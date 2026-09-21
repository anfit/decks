# Decks architecture baseline

Status: baseline for implementation, 2026-09-21. This document applies Scholion's small PHP/PostgreSQL conventions to the card-table domain; it is not a shared framework.

## Runtime boundaries

```text
Browser DOM app  <-->  Nginx  <-->  PHP-FPM + PostgreSQL
       |                         |
       +------ WebSocket --------+--> thin Node realtime service
                                  |
                                  +--> PostgreSQL LISTEN/NOTIFY
```

- Browser owns rendering, pan/zoom, pointer interaction, transient selection, local drag previews and accessible menus. It never chooses card identity, order, permission, randomness or final durable location.
- PHP is the application authority. A single bootstrap establishes environment validation, secure PHP session, request-scoped principal and PDO. Explicit application services perform authorization, transactions, mutations, projections and audit/outbox writes.
- PostgreSQL stores normalized current state, revisions, idempotency results, security records, mail outbox and bounded event/action history. Append-only checksum migrations are applied by a controlled command.
- Node owns WebSocket lifecycle, authenticated session channels, transient cursor/drag relays and post-commit revision notifications. It does not implement card business rules or durable state.
- Nginx terminates TLS, serves versioned public frontend assets, routes API traffic through PHP-FPM, proxies `/ws` with upgrade headers, and serves protected files only through an internal authorization handoff such as X-Accel-Redirect.
- A PHP CLI mail worker claims Decks-owned outbox rows and sends invitation/reset mail over authenticated TLS SMTP. It is a separate supervised lifecycle and has no public route.

## Scholion conventions retained

Use a bootstrap/request scope, a central principal and capability policy, explicit short transactions, PostgreSQL constraints/locks, append-only checksum migrations, CSRF and escaping helpers, bounded machine responses, and contract/security/smoke tests. Cohesive services may contain direct prepared SQL; do not introduce a repository or dependency-injection framework without evidence from a second domain. Sensitive operations re-check authorization in the service even when an adapter already checked it.

Adapt, do not share: Scholion's user database, cookies, secrets, domain services, corpus vocabulary, activity counters, views, MCP/OAuth subsystem or live mail worker. Decks has its own users, global roles, table roles, SMTP account, public base URL and email templates.

## Repository layout

```text
src/                 bootstrap, security, auth adjuncts, domain/application services
public/              front controller and public entry assets
frontend/            TypeScript/Vite DOM client
realtime/            Node WebSocket service
migrations/          ordered append-only PostgreSQL migrations
scripts/             migrations, workers, bootstrap, diagnostics
tests/               PHP/domain/integration/browser/contract tests
spec/                committed implementation contracts
.deployer/            release entrypoints, expectations and ignore rules
```

The public document root must not expose source, migrations, credentials, temporary uploads, original artwork or release metadata. Persistent assets and staging live outside immutable release trees.

## Durable action flow

1. Resolve the enabled Decks principal and table membership.
2. Validate request shape and CSRF/origin where applicable.
3. Begin a PostgreSQL transaction and lock the session row.
4. Check idempotency by `(session, actor, action_id)` and canonical request hash.
5. Lock/load dependent cards and containers; validate permissions, versions, locks, visibility and invariants.
6. Apply one complete domain mutation, write sanitized event/audit/outbox rows and advance `session_revision`.
7. Commit, then allow a small PostgreSQL notification containing only session/revision identifiers.
8. Return a viewer-authorized result; never serialize raw rows or hidden event payloads.

Different sessions may proceed concurrently. Stale dependency versions produce a conflict response rather than a blind replay. Continuous pointer movement is transient; pointer release creates the durable action.

## Local and production runtime

The current production host provides Node `24.21.0`, npm `11.19.0` and PostgreSQL 18. The realtime bridge is supported on the Node 24.x line; the application contract and lockfile must therefore reject older or newer major Node versions rather than silently selecting an unsupported runtime. Production prerequisites must assert PHP `>=8.2`, PostgreSQL 18 and Node `>=24.21 <25` on `prod`; they must not be guessed from this workstation. The Windows shell may use a different Node/PostgreSQL client version, and PHP/FPM integration still requires WSL/Linux or an equivalent pinned environment.

The initial renderer is DOM-based with absolutely positioned cards and CSS transforms. Canvas/WebGL, Redis, an external queue, containers, a CDN and multiple application nodes are deferred until measured need.
