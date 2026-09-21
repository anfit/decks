# Decks users, invitations and mail

Status: baseline for implementation, 2026-09-21.

## Reuse provenance and boundary

Use `C:\projects\scholion\docs\scholion-reference-architecture.md` and `C:\projects\scholion\docs\authentication-authorization-and-users.md` as the reference architecture and security behavior. The source checkout was `63d19c95ed7caf9cb7adbcab507b2e37cf087327`. Copy selected implementation patterns only after reviewing the current files; record source paths, commit and adaptations in the implementation commit.

Decks gets its own database, cookies, users, credentials, SMTP account, sender identity, public URL and mail worker. Do not share Scholion sessions, user tables, secrets or worker processes. Scholion's corpus, visit counters, MCP/OAuth and global `model.read/model.write` policy do not apply.

## Account identity and authentication

- Users are named local accounts. Email is the case-insensitive unique login/contact identifier for invited accounts.
- No public registration. A private CLI bootstrap creates the first enabled administrator.
- Passwords use PHP's `PASSWORD_DEFAULT` hashes, `password_verify`, rehash when needed, and Scholion's 16 Unicode character minimum / 4096-byte maximum for new passwords.
- Browser authentication uses a server-side PHP session with a Decks-specific cookie, `HttpOnly`, `Secure` under HTTPS and `SameSite=Strict`. Successful login regenerates the session ID and only accepts a local absolute return path.
- Remember-me is opt-in or default according to the product UI decision, with a 60-day default configurable from 1–3650 days. Store a random selector plus a hash of a high-entropy secret; rotate on restoration under row lock; revoke on logout/password changes/reset/disablement.
- Re-resolve the current enabled user and permissions on every protected request. Account role changes take effect without waiting for token expiry.
- Track an account/session security version or server-side session registry so password reset/change, disablement and sign-out-everywhere invalidate all intended PHP sessions and WebSocket tickets. This strengthens Scholion's ordinary browser-session behavior deliberately.

## Global roles and table roles

Global roles are `member` and `admin`; capabilities include `users.invite`, `users.manage`, template ownership and administrative operations. Table roles are independently `host`, `player` and `spectator`, with per-table capabilities such as draw, shuffle, manipulate public cards, manipulate own hand, peek, lock and reset.

A global administrator does not automatically gain visibility into a private hand or permission to mutate a table. Table membership and object grants are required. Disabled global users lose all table access and realtime sessions.

## Account invitations

- Account invitations are distinct from table join links. Account invitations create a named Decks user; table links only offer membership to an already authenticated eligible user.
- Use a 16-byte selector and 32-byte secret in `selector.secret` form; store selector and SHA-256 secret hash, expire by default after seven days, and accept once.
- Normalize email and lock by normalized address. Existing users and duplicate active invitations consume no credit. Admins have unlimited invitations; members start with one credit; acceptance creates an enabled member with one credit unless a later product decision changes this.
- Invitation creation atomically consumes credit, stores the invitation, writes audit metadata and queues mail. Acceptance creates the account/session without issuing a remember token.
- Resend rotates token and expiry. Rescind invalidates the link, cancels unsent delivery where possible and idempotently restores an eligible consumed credit. Expiry or delivery failure does not automatically restore credit.
- Inviter/admin views show delivery state without revealing token values. Old links fail closed.

## Password recovery and mail

- Password reset links are single-use, one hour by default, replace earlier unused links and revoke remember/OAuth-equivalent authentication artifacts on completion. Forgot-password requests have generic responses and rate limits to prevent account enumeration.
- `email_outbox` is Decks-owned and inserted in the same transaction as invitation/reset mutations. The worker claims rows safely, sends via authenticated TLS SMTP (default implicit TLS/465), uses bounded exponential retry and exposes permanent/exhausted failures to admins.
- Successful delivery redacts message bodies. Passwords, token secrets and token-bearing URLs never appear in audit details, application logs or deployment plans.
- Development uses a local SMTP sink. Production requires separate Decks settings for public base URL, visible sender, SMTP host/port/security/username/password and any provider sender-domain verification. The temporary operator values are kept in ignored `tmp/.env`; only variable names and loading instructions belong in tracked documentation. SPF/DKIM/DMARC and bounce/reply handling are operator-owned launch prerequisites.
- Mail is at-least-once. Repeated delivery must not repeat account creation or token consumption. Restores pause the worker until queued messages and revoked credentials are reconciled; historical mail is never blindly resent.

## Table join and reconnection

Table join tokens/codes are scoped to a session and do not authenticate a visitor. A visitor follows a local return path through login/account acceptance, then the server revalidates the link and creates membership. The membership links to `app_user` and is unique per session/user. Reconnect on another authenticated device restores the same participant and hand.

Leaving/disconnect retains cards and membership unless explicitly removed. Removal revokes HTTP and WebSocket access but does not silently move the hand; host recovery policy is a separate action. Presence timeouts never delete users, memberships or cards.

## Administrative invariants and audit

Protect the final enabled administrator from disablement/demotion. Do not expose another user's password or reset secret. Keep disabled users for audit attribution. Audit account invitations, resend/rescind/acceptance, credit restoration, reset requests/completion, password changes, role and enabled-state changes, sign-out-everywhere, and table membership administration. Account audit is separate from sanitized table action history.

## Acceptance examples

1. First-admin bootstrap, account invitation, SMTP delivery, acceptance and login create exactly one account and do not issue remember tokens automatically.
2. Two concurrent invitations for one normalized email consume at most one credit and leave one active invitation.
3. Resend invalidates the old link; rescind cannot be replayed to restore credit twice.
4. Password reset and disablement invalidate intended current sessions, remember tokens and WebSocket tickets; a role change takes effect on the next request.
5. A forwarded table link cannot log in, transfer a hand or bypass table capabilities.
6. A global admin without table membership cannot read another participant's hand.
7. SMTP outage leaves the account/invitation transaction committed and the message retryable without duplicating account effects.
