# Decks operations and deployment contract

Status: initial contract, 2026-09-21. This document records the S01 host boundary; it is not proof of production readiness.

## Ownership

- Decks application owns executable code, `.deployer` entrypoints/expectations, release-relative runtime commands, migrations and application defaults.
- service-infra owns the `prod` host declaration, deployment manifests, non-secret environment, route name/domain, storage paths and secret-variable names.
- Operator/host administration owns Linux packages, PHP-FPM pools, the internal application Nginx listener, PostgreSQL provisioning, DNS, TLS issuance/renewal, SMTP provider setup, backups and firewall policy.
- vps-deployer owns immutable release trees, systemd application/Node/mail lifecycles, public Nginx proxy, health/activation checks and one rollback target. It does not own DNS, certificates, database provisioning or arbitrary host configuration.

## Public and internal routing

The existing vps-deployer `http_proxy` remains the public route:

```text
decks.mmanir.pl:80  -> 308 HTTPS
decks.mmanir.pl:443 -> http://127.0.0.1:<internal-web-port>
```

For the initial deployment, host administration provisions the internal web listener because the current deployer renders only a reverse proxy. Its configuration is constrained and reviewed as a host prerequisite, not supplied as arbitrary application input:

```text
internal-web-port
  /assets/...            immutable files from the active release
  /                      PHP front controller through PHP-FPM
  /protected-assets/...  internal-only X-Accel alias to persistent assets
  /ws                    WebSocket upgrade to Node on loopback
```

The internal listener uses the active release symlink and an explicit PHP-FPM socket/loopback endpoint. It must not expose `/spec`, source, migrations, `.env`, original uploads or release metadata. X-Accel internal locations reject direct public requests; PHP authorizes a front asset and returns the internal handoff. The public deployer proxy has no permission to bypass this boundary.

S01 may later replace this host-managed internal listener with a constrained generic deployer route capability. That change requires a separate vps-deployer commit and fixture; it must preserve the same ownership, validation and rollback guarantees.

## Proposed lifecycle set

Names and ports are proposals until an actual host collision check:

| Lifecycle | Role | Candidate |
|---|---|---|
| `decks-prod` | PHP-FPM application release and public route | internal web 5240, FPM 5241 |
| `decks-realtime-prod` | Node WebSocket service | 5242 |
| `decks-mail-prod` | PHP CLI outbox worker | no public port |
| `decks-db-bootstrap` | one-shot database/user bootstrap | no route |
| `decks-migrate` | controlled schema migration | no route |
| `decks-retention` | scheduled cleanup | timer, no route |

All network listeners bind loopback. A dedicated non-root account is used per independent lifecycle where supported. The mail worker receives SMTP secrets; the realtime service receives only the credentials/signing material it needs.

## Health and activation

The deployer health URL is `http://127.0.0.1:<internal-web-port>/health`, so it tests Nginx plus PHP-FPM, not PHP-FPM directly. Node has a local readiness check used by its service/diagnostics. A release is healthy only after the migration compatibility check, PHP response, protected-asset route, Node readiness and public proxy configuration have been checked.

The PHP-FPM release entrypoint first syntax-checks the committed application PHP files and rejects PHP lint warnings, deprecations and notices as well as syntax errors, then runs the checksum-verified migration runner before starting FPM. Any PHP diagnostic, migration or checksum failure stops startup so the deployer's health check cannot accept a noisy or invalid release or a partial schema. Migrations use explicit PostgreSQL transactions. Previously active PHP, Node and mail releases must remain compatible with each expand-only migration while services are activated serially. An already-applied migration is a no-op after its checksum is verified, so ordinary restarts do not repeat schema changes. This keeps validation and migration execution in the reviewed release lifecycle and records output through its normal service logs.

The current `prod` host uses PostgreSQL 18 on loopback. Decks application and mail-worker manifests set the non-secret `PGSSLMODE=disable` value explicitly because the local PostgreSQL policy authenticates the service roles without TLS; credentials remain in the deployment environment file. This is a host-local connection setting and must be revalidated if PostgreSQL or the network boundary changes.

