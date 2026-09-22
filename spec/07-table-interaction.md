# Decks table interaction contract

Status: initial contract, 2026-09-21.

The first client uses ordinary DOM elements inside a transformable table. Pointer Events drive local drag previews; pointer release submits one durable action. Every essential drag operation has an action-menu alternative. Pan/zoom, selection, rotation, z-order, keyboard focus, touch-sized controls and reduced motion are presentation concerns; final position and containment are server state.

Create-table responses display an opaque join token for authenticated players. The Join table control submits that token unchanged to the token-only join endpoint; it never assumes the token is encoded JSON or decodes its segments in the browser.

Table cards support public face-up, public face-down and private hand projection. A hand exposes identities only to its owner. Public overlap never silently creates a pile; stacking is an explicit action. Visible cards use safe display names where authorized, while hidden projections use generic labels. Cards are keyboard-focusable and provide explicit action alternatives to drag or double-click. Pending actions and rejected conflicts remain visually distinct from accepted state.

The initial operation family is shuffle, draw top/bottom/N, move table card, rotate, flip/turn face, move to own hand, play from own hand face-up/down and return selected cards to deck top/bottom. The current pile slice adds create, move a validated selection into a pile, shuffle a pile and merge a pile into a deck top/bottom. S18 adds pile draw top/bottom, split/merge pile-to-pile, collect-spread, pile spatial/label/lock operations, group table-card rotation/reveal/z-order, and their version checks. All selection members are validated before mutation; hidden hands and removed cards remain inaccessible. Group selection state stays in the browser while final results are durable server state.
