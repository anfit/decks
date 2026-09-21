# Decks scope and requirements baseline

Status: baseline for implementation, 2026-09-21.

## Source documents

The implementation plan in `tmp/decks-implementation-plan.md` is the execution record and is ignored by Git. The following inputs were read and pinned by SHA-256 for this baseline:

| ID | Source | SHA-256 |
|---|---|---|
| B | Business specification 16, revision 1 | `3192646E4C3C2B914AF8F46A9711ECA6A38D4424B6D991D82D62EF96C1B69106` |
| T | Technical specification 17 | `83D41220E2CD1A558CB29325BEC69385D2F48E244E1AB5C5B0A5B0A6491A3DB6` |
| D | Deployment specification 18 | `C7EE27737E4D7EC41DB7F09D8B3C878F688C37E6F3FF75B9B9DEA32C05EF775D` |
| SA | `C:\projects\scholion\docs\scholion-reference-architecture.md` | `408965B200D1054446B8811CC8631300678E8780045C1F86FA3E9EE5D33A082C` |
| SU | `C:\projects\scholion\docs\authentication-authorization-and-users.md` | `9BD5F84243236E70A9301AD2A087CC84983583C2F843EB71C8291BA3B895904C` |

Scholion source checkout observed at commit `63d19c95ed7caf9cb7adbcab507b2e37cf087327`. Reused code or copied patterns must record the exact source commit and adaptations in the users/mail spec and implementation commit.

## Product boundary

Decks is a rule-agnostic shared table for manipulating physical-equivalent cards and piles. It provides named local accounts, reusable deck templates, table sessions, participants, private hands, public table objects, piles, zones, mats, presets, server-authoritative randomness, synchronized actions, reset and recovery. It does not implement turns, scoring, card effects, legal game moves, win conditions, AI or game-specific metadata interpretation.

The production target is `https://decks.mmanir.pl` on the OVH host represented by service-infra host `prod`. DNS, TLS certificates, SMTP sender setup and host packages remain operator-owned prerequisites.

## Non-negotiable invariants

1. PHP/PostgreSQL are authoritative for durable state; browser and Node state is disposable.
2. Every card instance has exactly one session-scoped logical location.
3. Accepted durable mutations are validated, atomic, idempotent and advance one session revision.
4. Randomized results are generated only by the server.
5. Unauthorized clients never receive hidden card identity, ordering, private metadata or protected front asset paths.
6. Table roles and object permissions are separate from global account administration; a global admin does not automatically see a private hand.
7. Account and table invitation credentials are single-use, expiring and stored only as hashes/secrets that cannot be recovered from the database.
8. Durable action history is sanitized; it is not an event-sourced source of hidden state.
9. Nginx serves normal asset bytes; PHP authorizes protected assets through an internal delivery mechanism.
10. Reconnection can reconstruct correct authorized state from PostgreSQL-backed snapshots/changes without relying on WebSocket delivery history.
11. Deployment artifacts are immutable, secrets are environment references, persistent data is outside release trees, and a repeat plan is a no-op.

## Milestones

- Foundation: S00–S06, including named accounts and mail.
- Playable trial: S07–S08.
- Complete MVP: S09–S11 plus S13 acceptance.
- Broader business operation families: S12.
- OVH launch and handover: S14–S16.

## Requirement ownership

The uncommitted plan contains the complete B/T/D traceability table. The major implementation owners are:

- sessions, roles, templates, cards, visibility and actions: S03–S07;
- piles, multi-card manipulation, zones and reset: S09–S11;
- users, account invitations, table join links, recovery and mail: S03–S04;
- revisions, idempotency, changes and realtime recovery: S05 and S08;
- assets and protected delivery: S06;
- production routing, release provenance, backup and rollback: S01 and S14–S16.

Optional behavior such as guest users, MCP/OAuth, multiple named hands, host-approved hidden rollback and resumable snapshot export requires a separate spec before implementation.
