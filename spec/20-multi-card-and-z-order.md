# Multi-card actions and table z-order

Status: S18 implementation slice, 2026-09-22.

Players may select up to 100 visible table cards and apply group rotation, face-up/face-down changes, or front/back ordering as one revisioned action. The server validates every selected card, active membership, card version, location and lock before mutating any card; a bad or stale selection rolls back the complete action. Group rotation changes each selected card's angle by the requested bounded delta without changing its position.

Front/back actions preserve the relative order within the selected group and within the unselected table cards. The server rewrites durable z-index values in one transaction, increments versions for affected cards, and never accepts client-chosen final z-index values for these operations. Face changes apply only to public table cards. Existing privacy projection rules continue to govern the identities shown afterward.

The browser offers an explicit selection mode with a keyboard/touch accessible toggle on each card, selection count, rotate, reveal/turn, bring-to-front and send-to-back controls. Selection is transient client state; final orientation, face and z-order remain PostgreSQL state. Public action events omit selected card identifiers.

Acceptance requires contract and runtime checks for atomic stale-selection rejection, same-session scoping, lock/ownership validation, stable relative ordering, face-up projection privacy, revision advancement, and no card identity leakage in public events.
