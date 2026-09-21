#!/usr/bin/env bash
set -euo pipefail

: "${DECKS_SMTP_HOST:?DECKS_SMTP_HOST is required}"
: "${DECKS_MAIL_FROM:?DECKS_MAIL_FROM is required}"

exec php scripts/send-mail-outbox.php --loop
