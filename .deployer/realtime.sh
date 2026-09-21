#!/usr/bin/env bash
set -euo pipefail

: "${DECKS_REALTIME_BIND:?DECKS_REALTIME_BIND is required}"
: "${DECKS_REALTIME_SIGNING_KEY:?DECKS_REALTIME_SIGNING_KEY is required}"

exec node realtime/server.mjs
