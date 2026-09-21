# OVH production upgrade and migration contract

Status: read-only handover contract, 2026-09-21. This document records the
requirements for a future OVH tier or Debian/Ubuntu migration. It does not
authorize an in-place upgrade and it does not replace host administration.

## Observed production state

The Decks host is `prod` (`ubuntu@vps-6b4ac8bd.vps.ovh.net`) and is shared with
other applications. The verified post-upgrade runtime is Ubuntu 26.04.1,
Node 24.21.0/npm 11.19.0, PHP 8.5.4 with PHP-FPM 8.5, and PostgreSQL 18.6.
Decks uses the `decks-prod`, `decks-realtime-prod`, and `decks-mail-prod`
lifecycles, loopback ports 5240/5241/5242, PostgreSQL database `decks`,
`/var/lib/decks-prod/{assets,sessions}`, the public route
`decks.mmanir.pl`, and OVH SMTP at `smtp.mail.ovh.net:465`.

The host upgrade was external to this application rollout. The current Decks
release is healthy, and the service manifests are converged. The application
and mail manifests explicitly set `PGSSLMODE=disable` for the local PostgreSQL
connection policy; credentials remain in the deployment environment files.

## Preconditions

Before changing the OVH tier or operating system, the operator must:

1. Record the exact source and target OVH plans, resources, disk layout,
   snapshot support, IPv4/IPv6 behavior, reverse DNS, bandwidth and rollback
   route.
2. Inventory every shared-host service, service user, listener, systemd unit,
   timer, Nginx site, PHP-FPM pool, Node process, firewall rule, disk, memory,
   swap and scheduled job. “No other Node users” is not an accepted assumption.
3. Capture an OVH snapshot and an encrypted off-host PostgreSQL backup. Archive
   Decks assets, PHP session storage, deployment manifests, TLS renewal
   configuration and a secret-variable inventory without copying secret values
   into this repository.
4. Restore the PostgreSQL backup and assets into an isolated target before the
   maintenance window and record row counts, permissions and checksums.
5. Confirm the target OS supports PHP-FPM, PostgreSQL client/server, Node,
   Nginx, OpenSSL, curl, tar and systemd versions required by `.deployer/expect.yaml`.

## Maintenance sequence

Announce a maintenance window and stop new table activity. Drain and reconcile
the mail outbox, record active and previous release IDs, and capture public,
loopback and WebSocket health. Stop Decks services only after those records are
complete. Resize or migrate the host, reinstall/verify packages and host-owned
Nginx/PHP-FPM/PostgreSQL configuration, restore persistent data, and preserve
DNS, TLS, SMTP and firewall state. Run the normal workflow in order:

```text
validate -> check -> plan -> apply -> status -> health -> repeat no-op plan
```

Start PHP-FPM and the application, then realtime, then mail. Verify HTTPS health,
authenticated API/session behavior, WebSocket upgrade and listener readiness,
protected asset authorization, PostgreSQL writes, a two-account table flow,
outbox behavior, logs and resource usage before ending the window.

## Rollback and no-go conditions

Application rollback uses the retained vps-deployer previous release and does
not reverse migrations, sent mail, DNS or certificates. OS rollback requires a
tested OVH snapshot or replacement-host cutover; an in-place release upgrade is
not assumed reversible. Database rollback requires a verified backup and an
explicit decision about writes made after that backup.

Go only when the exact plans, shared-host inventory, isolated restores, package
compatibility, DNS/TLS/SMTP preservation, firewall state and rollback path are
documented and the post-change smoke tests pass. Stop when any of those facts
is unknown, especially backup/restore evidence, another shared workload,
PostgreSQL state or rollback capability.
