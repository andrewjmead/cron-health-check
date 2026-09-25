#!/usr/bin/env bash
#
# Deploy Spawn – Cron Health Check to the WordPress.org plugin directory (SVN).
#
#   bin/deploy.sh release            deploy trunk + tag + assets; requires git tag <version> on the git remote
#   bin/deploy.sh assets             update the wp.org assets/ directory only (banner, icon, screenshots)
#
# Options:
#   --dry-run              do everything except `svn commit` (and the GitHub release)
#   --svn-url URL          override the SVN repository URL
#   --git-remote NAME      git remote that must have the release tag (default: origin)
#   --no-github-release    skip the GitHub release after a successful SVN commit
#   --username USER        wp.org username (or set WPORG_USERNAME)
#   --password PASS        wp.org SVN password (or set WPORG_PASSWORD; prompted if missing)
#
set -euo pipefail

SLUG="spawn-cron-health-check"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
RUNTIME_FILES=(
	"src/${SLUG}.php"
	"src/${SLUG}.js"
	"src/${SLUG}.css"
	"src/readme.txt"
)

cd "$(dirname "$0")/.."
ROOT="$(pwd)"

# ---------------------------------------------------------------- output ----

if [[ -t 1 ]]; then
	C_RED=$'\033[31m' C_GRN=$'\033[32m' C_YEL=$'\033[33m' C_OFF=$'\033[0m'
else
	C_RED='' C_GRN='' C_YEL='' C_OFF=''
fi
step() { printf '\n%s==> %s%s\n' "$C_GRN" "$*" "$C_OFF"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '%s    warning: %s%s\n' "$C_YEL" "$*" "$C_OFF"; }
die()  { printf '\n%serror: %s%s\n' "$C_RED" "$*" "$C_OFF" >&2; exit 1; }
usage() { sed -n '2,/^set -euo/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }

# ------------------------------------------------------------------ args ----

MODE=""
DRY_RUN=0
GIT_REMOTE="origin"
GITHUB_RELEASE=1
USERNAME="${WPORG_USERNAME:-}"
PASSWORD="${WPORG_PASSWORD:-}"

while [[ $# -gt 0 ]]; do
	case "$1" in
		release|assets) MODE="$1" ;;
		--dry-run)      DRY_RUN=1 ;;
		--git-remote)   GIT_REMOTE="$2"; shift ;;
		--no-github-release) GITHUB_RELEASE=0 ;;
		--svn-url)      SVN_URL="$2"; shift ;;
		--username)     USERNAME="$2"; shift ;;
		--password)     PASSWORD="$2"; shift ;;
		-h|--help)      usage 0 ;;
		*)              die "unknown argument: $1 (try --help)" ;;
	esac
	shift
done
[[ -n "$MODE" ]] || usage 1

# ------------------------------------------------------------ preflight ----

step "Preflight"

command -v svn >/dev/null || die "svn is not installed (brew install svn / apt install subversion)"
command -v git >/dev/null || die "git is not installed"
command -v rsync >/dev/null || die "rsync is not installed"

for f in "${RUNTIME_FILES[@]}"; do
	[[ -f "$f" ]] || die "missing runtime file: $f"
done
[[ -d assets ]] || die "missing assets/ directory"

if [[ -n "$(git status --porcelain)" ]]; then
	git status --short
	die "git working tree is not clean; commit or stash first"
fi
info "git tree clean at $(git rev-parse --short HEAD) ($(git rev-parse --abbrev-ref HEAD))"

HEADER_VERSION="$(sed -n 's/^ \* Version:[[:space:]]*\([0-9][0-9A-Za-z.+-]*\).*/\1/p' "src/${SLUG}.php" | head -1)"
CONST_VERSION="$(sed -n "s/^define( 'SPCR_VERSION', '\([^']*\)' ).*/\1/p" "src/${SLUG}.php" | head -1)"
STABLE_TAG="$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' src/readme.txt | head -1)"

[[ -n "$HEADER_VERSION" ]] || die "could not read Version from the plugin header"
[[ -n "$CONST_VERSION" ]]  || die "could not read SPCR_VERSION"
[[ -n "$STABLE_TAG" ]]     || die "could not read Stable tag from readme.txt"
# shellcheck disable=SC2055 # intentional: die when either check differs
if [[ "$HEADER_VERSION" != "$CONST_VERSION" || "$HEADER_VERSION" != "$STABLE_TAG" ]]; then
	die "version mismatch: header=$HEADER_VERSION SPCR_VERSION=$CONST_VERSION 'Stable tag'=$STABLE_TAG"
