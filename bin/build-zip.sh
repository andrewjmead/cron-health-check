#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
mkdir -p dist
rm -f dist/cron-health-check.zip
zip -j dist/cron-health-check.zip \
	src/cron-health-check.php \
	src/cron-health-check.js \
	src/cron-health-check.css \
	src/readme.txt
echo "Wrote dist/cron-health-check.zip"
