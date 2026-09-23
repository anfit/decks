# Participant capability policy

Status: S17/S18 authorization slice; scoped-lock matrix extended 2026-09-23.

Table roles remain host, player and spectator, while `session_participants.capabilities` supplies explicit per-participant grants. An absent capability uses the role default: hosts may administer the table, players may perform ordinary card/deck/pile actions, and spectators are read-only. An explicit boolean grant or denial overrides the default for that participant. Global account administration does not grant table capabilities.

The host-only `set_participant_capabilities` action accepts a participant id and an allowlisted map of capability names to booleans. It validates the target belongs to the same active session, stores only boolean values, advances the session revision and emits sanitized metadata. Every action family maps to its required capability checks before service mutation; leave/reconnect/read operations remain available according to role and membership. Actions that mutate more than one capability domain require every relevant grant. In particular, a deck draw whose target is a pile requires both `deck.manage` and `pile.manage`; drawing to the table or own hand requires only `deck.manage`.

The active host cannot remove their own `participant.manage` control through this action; host administration must retain a recovery path while other participant grants may be explicitly enabled or denied.

An authorized host snapshot may include sanitized capability decisions for the active participants so the table surface can render host controls. Other participants receive only their own explicit decisions. The browser hides mutation affordances when the current effective capability is absent, while the service remains the final authorization boundary.

Acceptance requires unauthorized actions to fail atomically, explicit grants/denials to survive reconnect and snapshots, and no capability map or participant identity to leak through public activity events.

Composite operations must require a grant for each source and destination domain they change. The required pairs are:

| Actions | Required capabilities |
| --- | --- |
| `draw_top`, `draw_bottom`, `draw_n` to a pile | `deck.manage`, `pile.manage` |
| `return_top`, `return_bottom`, `return_to_source_decks`, `insert_cards` | `deck.manage`, `card.manage` |
| `split_deck` | `deck.manage`, `pile.manage` |
| `move_to_pile`, `collect_spread` | `card.manage`, `pile.manage` |
| `draw_pile_top`, `draw_pile_bottom` | `pile.manage`, `card.manage` |
| `merge_pile_top`, `merge_pile_bottom`, `merge_pile_shuffle` | `pile.manage`, `deck.manage` |
| `spread_pile` | `pile.manage`, `card.manage` |
| `lock_card`, `unlock_card` | `lock.manage`, `card.manage` |
| `lock_pile`, `unlock_pile` | `lock.manage`, `pile.manage` |
| `lock_deck`, `unlock_deck` | `lock.manage`, `deck.manage` |
| `lock_zone`, `unlock_zone` | `lock.manage`, `zone.manage` |
| `lock_table`, `unlock_table` | `lock.manage`, `session.manage` |

Single-domain operations such as moving or labeling a pile continue to use their resource-domain capability. A composite denial is checked before duplicate replay and before the action mutation path; no card, container, event, or session revision changes on denial. New operations must extend this matrix before implementation.
