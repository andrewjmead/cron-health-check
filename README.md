# Cron Health Check

A WordPress plugin that manually tests whether WP-Cron actually fires on your site, and lists overdue scheduled events. The shipped plugin lives in `src/` and is three files: `src/cron-health-check.php`, `src/cron-health-check.js`, `src/cron-health-check.css`.

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
bin/build-zip.sh   # writes dist/cron-health-check.zip
```