fi
VERSION="$HEADER_VERSION"
info "plugin version $VERSION (header, SPCR_VERSION and Stable tag agree)"

if [[ "$MODE" == "release" ]]; then
	if ! git rev-parse -q --verify "refs/tags/${VERSION}" >/dev/null; then
		die "git tag ${VERSION} not found — create and push it first: git tag ${VERSION} && git push ${GIT_REMOTE} ${VERSION}"
	fi
	LOCAL_TAG_COMMIT="$(git rev-parse "${VERSION}^{commit}")"
	REMOTE_TAG="$(git ls-remote --tags "$GIT_REMOTE" "refs/tags/${VERSION}")"
	[[ -n "$REMOTE_TAG" ]] || die "git tag ${VERSION} not found on ${GIT_REMOTE} — push it first: git push ${GIT_REMOTE} ${VERSION}"
	# Annotated tags list the tag object plus a peeled ^{} line; lightweight tags list only the commit.
	REMOTE_TAG_COMMIT="$(printf '%s\n' "$REMOTE_TAG" | awk -v t="refs/tags/${VERSION}^{}" '$2 == t {print $1}')"
	[[ -n "$REMOTE_TAG_COMMIT" ]] || REMOTE_TAG_COMMIT="$(printf '%s\n' "$REMOTE_TAG" | awk -v t="refs/tags/${VERSION}" '$2 == t {print $1}')"
	[[ "$LOCAL_TAG_COMMIT" == "$REMOTE_TAG_COMMIT" ]] \
		|| die "git tag ${VERSION} points at different commits locally and on ${GIT_REMOTE} (${LOCAL_TAG_COMMIT} vs ${REMOTE_TAG_COMMIT})"

	TAG_PHP="$(git show "${VERSION}:src/${SLUG}.php")"
	TAG_README="$(git show "${VERSION}:src/readme.txt")"
	TAG_HEADER_VERSION="$(printf '%s\n' "$TAG_PHP" | sed -n 's/^ \* Version:[[:space:]]*\([0-9][0-9A-Za-z.+-]*\).*/\1/p' | head -1)"
	TAG_CONST_VERSION="$(printf '%s\n' "$TAG_PHP" | sed -n "s/^define( 'SPCR_VERSION', '\([^']*\)' ).*/\1/p" | head -1)"
	TAG_STABLE_TAG="$(printf '%s\n' "$TAG_README" | sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' | head -1)"
	if [[ "$TAG_HEADER_VERSION" != "$VERSION" || "$TAG_CONST_VERSION" != "$VERSION" || "$TAG_STABLE_TAG" != "$VERSION" ]]; then
		die "tag ${VERSION} version mismatch: header=${TAG_HEADER_VERSION:-?} SPCR_VERSION=${TAG_CONST_VERSION:-?} 'Stable tag'=${TAG_STABLE_TAG:-?}"
	fi
	printf '%s\n' "$TAG_README" | grep -q "^= ${VERSION} =" \
		|| die "tag ${VERSION} readme.txt has no changelog entry '= ${VERSION} ='"
	info "git tag ${VERSION} verified on ${GIT_REMOTE}"

	if [[ "$(git rev-parse HEAD)" != "$LOCAL_TAG_COMMIT" ]]; then
		warn "HEAD ($(git rev-parse --short HEAD)) does not match tag ${VERSION} — deploying the tagged files anyway"
	fi
fi

step "Checking SVN repository $SVN_URL"
svn info "$SVN_URL" >/dev/null 2>&1 \
	|| die "cannot reach $SVN_URL (slug not approved yet, no network, or wrong URL)"

if [[ "$MODE" == "release" ]]; then
	if svn info "${SVN_URL}/tags/${VERSION}" >/dev/null 2>&1; then
		die "version $VERSION is already deployed (tags/${VERSION} exists on SVN); bump the version first"
	fi
	info "tags/${VERSION} does not exist yet"
fi

# -------------------------------------------------------------- checkout ----

WORK="$(mktemp -d "${TMPDIR:-/tmp}/${SLUG}-deploy.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

step "Checking out SVN into $WORK"
# Sparse checkout: pull trunk + assets fully, tags only as empty dirs.
svn checkout -q --depth immediates "$SVN_URL" "$WORK/svn"
svn update -q --set-depth infinity "$WORK/svn/assets" "$WORK/svn/trunk"
svn update -q --set-depth immediates "$WORK/svn/tags"
SVN="$WORK/svn"

# ---------------------------------------------------------------- assets ----

