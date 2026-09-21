#!/usr/bin/env bash
set -euo pipefail

: "${DECKS_ENV:?DECKS_ENV is required}"
: "${DECKS_DB_NAME:?DECKS_DB_NAME is required}"
: "${DECKS_DB_USER:?DECKS_DB_USER is required}"
: "${DECKS_DB_PASSWORD:?DECKS_DB_PASSWORD is required}"
: "${DECKS_APP_KEY:?DECKS_APP_KEY is required}"

php_fpm_bin="${DECKS_PHP_FPM_BIN:-}"
if [[ -z "$php_fpm_bin" ]]; then
  for candidate in php-fpm8.5 php-fpm8.4 php-fpm8.3 php-fpm8.2 php-fpm; do
    if command -v "$candidate" >/dev/null 2>&1; then php_fpm_bin="$candidate"; break; fi
  done
fi
[[ -n "$php_fpm_bin" ]] || { echo 'No PHP-FPM binary is installed.' >&2; exit 1; }
exec "$php_fpm_bin" --nodaemonize --fpm-config "$PWD/.deployer/php-fpm.conf"
