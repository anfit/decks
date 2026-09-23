# Host interaction freeze

Status: business requirements §5 and §20 interaction control.

`interaction_frozen` is a durable session property, independent from the table's object-lock scopes and the lobby's configuration freeze. It prevents all participants, including the host, from performing card, deck, hand, pile, zone, or spatial gameplay mutations until the host unfreezes the table. It does not change visibility or expose participant identity.

Only the active host with effective `session.manage` may run `freeze_table` or `unfreeze_table`. Both are serialized actions requiring the exact expected session revision; repeated requests that already match the state are idempotent and do not advance the revision. The authorized state projection exposes only `interaction_frozen: boolean`; public history records an allowlisted generic action type, never an actor id or private payload.

While frozen, all gameplay actions are rejected atomically before mutation, event insertion, idempotency completion, or revision advancement. Host session-administration and recovery actions remain available: unfreeze, participant administration/capability changes, whole-table unlock, reset, and end. Read-only requests, realtime reconnect, and leaving the session remain available. Joining remains governed by the ordinary session-token and participant-limit rules; new members receive the frozen flag and cannot mutate gameplay until the host unfreezes.

Reset returns the session to its configured lobby state and clears the freeze. Ending leaves the ended session read-only under the existing lifecycle rules. Starting a session is only a configuration freeze and does not set `interaction_frozen`.

The host controls show a clear frozen/unfrozen state and a single Freeze or Unfreeze action. All participants see a non-sensitive read-only notice while frozen. Disabled controls are presentation only; service validation is authoritative. The whole-table administrative lock remains separate: its holder may work inside the lock and authorized managers may unlock it, while a frozen session blocks gameplay actions by everyone.

Acceptance requires host-only authorization, exact-revision and idempotency behavior, consistent frozen snapshots across participants/reconnects, denial of every gameplay action with no partial effects, permitted host recovery/admin operations, continued leave/read paths, and reset/end behavior. Tests must prove that a denial leaves state, event history, idempotency results, and revision unchanged.
