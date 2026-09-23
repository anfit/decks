# Decks assets and deck templates

Status: initial contract, 2026-09-21.

## Uploads

Artwork is uploaded through an authenticated owner action, validated by decoded MIME and dimensions, bounded by a 10 MiB source-file and decoded-pixel limit, and stored outside public/release trees under a content-addressed key. The database stores owner, dimensions, MIME, size and hash. Invalid formats, active SVG/HTML and arbitrary URL imports are rejected. A later image-processing step may re-encode to normalized WebP when the host image library is available; gameplay never depends on client-supplied MIME or extension.

Protected fronts are authorized per request and served by Nginx through an internal handoff. Public back/UI assets may be immutable-cacheable. A direct storage path, guessed asset ID or stale browser URL cannot bypass current permission.

For card fronts, an active table participant can request artwork only through a protected session/card route; the server resolves the asset from the card definition and rechecks the current visibility boundary on every request. Snapshot/event/WebSocket payloads do not contain the front asset ID or URL. The browser renders the image only for a face-up public card, its owner's hand card, or its owner's private table card. A previously valid route stops serving bytes after the card becomes hidden, removed, private to another user, or inaccessible through membership revocation.

For face-down table cards, the authorized projection adds only a `has_back_art` boolean. A session/card-bound protected back route resolves `card_definitions.back_asset_id` before `deck_template_versions.default_back_asset_id`; it serves the back to an active participant only while the card remains on the table and is not face up. Foreign-private table cards may use the back representation, but private fronts remain protected. Back asset identifiers, storage keys and URLs are never projected. Responses use private `no-store` caching, and missing or invalid back assets use the generic CSS back. Hand/deck/pile/removed cards do not use this route.

## Templates and versions

An owner creates a named `deck_template`; each edit creates an immutable `deck_template_version` with ordered card definitions, quantities, front assets and optional backs. A session deck snapshots one version. Editing or deleting a template never mutates active card instances. Two instances from one definition have unique `session_cards` but may reference shared immutable artwork.

The authenticated home has an accessible template-management flow. The owner can create a template, add an immutable version to an existing template, order card definitions, set a safe display label and quantity, upload or select owned front/back artwork, and choose an optional version-default back. An upload is limited to the formats and size/dimension limits above; arbitrary URLs are not accepted. The browser uses owner-scoped asset IDs and never displays or submits storage keys. A failed/abandoned editor may leave an uploaded image in the caller's reusable asset library, but it cannot create an unowned file or make another account's image attachable.

`GET /api/assets` returns only the caller's asset id and safe image metadata (MIME, dimensions, byte size, creation time), never a storage key. Uploading byte-identical artwork remains content-deduplicated, while an explicit asset-ownership relation records every account that uploaded/owns that asset. Template version creation must verify that every front, card-back and default-back asset is owned by the template owner; knowing another account's asset UUID is insufficient. The version-default back is persisted when a version is created. The owner may preview an owned asset through an owner-authorized route; gameplay images continue to use the separate session/card-protected routes above.

The editor clearly distinguishes creating a template from adding a version. Each save creates exactly one new immutable version with the submitted ordering; it does not modify versions already used by sessions. The template list shows the owner their names, versions and definition counts and makes new templates available in session setup after save.

## Acceptance

- Oversized, corrupt, spoofed and unsupported uploads fail without orphaned database/file state.
- Content-addressed duplicates do not duplicate bytes while ownership/authorization remains explicit.
- A user can create a template and a later immutable version from the home UI using their own uploaded/selectable artwork; ordering, quantities, per-card backs and default-back selection round-trip correctly.
- `GET /api/assets` discloses no other user's images or storage keys, and another user's asset UUID is rejected for all template image positions.
- Identical uploads by two users reference deduplicated bytes while both ownership grants remain explicit; one user cannot use the other's template or owner-preview route.
- Template version changes do not affect an instantiated session.
- Unauthorized users cannot read a protected front; an owner can edit/use their own asset.
- A visible card renders its front artwork to an authorized viewer; face-down/foreign-private/removed cards omit the image and a stale image request returns not found after visibility changes.
- Face-down public and foreign-private table cards may render only their resolved card/default back; the route rejects a stale card moved to a hand, deck, pile or removed state, a face-up card, and non-members, without exposing an asset identifier.
- Image responses reveal no asset storage path, use private `no-store` caching, and never return bytes to a non-member or an account whose membership was removed.
