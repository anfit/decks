# Business conformance audit

Audit date: 2026-09-22  
Business source: Online Deck Table — Business Logic Specification, revision 1, business-spec hash `3192646E4C3C2B914AF8F46A9711ECA6A38D4424B6D991D82D62EF96C1B69106` (as recorded in `spec/00-scope.md`).  
Audited source: commit `6bb2ea9` and production release `f9d9480cadf03788`.

## Method and evidence

The audit compared the revision-1 business specification with the committed PHP services, PostgreSQL schema, HTTP/WebSocket projections, frontend, contract tests, and the deployed health endpoints. The production release reports all three lifecycles healthy, with the application, realtime listener, and mail unit active. The focused contract suite passes 12 tests; TypeScript type checking, the frontend build, and `git diff --check` pass.

The browser acceptance check is recorded in the execution plan after the admin session is completed. This document deliberately separates server-side conformance from whether the normal web UI exposes the behavior.

## Findings

### Conformant or substantially conformant

- PHP/PostgreSQL own durable state and actions. Actions run in a transaction, lock the session row, advance the revision, enforce idempotency, and emit sanitized events.
- Card uniqueness, locations, ordering, locks, versions, and session lifecycle are represented by database constraints and service validation. Stale revisions and invalid targets fail atomically.
- Hidden hands and private cards are filtered from public projections. Face-down card identities, protected asset paths, private metadata, and hidden ordering are not sent to unauthorized clients or logs.
- Server-side randomness is used for shuffle, deal, cut, and insertion. The action/event path does not expose hidden random results.
- Authentication, invitations, join, leave, reconnect, end, host recovery, and participant roles are implemented. Realtime tickets and revisioned changes support reconnect.
- Deck templates, versions, card definitions, uploaded assets, mats, presets, and generic server-side zones exist in the backend model. Zone effects are generic and privacy-aware.
- The implemented undo path is restricted to actor-owned spatial move/rotate actions and validates the current version; reveal, random, private, and stale-state actions are not unilaterally undone.

### Partial conformance and gaps

The product surface is materially behind the business MVP even where backend primitives exist.

| Priority | Area | Evidence and required follow-up |
| --- | --- | --- |
| P0 | Primary web flow | The normal UI only creates or joins a blank table and exposes Refresh, Draw top, Shuffle, Cut, Deal one each, Collect all, and Reset. It cannot choose a template, deck, preset, access mode, participant permissions, or spectator role. Wire the intended setup and core actions into the UI. |
| Resolved | Join-token flow | The original audit found that the frontend decoded the scoped random `hex.hex` token as padded base64 JSON. Commit `8d59e4f` adds the token-only join endpoint, submits the token unchanged, and adds a contract regression test. Production release `e43714282ca850be` now passes a live create → paste → join browser check. |
| P1 | Capability permissions | `session_participants.capabilities` is stored, but service checks and UI controls do not consistently enforce or configure capability-level permissions. Add an explicit policy matrix and host controls. |
| P1 | Deal semantics | `per_participant` computes a mode but currently iterates round-robin, so participant-at-a-time behavior is not implemented. Correct the service and add a contract test. |
| P1 | Pile operations | Missing explicit pile draw top/bottom, pile split/merge, collect-spread, pile spatial move/rotate/z-order, labels, and locks. Add actions, authorization, projections, and tests. |
| P1 | Card and multi-card actions | Missing z-order front/back/layer actions, multi-card rotate/face actions, and an explicit public reveal-selection action. Add atomic action variants and privacy tests. |
| P1 | Session configuration | The create endpoint accepts only title/max participants; the UI has no deck/preset/access/permission configuration and joining hardcodes a player role. Add setup and role-aware join controls. |
| P2 | Restore/collect modes | Restore is effectively bottom-like and ignores caller selection of top/bottom/shuffle. Collect always restores original template order, while the specification allows original, shuffle, or preserve modes. Add explicit modes and audit them. |
| P2 | Presentation and observability | Cards render UUID prefixes rather than card art/meaningful labels, there is no action-log view, and face-down piles are omitted instead of showing a safe back/count representation. Improve the safe projection and operator feedback without revealing hidden identity. |
| P2 | Administration | There are no explicit freeze/unfreeze controls or promote/demote/capability administration in the product surface. Add host-only controls if they remain in scope for the MVP. |

## Conformance result

The backend invariants and privacy boundary are largely conformant, but the business MVP is only partially delivered at the UI/product surface. The remaining plan must prioritize a complete session setup and card-table flow, then fill the missing pile/card semantics and permissions before treating the application as business-complete.

Remediation verification: the P0 join-token defect is closed in production. The browser now creates a table, accepts the displayed opaque token in the Join table field, opens the resulting table, and reports `Connected`.

## Required plan adjustments

1. **S17 — Capability policy and session setup:** define and enforce capability checks, add deck/template/preset/access/role setup, and expose host controls.
2. **S18 — Complete card and pile semantics:** fix deal mode; add missing pile, z-order, multi-card, reveal, restore, and collect modes with contract and privacy tests.
3. **S19 — UX and browser acceptance:** expose the primary flow, meaningful card presentation, accessible actions, and end-to-end browser checks.
4. Keep the external DKIM/DMARC, authorized SMTP test, and encrypted off-host backup gates in S15/S16 open until their evidence is supplied.

## Follow-up audit — 2026-09-22

The latest source review is against commit `56cd9ef` and committed behavior specs `spec/15` through `spec/21`. The original business-spec share URL could not be retrieved by the available web reader, so this delta uses the business revision/hash and requirements already recorded in `spec/00-scope.md` and this audit. Contract tests pass 22/22, TypeScript typecheck and production frontend build pass, and `git diff --check` passed before commit.

The source now closes several earlier product gaps: setup selects a template version and preset and supports owned-table resume; join chooses player/spectator; participant-major dealing is distinct from round-robin; capability decisions are enforced and host-configurable; explicit pile draw/shuffle/split/merge/collect and restore/collect modes exist in services; accessible card labels and hand play choices exist; and this source slice adds atomic multi-card rotate/face/z-order controls with version, ownership, privacy, lock and composite-capability checks. The exact group-selection controls are not yet live in production.

The current production release is still `a70c82c42a9503a6` at source `4e51b2d`; the public HTTPS `/health` endpoint returns `ok`, but all three deployer status checks report stale manifests because the desired release is source `56cd9ef`. Apply stopped at its preflight because this shell has no values for the required `DECKS_*` runtime secrets. Therefore the source-level remediations must not be counted as deployed business conformance.

Remaining product gaps are narrower and ordered by impact: first restore the documented deploy-secret environment in the authorized deployment shell and release the current source; then exercise the configured table, populated pile flows, group actions, private-card boundaries, capability changes, reconnect and restore/collect choices in authenticated browser and multi-participant integration checks; then expose the remaining service operations in the UI (pile split/merge/collect-spread, pile positioning/labels, session/access/start/end controls, and any scoped undo/recovery affordance). Action history, real participant names, richer art/back presentation, and a complete visual zone editor remain follow-up scope. The SMTP, sender-domain DKIM/DMARC, and encrypted off-host backup ownership/retention gates remain open.
