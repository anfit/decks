# Decks state model

Status: initial relational contract, 2026-09-21.

## Identity and ownership

`app_user` is the Decks account identity. Account security fields and user-owned template ownership are separate from table membership. A session participant references one account and has a table role. The same account may participate in many sessions, at most once per session.

## Immutable definitions

`deck_templates` are stable named owner records. `deck_template_versions` snapshot the card definitions and default back asset used by an instantiation. A session deck references one immutable template version. Editing a template creates a new version and never mutates cards already instantiated in a session. Asset rows are immutable content-addressed or versioned references; garbage collection requires no live template/session references.

## Session and containers

`sessions` owns a monotonically increasing `revision`. `session_decks`, `session_piles` and participant `hands` are typed containers. Mats and zones are session presentation/configuration records; zones may describe generic drop behavior but do not inspect card meaning.

`session_cards` is the single source of current card location. A check constraint requires exactly one of: a deck, pile, hand, table or removed state. Composite foreign keys include `session_id` so a card cannot point into another session. Decks, piles and hands use `order_key`; table/removed cards use spatial fields and no container order. Origin deck and card definition are immutable after instantiation.

## Reset and restoration

The session stores the selected template version, initial mat/zones/permissions and initial ordering policy. Reset is a normal serialized action that reconstructs these values and increments revision; a shuffled initial policy generates fresh server randomness. Collect-all retains card instances, moves active and removed cards to their immutable source decks, and empties hands/piles.

## Concurrency and history

`session_events` records sanitized accepted actions by revision. It is not an event-sourcing source of truth. `processed_actions` stores an actor/session/action ID, canonical request hash and result metadata so retries cannot repeat a mutation. Cards/containers retain versions for dependency checks even when unrelated session revisions advance.

Account security audit and email outbox rows are separate from table events. Outbox rows are inserted with the account mutation that requires mail and are claimed by the mail worker.

## Database invariants

- Every referenced object belongs to the same session.
- A card has exactly one logical location and appears once in its container.
- A container's order key is unique within its session/container.
- A participant has at most one default hand in a session.
- A template version and its card definitions are immutable by application policy.
- Session revision and object versions advance only inside accepted transactions.
- Disabled users remain for audit attribution but cannot authenticate or act.
