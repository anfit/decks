# Session setup and capability surface

Status: initial S17 setup slice, 2026-09-22.

The authenticated home surface provides a resumable list of the caller's active table memberships. `GET /api/sessions` returns only the caller's non-removed memberships with session id, title, status, revision, role and timestamps; it never returns another participant's private state or join credentials.

Owners may browse their own immutable deck templates/versions through `GET /api/templates` and their own mats/presets through `GET /api/mats-and-presets`. The create-table flow accepts an optional title, participant limit and preset selection. When a preset is selected, the host configures the lobby with `configure_table` and instantiates the preset's pinned template version in a subsequent revisioned action; a failed setup action does not silently claim that a deck was created.

The join surface lets an authenticated visitor choose `player` or `spectator` and submits the opaque table token unchanged. A spectator receives public projections and no mutation capability. Explicit allowlisted capability grants and denials are enforced by action family under `spec/19-capability-policy.md` (implementation commits `777e930` and `4e51b2d`); host controls retain a recovery path. Composite actions also require every affected capability domain, including deck-to-pile draws (`spec/19-capability-policy.md`, commit `56cd9ef`).

The table workspace exposes lifecycle controls to a host with `session.manage`: a lobby can be started, an active session can be ended with confirmation, and a reset returns the table to its lobby state after confirmation. Ended sessions remain read-only except for the documented host reset/recovery path. Collect/reset controls are independent of the participant's deck/card action grants.

Acceptance requires owner scoping for list endpoints, a browser path that creates a configured table and resumes it from the table list, and a browser path that joins as either player or spectator without decoding the token in JavaScript.
