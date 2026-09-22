# Pile operation completion

Status: S18 implementation slice, 2026-09-22.

Piles are first-class public containers. Their card order is server-authoritative: top is the lowest `order_key`, bottom is the highest. `draw_pile_top` and `draw_pile_bottom` atomically move a requested count to the actor's private hand and expose only the resulting count to other participants. `split_pile` creates a new pile from a top slice; `merge_piles` moves all source cards into a target pile at top or bottom and removes the empty source. `collect_spread` moves an explicitly selected set of public table cards into a pile without silently changing unrelated containment.

Hosts and players may move or rotate a pile, set its label, and lock/unlock it. A pile lock blocks foreign card and spatial mutations until its owner unlocks it; every mutation accepts the expected pile version and advances it atomically. Hidden/private cards remain subject to the existing card-control and projection rules.

The table UI exposes pile labeling, splitting a top count into a new pile, merging a pile into another at top or bottom, and collecting an explicitly selected set of visible table cards into a pile. These controls are reachable by keyboard, show current container counts, include expected pile versions, and are hidden or disabled when the participant lacks the required capability, the pile is locked, or the operation has no valid target. Collect-spread reuses transient table selection; it never exposes card definitions or identities beyond the current authorized projection.

Each pile also appears as a face-down stack on the shared table at its persisted position, rotation and z-order, displaying only its safe label and card count. Participants with pile-management capability may drag it or use accessible fixed-step move, rotate, bring-to-front and send-to-back buttons. Layer changes update only the pile's z-index and respect the service bounds. Geometry actions carry the expected pile version and are disabled for locked piles; the rendering never reveals identities of cards inside the pile.

Acceptance requires action-registry coverage, transaction-scoped version checks, same-session validation, sanitized events, and focused contract tests for draw, split, merge, collect-spread, spatial updates and locks. Browser acceptance must exercise populated split, both merge positions, label changes, collect-spread, stale pile versions, move/rotate/front/back persistence, and a locked-pile view.
