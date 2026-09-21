#!/usr/bin/env bash
set -euo pipefail

: "${DECKS_DB_NAME:?DECKS_DB_NAME is required}"
: "${DECKS_DB_USER:?DECKS_DB_USER is required}"
: "${DECKS_DB_PASSWORD:?DECKS_DB_PASSWORD is required}"

exec php scripts/retention.php "$@"
