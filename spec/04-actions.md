# Decks sessions and action contract

Status: initial lifecycle/action contract, 2026-09-21.

## Session membership

An authenticated Decks account creates a session and becomes its host participant. A table join link is a scoped selector/secret credential stored as a hash on the session; it does not authenticate a visitor. A visitor must authenticate, then the server revalidates the link and creates or restores the account's unique membership.

Table roles are host, player and spectator. A player has one default private hand. A spectator has public projection access and no mutation capability. A removed participant retains cards for host recovery but cannot read or mutate the session. Leaving/disconnect does not remove membership or cards. Starting freezes configuration-level changes; ending makes the session read-only.

The authenticated join surface accepts the opaque `selector.secret` table token directly at `POST /api/sessions/join` with `{ "token": "...", "role": "player|spectator" }`. The server resolves the selector, verifies the secret hash and expiry, and returns the authorized membership including `session_id`; clients must not decode or reinterpret token segments. The session-scoped compatibility route may additionally require an expected session id, but the shared token itself is the complete join credential.

Deal accepts `mode: round_robin` (one card per recipient per round) or `mode: per_participant` (the requested count is completed for the first recipient before moving to the next). Both modes select and assign the full card set atomically and return per-participant counts.

Host `restore_card` accepts an explicit source-deck position (`top`, `bottom` or `shuffle`), and `collect_all` accepts `original`, `shuffle` or `preserve` mode. The server remains authoritative for ordering and randomization.

The host may transfer host role, remove/restore participants, freeze/unfreeze configuration and end/reset where capability permits. A global account administrator has no table access unless also a participant. Host disconnect does not elect a replacement automatically.

## Durable action envelope

Every state mutation uses `POST /api/sessions/{sessionId}/actions`:

```json
{
  "action_id": "uuid",
  "expected_session_revision": 12,
  "type": "draw_top",
  "payload": { "deck_id": "uuid", "target": "hand" }
}
```

The server authenticates the current account, resolves active membership, locks the session row, checks the idempotency record and canonical request hash, validates capabilities/object versions/locks/visibility/card invariants, mutates all required rows in one transaction, writes a sanitized event, advances `session.revision`, sends a post-commit notification and returns an authorized result.

Duplicate `(session, actor, action_id)` with the same request hash returns the original result/revision without another mutation. Reusing an ID with another request fails. A stale dependency or strict expected session revision returns a conflict and current authorized revision; it never silently replays an operation.

## Initial action registry

S05/S07 implement `create_session`, `join_session`, `leave_session`, `start_session`, `end_session`, `move_card`, `rotate_card`, `flip_card`, `turn_face_up`, `turn_face_down`, `draw_top`, `draw_bottom`, `draw_n`, `return_top`, `return_bottom`, `shuffle_deck`, `move_to_hand`, `play_from_hand`, `create_pile`, `move_pile`, and `reset_session` as the first families. The current registry also provides atomic `deal`, `cut_deck`, `insert_cards`, `split_deck`, `reverse_pile`, `flip_pile`, `spread_pile`, `collect_all`, `shuffle_pile`, pile-to-deck merge, remove/restore, locking, group movement, hand reorder/transfer, peek, table configuration, participant recovery, zone create/delete and actor-scoped `undo_action` for safe spatial moves/rotations. Zone effects are validated and applied server-side for table placement. Hidden, random and reveal actions remain non-undoable.

Actions that reveal hidden state or change randomness have no unilateral undo. Durable event history stores action metadata only; card identities, definitions, arrays, secrets and peek results are excluded from public event payloads. Spatial/public actions may later support dependency-checked inverse actions.

## Error envelope

HTTP errors use a stable JSON shape with `error`, `message`, `session_revision` where known, and an optional authorized `current` projection. Error messages do not include hidden card identities, private order, token secrets, SQL or filesystem paths.
