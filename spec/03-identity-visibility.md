# Decks identity, authorization and visibility

Status: initial contract, 2026-09-21.

## Identity

Decks uses named local accounts adapted from the Scholion reference. The request bootstrap resolves a current enabled `app_user` from a server-side PHP session; it never trusts a stale role or an arbitrary actor ID from the browser. A session records the account security version observed at login. Password reset/change, disablement and sign-out-everywhere increment that version and invalidate affected sessions/tickets.

Account invitations and table join links are separate. An account invitation creates a named user. A table link only grants an authenticated eligible user an opportunity to join one session; it cannot log in, transfer a hand or bypass table capability checks.

## Policy layers

Global roles are `member` and `admin`. Global capabilities cover account invitations, user administration and ownership of reusable templates. Table roles are independently `host`, `player` and `spectator`, with table capabilities such as draw, shuffle, manipulate public cards, manipulate an own hand, create piles, peek, lock and reset.

Global administration never implies table membership or private-hand visibility. Every table action authorizes the current account, active membership, table capability, object ownership/control and visibility grants. Sensitive service methods re-check policy even when HTTP/WS adapters already performed a gate.

## Card visibility

Card identity, location and face state are separate. Public face-up cards expose an authorized front projection. Public face-down and private cards expose only the minimum interaction handle, back asset and spatial/container information permitted to the viewer. A hand owner sees its identities; other participants receive counts unless an explicit reveal occurs. A table card entering an `owner_private` zone is owned by the placing actor and exposes its definition only to that actor. The host does not see hands or another participant’s private table cards by default.

Projection code is allowlist-based and shared by snapshots, changes, action results, WebSocket notifications and history. It must not send hidden definition IDs, front URLs, private metadata, hidden order, peek results or raw historical secrets. Protected front assets are authorized per request and delivered through Nginx internal handoff; frontend CSS/DOM hiding is not a security boundary.

The browser may construct a same-origin protected front-image request from the session and card handles already present in an authorized visible-card projection; the snapshot itself must not contain an asset ID or image URL. The route is bound to the exact session and card and resolves the front asset only on the server. It rechecks the enabled principal, active membership, current card location/face/owner and front-asset relationship on every request. Access is limited to a current participant viewing a face-up public table/pile card, their own hand card, or their own private table card. Hidden/deck/removed/foreign-private cards and non-members return the same 404. Responses are private and `no-store`; the owner-only asset preview route remains separate. The frontend adds the image only for a projection it is already allowed to display, with an empty alt attribute and the safe card label as the accessible name.

For a non-front-facing card on the public table, the authorized projection may expose only a `has_back_art` boolean. The browser may request a back through a session/card-bound protected route; the server resolves the card-specific back first, then the immutable template-version default back, and rechecks active membership, current table location and current non-face-up state on every request. A public face-down card and a foreign-private table card may show this back representation, never its front. A card in a hand, deck, pile or removed state cannot use the route. Back asset IDs, storage keys and URLs are never included in snapshots or transient messages; a missing asset falls back to the generic CSS back. Back responses are private and `no-store`.

Hand order is private card state: the snapshot may expose it only in the current owner's own-hand projection and must omit it from all public or other-participant views and events.

## Session/table revocation

Disabled accounts, removed participants and expired/revoked table links fail closed for HTTP and WebSocket. Account role changes are observed on the next request. Revocation changes must redact private projections or force a full authorized snapshot; a late joiner never receives historical private state.

## Threat-focused acceptance

- A forged user/participant/card ID cannot increase authority.
- A global admin who is not a table member cannot read another participant's hand.
- A copied protected front URL fails after authorization is lost and is not cacheable publicly.
- A face-down card's front, definition, hidden order and metadata are absent from API/WS payloads, DOM attributes, accessibility labels and logs.
- Returning a previously revealed card to a hidden container rotates/changes the audience projection handle so later observers cannot track it through identity alone.
