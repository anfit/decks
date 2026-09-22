# Participant capability policy

Status: S17/S18 authorization slice, 2026-09-22.

Table roles remain host, player and spectator, while `session_participants.capabilities` supplies explicit per-participant grants. An absent capability uses the role default: hosts may administer the table, players may perform ordinary card/deck/pile actions, and spectators are read-only. An explicit boolean grant or denial overrides the default for that participant. Global account administration does not grant table capabilities.

The host-only `set_participant_capabilities` action accepts a participant id and an allowlisted map of capability names to booleans. It validates the target belongs to the same active session, stores only boolean values, advances the session revision and emits sanitized metadata. Every action family maps to its required capability checks before service mutation; leave/reconnect/read operations remain available according to role and membership. Actions that mutate more than one capability domain require every relevant grant. In particular, a deck draw whose target is a pile requires both `deck.manage` and `pile.manage`; drawing to the table or own hand requires only `deck.manage`.

The active host cannot remove their own `participant.manage` control through this action; host administration must retain a recovery path while other participant grants may be explicitly enabled or denied.

An authorized host snapshot may include sanitized capability decisions for the active participants so the table surface can render host controls. Other participants receive only their own explicit decisions. The browser hides mutation affordances when the current effective capability is absent, while the service remains the final authorization boundary.

Acceptance requires unauthorized actions to fail atomically, explicit grants/denials to survive reconnect and snapshots, and no capability map or participant identity to leak through public activity events.