Database migrations are expand/contract and backward-compatible across the retained application/Node/mail release set. Deployer rollback does not reverse migrations, sent mail, DNS, certificates or external side effects. The previous compatible release remains available until the complete activation and public smoke tests pass.

## Secrets and environment

Commit variable names, never values. Expected names include `DECKS_APP_KEY`, `DECKS_DB_PASSWORD`, `DECKS_REALTIME_DB_PASSWORD`, `DECKS_REALTIME_SIGNING_KEY`, `DECKS_SMTP_PASSWORD` and separately scoped bootstrap/migration credentials. Non-secret values include `DECKS_PUBLIC_BASE_URL=https://decks.mmanir.pl`, `DECKS_MAIL_FROM`, SMTP host/port/security/username and lifecycle limits.

The realtime release carries the locked production `node_modules` tree because the current deployer has no build/install hook. Development-only packages remain present in the artifact for now; a later deployer build phase should replace this with a reproducible `npm ci --omit=dev` artifact before capacity-sensitive launch. The production runtime contract is Node `>=22.22 <25` with PostgreSQL 18; the Node engine declaration, deployer expectation and lockfile must agree. PHP-FPM discovery must include the installed supported PHP-FPM minor rather than requiring an obsolete binary name.

## Launch checks

The DNS A record for `decks.mmanir.pl` has been created. Before public launch, verify any AAAA record, existing TLS PEM paths, sender-domain verification/SPF/DKIM/DMARC, outbound SMTP delivery, internal and public routes, WebSocket HTTP 101, protected asset denial/delivery, account invite/reset flows, multi-user play/reconnect, logs without secrets/private card identity, backups and isolated restore. The temporary operator SMTP details are in the ignored `tmp/.env`; load them only into the current process or an approved secret store, never commit or print them. A repeat `vps-deployer plan` must be a no-op.

## Retention command

`scripts/retention.php` is the controlled cleanup entrypoint. It runs in read-only report mode by default; `--apply` additionally requires `DECKS_RETENTION_CONFIRM=apply` in the private operator environment. Every duration is supplied through a bounded environment variable, and absent durations mean no deletion. The command may expire password-reset and remember-token rows, prune old rate-limit windows, and redact or remove old sent/failed outbox rows when their configured ages have elapsed. Session, event and asset deletion is disabled unless the operator sets the corresponding explicit allow flag after an isolated restore and backup reconciliation. It never deletes active sessions, current assets, unconsumed tokens or queued mail, and it never sends mail. A production timer is intentionally not declared until backup ownership, retention periods, alerts and restore policy are approved.

## Backup and restore baseline

No Decks-owned backup job, off-host destination, or completed isolated-restore record is currently defined. The only scheduled-data operation in this repository is the retention dry-run/apply command above; it is not a backup. Do not report backups as enabled or production-ready until an operator has selected and verified the destination.

Each recoverable backup generation must pair a PostgreSQL custom-format dump of database `decks` with the persistent asset tree at `/var/lib/decks-prod/assets`, plus a small non-secret manifest recording capture time, application source commit, schema/migration checksums, PostgreSQL major version, and artifact hashes. Encrypt before transfer to an off-host destination; keep encryption keys and destination credentials out of the backup set and off the production host. A VPS snapshot may supplement this set but does not replace the database-and-assets backup. Backup retention, cadence, RPO/RTO, owner, alert route, destination and key custody are explicit operator decisions and must be recorded before enabling a timer.

Restore validation must use an isolated database and asset directory, never overwrite production during a drill. Restore both artifacts from the same generation; verify PostgreSQL integrity, migration compatibility, asset hashes and file ownership, then run the application health check and authenticated non-mail smoke flows including protected-asset authorization. Record duration and results without secrets. A restore exercise is required before relying on the backup for maintenance or launch; an untested backup is not recovery evidence.
