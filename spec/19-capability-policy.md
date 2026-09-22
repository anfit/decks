# Participant capability policy

Status: S17/S18 authorization slice, 2026-09-22.

Table roles remain host, player and spectator, while `session_participants.capabilities` supplies explicit per-participant grants. An absent capability uses the role default: hosts may administer the table, players may perform ordinary card/deck/pile actions, and spectators are read-only. An explicit boolean grant or denial overrides the default for that participant. Global account administration does not grant table capabilities.

The host-only `set_participant_capabilities` action accepts a participant id and an allowlisted map of capability names to booleans. It validates the target belongs to the same active session, stores only boolean values, advances the session revision and emits sanitized metadata. Every action family maps to one capability check before service mutation; leave/reconnect/read operations remain available according to role and membership.

Acceptance requires unauthorized actions to fail atomically, explicit grants/denials to survive reconnect and snapshots, and no capability map or participant identity to leak through public activity events.
