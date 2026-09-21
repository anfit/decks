#!/usr/bin/env bash
set -euo pipefail

: "${DECKS_ENV:?DECKS_ENV is required}"
: "${DECKS_DB_NAME:?DECKS_DB_NAME is required}"
: "${DECKS_DB_USER:?DECKS_DB_USER is required}"
: "${DECKS_DB_PASSWORD:?DECKS_DB_PASSWORD is required}"
: "${DECKS_APP_KEY:?DECKS_APP_KEY is required}"

exec php-fpm8.2 --nodaemonize --fpm-config "$PWD/.deployer/php-fpm.conf"
