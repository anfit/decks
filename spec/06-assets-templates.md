# Decks assets and deck templates

Status: initial contract, 2026-09-21.

## Uploads

Artwork is uploaded through an authenticated owner action, validated by decoded MIME and dimensions, bounded by a 10 MiB source-file and decoded-pixel limit, and stored outside public/release trees under a content-addressed key. The database stores owner, dimensions, MIME, size and hash. Invalid formats, active SVG/HTML and arbitrary URL imports are rejected. A later image-processing step may re-encode to normalized WebP when the host image library is available; gameplay never depends on client-supplied MIME or extension.

Protected fronts are authorized per request and served by Nginx through an internal handoff. Public back/UI assets may be immutable-cacheable. A direct storage path, guessed asset ID or stale browser URL cannot bypass current permission.

For card fronts, an active table participant can request artwork only through a protected session/card route; the server resolves the asset from the card definition and rechecks the current visibility boundary on every request. Snapshot/event/WebSocket payloads do not contain the front asset ID or URL. The browser renders the image only for a face-up public card, its owner's hand card, or its owner's private table card. A previously valid route stops serving bytes after the card becomes hidden, removed, private to another user, or inaccessible through membership revocation.

## Templates and versions

An owner creates a named `deck_template`; each edit creates an immutable `deck_template_version` with ordered card definitions, quantities, front assets and optional backs. A session deck snapshots one version. Editing or deleting a template never mutates active card instances. Two instances from one definition have unique `session_cards` but may reference shared immutable artwork.

## Acceptance

- Oversized, corrupt, spoofed and unsupported uploads fail without orphaned database/file state.
- Content-addressed duplicates do not duplicate bytes while ownership/authorization remains explicit.
- Template version changes do not affect an instantiated session.
- Unauthorized users cannot read a protected front; an owner can edit/use their own asset.
- A visible card renders its front artwork to an authorized viewer; face-down/foreign-private/removed cards omit the image and a stale image request returns not found after visibility changes.
- Image responses reveal no asset storage path, use private `no-store` caching, and never return bytes to a non-member or an account whose membership was removed.
