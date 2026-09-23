#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
mkdir -p dist
rm -f dist/cron-health-check.zip
zip -j dist/cron-health-check.zip \
	cron-health-check.php \
	cron-health-check.js \
	cron-health-check.css \
	readme.txt
echo "Wrote dist/cron-health-check.zip"
