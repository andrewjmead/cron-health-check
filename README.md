# Spawn – Cron Health Check

A WordPress plugin that manually tests whether WP-Cron actually fires on your site, and lists overdue scheduled events. The shipped plugin lives in `src/` and is three files: `src/spawn-cron-health-check.php`, `src/spawn-cron-health-check.js`, `src/spawn-cron-health-check.css`.

## Development setup

```bash
composer install
```

## Checks

```bash
composer lint         # PHPCS with WordPress Coding Standards
composer analyse      # PHPStan (phpstan-wordpress)
composer test:unit    # PHPUnit + Brain Monkey (no WordPress needed)
```

## Integration tests

Requires Docker and Node.js.

```bash
npm install
npm run env:start          # starts wp-env (PHP 7.4)
npm run test:integration   # runs the integration suite inside wp-env
```

## Build a distributable zip

```bash
bin/build-zip.sh   # writes dist/spawn-cron-health-check.zip
```

## Deploy to WordPress.org

Deploys the plugin to the wp.org SVN repository. Requires `svn`, `rsync`, and a clean git tree.

```bash
bin/deploy.sh release           # trunk + tag for the current version + assets
bin/deploy.sh assets            # update wp.org assets only (banner, icon, screenshots)
bin/deploy.sh release --dry-run # stage everything without committing
```

Credentials come from `--username`/`--password` or the `WPORG_USERNAME`/`WPORG_PASSWORD` environment variables (prompted if missing). See `bin/deploy.sh --help` for all flags.
