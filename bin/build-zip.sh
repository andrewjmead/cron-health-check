#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
mkdir -p dist
rm -rf dist/spawn-cron-health-check dist/spawn-cron-health-check.zip
mkdir dist/spawn-cron-health-check
cp src/spawn-cron-health-check.php src/spawn-cron-health-check.js src/spawn-cron-health-check.css src/readme.txt dist/spawn-cron-health-check/
( cd dist && zip -r spawn-cron-health-check.zip spawn-cron-health-check )
rm -rf dist/spawn-cron-health-check
echo "Wrote dist/spawn-cron-health-check.zip"
