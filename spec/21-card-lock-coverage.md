# Card lock coverage for bulk operations

Status: S18 integrity hardening, 2026-09-22.

A card lock prevents another participant from changing that card's location, face, ownership, visibility or ordering. The service checks locks for every affected card before any batch mutation, including draw/deal, deck shuffle/cut/insert/split, pile draw/shuffle, hand reorder/transfer, play, and group table actions. A selection containing one inaccessible or locked card fails atomically.

Pile locks continue to guard the pile container and its contents. Host recovery operations that explicitly restore, collect or reset cards may clear locks as part of their documented recovery behavior. Public action events omit card identifiers and hidden results.

Acceptance requires source/contract coverage for each affected operation family and runtime verification that a foreign lock leaves all affected card locations, ordering, versions, and session revision unchanged.
