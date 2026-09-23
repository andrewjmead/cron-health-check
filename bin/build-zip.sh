#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
mkdir -p dist
rm -rf dist/cron-health-check dist/cron-health-check.zip
mkdir dist/cron-health-check
cp src/cron-health-check.php src/cron-health-check.js src/cron-health-check.css src/readme.txt dist/cron-health-check/
( cd dist && zip -r cron-health-check.zip cron-health-check )
rm -rf dist/cron-health-check
echo "Wrote dist/cron-health-check.zip"