step "Syncing assets/"
mkdir -p "$SVN/assets"
rsync -a --delete --exclude='.svn' "$ROOT/assets/" "$SVN/assets/"

# ----------------------------------------------------------------- trunk ----

if [[ "$MODE" == "release" ]]; then
	step "Building trunk/"
	BUILD="$WORK/build"
	mkdir -p "$BUILD"
	git archive "$VERSION" -- "${RUNTIME_FILES[@]}" | tar -x -C "$BUILD" --strip-components=1
	mkdir -p "$SVN/trunk"
	rsync -a --delete --exclude='.svn' "$BUILD/" "$SVN/trunk/"
	# shellcheck disable=SC2012 # runtime filenames are fixed and alphanumeric
	info "$(ls "$SVN/trunk" | tr '\n' ' ')"
fi

# ------------------------------------------------------- svn add/remove ----

step "Staging SVN changes"
cd "$SVN"
svn status | { grep '^?' || true; } | awk '{print $2}' | while read -r p; do svn add -q --parents "$p"; done
svn status | { grep '^!' || true; } | awk '{print $2}' | while read -r p; do svn rm -q "$p"; done

# Set MIME types so wp.org serves images/SVG correctly.
find assets -type f \( -name '*.png' -o -name '*.jpg' -o -name '*.jpeg' -o -name '*.gif' -o -name '*.svg' \) | while read -r p; do
	case "$p" in
		*.png)  m=image/png ;;
		*.jpg|*.jpeg) m=image/jpeg ;;
		*.gif)  m=image/gif ;;
		*.svg)  m=image/svg+xml ;;
	esac
	svn propset -q svn:mime-type "$m" "$p"
done

if [[ "$MODE" == "release" ]]; then
	svn cp -q trunk "tags/${VERSION}"
fi

if [[ -z "$(svn status)" ]]; then
	warn "nothing to commit — SVN already matches the working tree"
	exit 0
fi
echo
svn status
echo

if [[ "$MODE" == "release" ]]; then
	MESSAGE="Release ${VERSION}"
else
	MESSAGE="Update assets"
fi

# ---------------------------------------------------------------- commit ----

if [[ $DRY_RUN -eq 1 ]]; then
	step "Dry run — not committing"
	info "would run: svn commit -m \"$MESSAGE\""
	[[ "$MODE" == "release" && $GITHUB_RELEASE -eq 1 ]] && info "would run: gh release create ${VERSION} <zip> --title ${VERSION} --notes-from-tag"
	exit 0
fi

[[ -n "$USERNAME" ]] || read -r -p "wp.org username: " USERNAME
if [[ -z "$PASSWORD" ]]; then
	read -r -s -p "wp.org SVN password for $USERNAME: " PASSWORD
	echo
fi
[[ -n "$USERNAME" && -n "$PASSWORD" ]] || die "username and password are required"

step "Committing to SVN"
svn commit -q --non-interactive --no-auth-cache \
	--username "$USERNAME" --password "$PASSWORD" -m "$MESSAGE" \
	|| die "svn commit failed"
info "committed: $MESSAGE"

if [[ "$MODE" == "release" && $GITHUB_RELEASE -eq 1 ]]; then
	step "Creating GitHub release ${VERSION}"
	mkdir -p "$WORK/zip/${SLUG}"
	cp "$BUILD"/* "$WORK/zip/${SLUG}/"
	ZIP="$WORK/${SLUG}.zip"
	( cd "$WORK/zip" && zip -qr "$ZIP" "$SLUG" )
	cd "$ROOT"
	if command -v gh >/dev/null && gh auth status >/dev/null 2>&1; then
		if gh release create "$VERSION" "$ZIP" --title "$VERSION" --notes-from-tag 2>/dev/null \
			|| gh release create "$VERSION" "$ZIP" --title "$VERSION" --notes "Release ${VERSION}"; then
			info "created GitHub release ${VERSION}"
		else
			warn "gh release create failed — run it manually: bin/build-zip.sh && gh release create ${VERSION} dist/${SLUG}.zip --title ${VERSION}"
		fi
	else
		warn "gh is not installed or not authenticated — skipping GitHub release"
		info "manual: bin/build-zip.sh && gh release create ${VERSION} dist/${SLUG}.zip --title ${VERSION}"
	fi
fi

step "Done"
if [[ "$MODE" == "release" ]]; then
	info "https://wordpress.org/plugins/${SLUG}/ should show ${VERSION} within a few minutes"
else
	info "assets updated; wp.org may cache images for a while"
fi
