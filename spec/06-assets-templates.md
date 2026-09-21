# Decks assets and deck templates

Status: initial contract, 2026-09-21.

## Uploads

Artwork is uploaded through an authenticated owner action, validated by decoded MIME and dimensions, bounded by a 10 MiB source-file and decoded-pixel limit, and stored outside public/release trees under a content-addressed key. The database stores owner, dimensions, MIME, size and hash. Invalid formats, active SVG/HTML and arbitrary URL imports are rejected. A later image-processing step may re-encode to normalized WebP when the host image library is available; gameplay never depends on client-supplied MIME or extension.

Protected fronts are authorized per request and served by Nginx through an internal handoff. Public back/UI assets may be immutable-cacheable. A direct storage path, guessed asset ID or stale browser URL cannot bypass current permission.

## Templates and versions

An owner creates a named `deck_template`; each edit creates an immutable `deck_template_version` with ordered card definitions, quantities, front assets and optional backs. A session deck snapshots one version. Editing or deleting a template never mutates active card instances. Two instances from one definition have unique `session_cards` but may reference shared immutable artwork.

## Acceptance

- Oversized, corrupt, spoofed and unsupported uploads fail without orphaned database/file state.
- Content-addressed duplicates do not duplicate bytes while ownership/authorization remains explicit.
- Template version changes do not affect an instantiated session.
- Unauthorized users cannot read a protected front; an owner can edit/use their own asset.
