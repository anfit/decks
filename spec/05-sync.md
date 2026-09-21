# Decks synchronization and projection contract

Status: initial contract, 2026-09-21.

## HTTP state

- `GET /api/sessions/{id}/state` returns the requesting account's current authorized projection and `revision`.
- `GET /api/sessions/{id}/changes?after={revision}` returns bounded sanitized event invalidations and a consistent `to_revision`. It may return `resync_required` when history is too old or visibility changed.
- `POST /api/sessions/{id}/actions` is the only durable mutation endpoint. An accepted action increments revision once and emits a small post-commit notification later in S08.

Projection code is allowlist-based and viewer-specific. Public table cards include spatial/face state and authorized front references only when face-up. Other hands expose counts only. Deck order, private definitions, hidden metadata, peek results and protected asset paths are never included for unauthorized viewers. Changes do not replay raw private historical payloads.

## Realtime

The Node service will authenticate a short-lived table-scoped ticket and listen for PostgreSQL `decks_session_changed` payloads containing only `session_id` and `revision`. A notification is a hint; the browser catches up through HTTP and periodically reconciles revisions. Dropped/out-of-order/duplicate notifications are safe. Node failure cannot lose durable state.

Transient cursor/drag messages are bounded, rate-limited and contain only public interaction handles. They are not persisted and are discarded on reconnect. PHP card/object versions and durable locks remain the correctness boundary.

## Conflict and recovery behavior

The browser keeps pending local presentation separate from accepted state. A stale expected revision/object version returns a conflict plus current authorized state or resync instruction. A reconnect obtains missed changes or a full snapshot, discards transient drag state and resumes with the current permission/security version.
