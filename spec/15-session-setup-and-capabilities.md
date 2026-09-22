# Session setup and capability surface

Status: initial S17 setup slice, 2026-09-22.

The authenticated home surface provides a resumable list of the caller's active table memberships. `GET /api/sessions` returns only the caller's non-removed memberships with session id, title, status, revision, role and timestamps; it never returns another participant's private state or join credentials.

Owners may browse their own immutable deck templates/versions through `GET /api/templates` and their own mats/presets through `GET /api/mats-and-presets`. The create-table flow accepts an optional title, participant limit and preset selection. When a preset is selected, the host configures the lobby with `configure_table` and instantiates the preset's pinned template version in a subsequent revisioned action; a failed setup action does not silently claim that a deck was created.

The join surface lets an authenticated visitor choose `player` or `spectator` and submits the opaque table token unchanged. A spectator receives public projections and no mutation capability. Capability-specific host/player policy remains a follow-up S17 task: role checks remain the server fallback until explicit capability grants are enforced for each action family.

Acceptance requires owner scoping for list endpoints, a browser path that creates a configured table and resumes it from the table list, and a browser path that joins as either player or spectator without decoding the token in JavaScript.
