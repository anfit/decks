# Resource lock scopes

Status: business requirement §20 coverage, 2026-09-23.

Locks prevent manipulation and do not alter visibility. The supported administrative scopes are an individual card, pile, zone, deck, and the whole table. A lock records the user who created it. The lock holder may continue to manipulate objects inside their own locked scope; other participants must be denied atomically. Only an active participant with effective `lock.manage` and the capability for the target scope may add or remove that scope's lock. Authorized lock managers may release another participant's lock. Snapshots expose only a boolean locked state, never the lock holder.

Deck locks apply to operations on the deck container and the cards currently in it, including draw/deal, shuffle, cut, insert, split, return, pile-to-deck merge, collect, and reset restoration. A card that has left the deck is no longer covered by that deck lock; returning it to a locked source deck is denied. Every deck-lock action supplies the current expected deck version.

Zone locks apply to the zone rectangle and all cards or piles whose center point is within it. Cards or piles cannot be manipulated while inside another participant's locked zone, and a move cannot enter or leave that zone. For a card placed or moved on the table, both its existing point (when on the table) and requested destination point are checked. For a pile, its position is the zone point. Overlapping locked zones are all enforced. A zone lock also prevents another participant from editing or deleting that zone. Zone lock actions require the current expected session revision.

A whole-table lock prevents every other participant's durable table mutation. Leaving a session and non-mutating reads/reconnect remain available. The lock holder may continue to work. Only an authorized `unlock_table` action by the lock holder or an authorized lock manager, or a host-authorized session reset, clears it. Whole-table lock actions require the current expected session revision.

Administrative locks survive reconnect and role changes, and persist until explicitly removed or session reset. A reset is the recovery operation: it restores configured initial state and clears card, pile, deck, zone, and whole-table locks. A lock or unlock action advances the normal session revision, records only its allowlisted action type in public history, and never includes hidden card identities or lock-owner identity in an unauthorized projection.

Acceptance requires a foreign lock to reject an affected single or composite operation before any card, container, event, or session revision changes; the holder may act; authorized unlock and reset recover cleanly; stale lock requests fail; and snapshots reveal only lock state. Runtime validation must cover overlapping zones, cards/piles entering and leaving zones, and deck actions that both consume and return cards.
