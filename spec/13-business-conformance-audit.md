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

The latest source review is against commit `e1ba1e1` and committed behavior specs `spec/15` through `spec/21`. The original business-spec share URL could not be retrieved by the available web reader, so this delta uses the business revision/hash and requirements already recorded in `spec/00-scope.md` and this audit. Contract tests pass 23/23, TypeScript typecheck and production frontend build pass, and `git diff --check` passed before commit.

The source now closes several earlier product gaps: setup selects a template version and preset and supports owned-table resume; join chooses player/spectator; participant-major dealing is distinct from round-robin; capability decisions are enforced and host-configurable; pile draw/shuffle/split/merge/collect and restore/collect modes exist in services; accessible card labels and hand play choices exist; and source commit `56cd9ef` adds atomic multi-card rotate/face/z-order controls with version, ownership, privacy, lock and composite-capability checks. Commit `e0b2759` exposes pile split, merge, collect-spread, and labeling in the table UI with lock/capability-aware controls and expected container versions. Commits `37a0e1e` and `2c522e7` render safe pile stacks on the board and add versioned move, rotate and front/back controls. Commit `e1ba1e1` adds host-only start/end controls with confirmation and keeps collect/reset independent of deck capability. These S17/S18 UI slices are source-complete but not yet live in production.

The current production release is still `a70c82c42a9503a6` at source `4e51b2d`; the public HTTPS `/health` endpoint returns `ok`, but all three deployer status checks report stale manifests because the desired release is source `56cd9ef`. Apply stopped at its preflight because this shell has no values for the required `DECKS_*` runtime secrets. Therefore the source-level remediations must not be counted as deployed business conformance.

Remaining product gaps are narrower and ordered by impact: first restore the documented deploy-secret environment in the authorized deployment shell and release the current source; then exercise the configured table, populated pile flows, group actions, private-card boundaries, capability changes, reconnect and restore/collect choices in authenticated browser and multi-participant integration checks; then resolve whether additional public/private access and join-policy controls are required beyond scoped invite tokens, and expose any scoped undo/recovery affordance. Action history, real participant names, richer art/back presentation, and a complete visual zone editor remain follow-up scope. The pile surface still needs visual validation and a pan/zoom strategy for crowded tables. The SMTP, sender-domain DKIM/DMARC, and encrypted off-host backup ownership/retention gates remain open.

## Business-spec comparison and live follow-up — 2026-09-22

Correction to the preceding note: the web search reader did not retrieve the source, but the full read-only shared page was available in the in-app browser. This follow-up compares against the actual revision-1 document at [Online Deck Table — Business Logic Specification](https://special.mmanir.pl/share/eyJ2IjoxLCJpZCI6MTZ9.97a877515fa081e6f02c2920be42e5a251b132665e5f7b7720488f21b82c1083), whose hash matches `spec/00-scope.md` (`3192646E4C3C2B914AF8F46A9711ECA6A38D4424B6D991D82D62EF96C1B69106`).

Audited repository state: `b8a6981` (latest behavior source `e1ba1e1`; tests 23/23, TypeScript typecheck and production frontend build pass). OVH status was rechecked for all three services: desired source is `b8a6981`, active source is `4e51b2d` / release `a70c82c42a9503a6`, and deployer status reports stale/missing manifests and unhealthy provenance. Public HTTPS `/health` still returns `ok`. The current operator shell lacks all seven required `DECKS_*` deploy variables, so the newer source has not been released.

The logged-in production home exposes one standalone template selector, one preset selector, participant count, role choice on join, and a list of resumable tables. The creator can instantiate multiple decks by repeating the server action while the lobby is open, but the web flow only selects one template and one preset-provided template version. The home has no multi-deck composition control or access-policy choice beyond scoped invite tokens. The business document explicitly allows multiple deck instances and asks the creator to select one or more decks, access settings, and participant permissions.

The table view has a substantial safe backend foundation: revisioned atomic actions, hidden-hand projections, server-side randomness, deck/pile/card/hand services, participant capability grants, and sanitized event metadata. Several business-MVP operations still lack a complete user-facing path. The current source's main table controls expose only the first deck and omit draw-to-hand/N/bottom, return-to-deck, move/align selection, hand reorder/give, card remove/restore, pile reverse/flip/spread/merge-into-deck, action history, and zone editing. The deployed pile UI covers draw top/bottom, shuffle and lock; the newer unshipped source additionally exposes split/merge/label/collect-selected, while the backend supports further pile operations with no visible control. Locks cover cards and piles, not the specification's deck/zone/whole-table scopes. Host transfer/removal server actions exist without corresponding participant-management controls; freeze/unfreeze and broader participant administration are incomplete. Treat optional password/open access modes as a policy decision; scoped invite tokens already provide a restricted join path.

Live browser acceptance now confirms the operator can submit the production sign-in form and reach the authenticated home. Opening `Configured setup verification` showed three safe “Smoke card” instances, two empty piles, host capability controls, and accessible card controls. Pressing Enter on a table card flipped it face down and advanced revision 18→19; pressing Enter again restored its visible face and advanced 19→20. The table then showed `Realtime connection closed; retrying`, which persisted after a 2.5-second recheck. The card was restored to its prior visible state. This is a positive keyboard-action check but also a live reconnect defect to investigate; it does not verify the unshipped `e1ba1e1` UI.

### Updated audit disposition

1. **Release provenance is the first gate:** restore the documented deploy environment through the trusted operator mechanism, apply the exact desired source to the web, realtime and mail lifecycles serially, and confirm current manifests, health and remote PHP lint.
2. **Fix and verify realtime lifecycle before broader acceptance:** a successful mutation followed by a persistent closed/retrying status is unacceptable. Review socket ownership across rerenders, ensure stale socket close events cannot initiate duplicate reconnects, then add two-client event/reconnect coverage and verify recovery in a browser.
3. **Close creator setup gaps:** add selection of multiple deck versions and define the invite/access policy and configuration semantics before adding public/password modes. Preserve token secrecy and host setup permissions.
4. **Finish user-visible MVP actions:** add the remaining accessible card, hand, deck and pile workflows and meaningful safe action history; expose supported zone editing/behavior only after its generic, privacy-safe semantics are specified.
5. **Verify privacy and concurrency with multiple participants:** cover player/spectator projections, hidden hand/card boundaries, capability denial, object locks, stale selection atomicity and reconnect against the deployed release.
6. **Complete the UX pass:** add participant labels, clear empty/loading/error/retry states, an intentional pan/zoom and narrow-viewport layout, and visible art/back treatment. Re-run keyboard, touch and mobile viewport checks.
7. Keep the authorized SMTP delivery, DKIM/DMARC and encrypted off-host backup ownership/retention gates open until the relevant external evidence exists.

The audit remains **partial**: source conformance is strong for core state, visibility, randomness and atomicity, but the revision-1 MVP and current production release are not yet fully conformant or accepted.

## Realtime finding response — 2026-09-22

The browser finding was traced to the frontend replacing its socket from `renderTable()` after every snapshot refresh; closing the old socket could schedule a reconnect that displaced the new one. The contract in `spec/05-sync.md` now defines one session-scoped connection, generation-guarded callbacks and a single cancellable retry. Commit `a0abcb8` implements that lifecycle, reports Connected only on WebSocket `open`, and adds a regression contract test. Validation passes 24/24 Python contract tests, TypeScript typecheck, Vite production build and `git diff --check`. This is a source-level correction only: production still runs `4e51b2d`, so the live reconnect observation remains open until the corrected release is deployed and browser-verified.

## Multi-deck creator response — 2026-09-22

The one-template creator gap is closed in source. Spec `15-session-setup-and-capabilities.md` now permits repeated deck selections, including repeated copies of the same template version and additional decks alongside a preset's pinned deck. Commit `93e8d06` adds accessible add/remove deck rows, runs each `instantiate_deck` action serially against the revision returned by the preceding action, disables duplicate submission while setup is running, and leaves an open/copy-token path with an explicit partial-setup error if a later action fails. Validation remains 24/24 Python contract tests, TypeScript typecheck, production build and diff check. The production release remains `4e51b2d`; this source improvement requires deployment and browser verification.

## Action-history response — 2026-09-22

The source now exposes the sanitized event stream in a recent-action panel. Spec `04-actions.md` limits it to revision/time, generic actor (`You` or `A participant`) and allowlisted human-readable action descriptions; it never displays raw payloads, identifiers, card identities or private hand details. Commit `68e58cb` implements the authorized `changes` projection and a newest-first 20-event UI with generic loading/unavailable states. Validation passes 25/25 Python contract tests, TypeScript typecheck, Vite build and `git diff --check`. This closes the action-history *source UI* gap; populated-event and privacy verification still require browser/integration coverage after deployment.

## Current business conformance recheck — 2026-09-22

The shared business specification was inspected in the open, read-only Special page (revision 1); its content hash matches `spec/00-scope.md`. Source review is current through `02e1691` and committed behavior specs. This source state now includes repeatable multi-deck creation, role-aware joining, capability enforcement, the specified pile and collect/restore service operations, accessible multi-card actions, safe recent-action history, a separate safe control panel for every session deck, and selection actions to return cards to their server-resolved source decks. The source-return action accepts an exact version map, validates visibility and locks, groups selected cards by source deck internally, preserves selection order per deck, and exposes only a total count and position; neither source associations nor card IDs enter projections or public activity. Per-deck controls expose draw top/bottom/N to table, the actor's hand, or an unlocked pile, plus shuffle, cut and deal; the snapshot adds only safe deck labels and card counts. Composite source/destination operations now require all corresponding capability grants (`spec/04-actions.md`, `spec/07-table-interaction.md`, `spec/19-capability-policy.md`).

The implementation validation passes 27/27 Python contract tests, TypeScript typecheck, Vite production build, and `git diff --check`. PHP/database integration and remote PHP lint have not been run in this environment, so source review and contract tests do not establish runtime conformance.

The authenticated production browser remains on `4e51b2d` / release `a70c82c42a9503a6`, not the audited source. The `Configured setup verification` table is signed in at revision 20 with three safe face-up cards and two empty piles, but the visible status remains `Realtime connection closed; retrying`; this run made no production mutations. The production page does not expose the newer action-history or per-deck control panels. Fresh deployer status reports all three lifecycles (`decks-prod`, `decks-realtime-prod`, `decks-mail-prod`) as stale/unhealthy against desired commit `a9d012e` / desired release `d6f739601e783bbf`, while active remains `4e51b2d` / `a70c82c42a9503a6`. The public `/health` route says `ok`, which does not override the stale-release status. The current shell has none of the seven required `DECKS_*` deployment variables, and no apply was attempted.

### Remaining business work, in priority order

1. Restore the trusted production runtime environment, validate all three manifests, deploy the exact audited commit, run remote PHP lint, and confirm healthy/current provenance. Do not use `/health` as the sole release check.
2. Run disposable integration and two-client checks for draw targets, populated split/merge/draw/collect paths, return/restore, hand privacy, role/capability denial, object locks, stale revisions, action-history redaction and reconnect. The current production browser result is not acceptance for source code it does not serve.
3. Complete remaining normal-UI business flows: hand reorder/transfer/move-to-pile, selected reveal, remove/restore, merge piles into decks, remaining pile reverse/flip/spread, and participant recovery/transfer/freeze administration. Review each against an existing service before adding duplicate backend behavior.
4. Add a generic visual zone editor and close deck/zone/table lock scope only after their semantics and authorization are specified. Decide whether creation-time permissions or access modes beyond scoped invite tokens belong in MVP; keep the default invite-only restriction until that decision is documented.
5. Re-run the end-to-end business audit against the deployed commit, including player/spectator hidden-state boundaries and a second browser. Keep SMTP, DKIM/DMARC, and encrypted off-host backup evidence as separate go-live gates.

The business result remains **partial**. The backend design and source coverage are materially broader than at the initial audit, but production is stale, the observed live realtime status is failing, and the full UI MVP has not been exercised against the current source.

## Selected card and pile controls completed in source — 2026-09-23

The selected-table-card move/alignment slice (`2898b8d`, `fb6ea9a`), host-only removed-card recovery slice (`72d7f38`, `ac1ad77`), and pile reverse/flip/spread slice (`cd65e21`, `7bbdfbc`) close three gaps identified above. The selection toolbar moves a versioned group with a shared clamped delta and aligns left/top edges. Removal is confirmation-gated and versioned; only the host with effective card-management capability receives an opaque removed-card recovery roster, and restore chooses top/bottom/server shuffle without exposing card identity or origin. Reverse and physical flip require a valid observed pile version; spread additionally requires card-management capability, validates the target pile version and every contained card, and exposes only safe action metadata.

The current source passes 31/31 Python contract tests, TypeScript typecheck, Vite production build, and `git diff --check`. `7bbdfbc` is pushed to `origin/master`. PHP CLI, PostgreSQL integration, and authenticated browser/runtime tools remain unavailable in this environment, so transaction execution and browser behavior are not established by these source checks.

The production browser findings in the audit below are unchanged: production had an immediate reconnect/retry status after card mutations and was missing newer source controls. A fresh local probe on 2026-09-23 found no required `DECKS_*` deploy variables and no `php`, `vps-deployer`, or `vps_deployer` executable. Do not inspect `tmp/.env`; restore the trusted deploy environment and operator toolchain before attempting release. These controls are now source-complete and belong in deployment acceptance, not the source backlog.

### Current remaining business work

1. Restore the trusted OVH deployment environment/toolchain, refresh exact web/realtime/mail manifests, deploy the current commit, run remote PHP lint, and verify release provenance and health.
2. On the exact release, run populated two-browser acceptance for role/privacy boundaries, host recovery, selected move/alignment and remove/restore, pile reverse/flip/spread and all prior deck/pile/hand operations, stale versions, locks, capability denial, action redaction, and WebSocket recovery. Use disposable fixtures and do not send email.
3. Implement the still-unserved participant-management, freeze/lock-scope, and zone-editing requirements only after reviewing their committed behavior specs and clarifying any underspecified privacy/ownership semantics in the smallest spec first. Close demonstrable UX issues from `spec/14` (home density/list scanning, empty-state contrast, table space and responsive/touch interaction) in separate reviewable slices.
4. Decide explicitly whether public/password access modes and creation-time permission presets are required beyond the existing scoped invitation flow. Keep SMTP/DKIM/DMARC and encrypted off-host backup/retention/restore as distinct operational gates.

The business result remains **partial** until deployment, two-client acceptance, remaining MVP gaps, and the external launch gates are evidenced.

## Host participant administration response — 2026-09-23

Spec-first commits `d2fd63c` and `4c711f7` extend `spec/15-session-setup-and-capabilities.md` with the generic host-only remove/restore/transfer UI contract and a capability-reset invariant for host transfer. Source commit `03b8de6` projects removed non-host participants to an authorized host as opaque action IDs plus prior role only, adds confirmed participant removal and host transfer, allows role selection on restoration, hides controls for ended sessions, and clears stale explicit capability overrides on both participants when host role changes. The capability reset ensures the incoming host receives host defaults and the outgoing host receives player defaults. Public event sanitization continues to remove participant IDs.

The source is pushed to `origin/master`; 32/32 contract tests, TypeScript typecheck, Vite production build, and `git diff --check` pass. The local host still lacks PHP/PostgreSQL and the trusted deployment environment, so no database transaction or browser test has accepted the new projection or controls. Treat participant administration as source-complete and retain it in two-account deployed acceptance.

## Visual zone editor response — 2026-09-23

Spec-first commits `d91aae6`, `24b9552`, and `5361231` update `spec/08-presets-zones.md` with a generic rectangle editor, bounded geometry/priority/effects, lobby-only configuration freeze, and mandatory current session-revision checks. Source commit `8678fbf` registers `update_zone`, validates same-session targets and normalized zone data, enforces host plus effective `zone.manage` authorization and lobby status in the service, rejects absent or malformed zone-action revisions, and exposes overlays plus add/edit/confirmed-delete controls only to authorized hosts while the table is in the lobby. Zone overlays are labeled, non-interactive, and contain no card or participant data.

The latest source passes 33/33 contract tests, TypeScript typecheck, Vite production build, and `git diff --check`, and is pushed to `origin/master`. PHP lint/integration and browser acceptance remain unavailable here. The zone editor is source-complete; keep its geometry/effect behavior, lobby freeze, stale revision, capability denial, reset restoration and accessible overlay review in deployment acceptance.

## Source-deck return and composite-capability response — 2026-09-22

Specs `07-table-interaction.md` and `19-capability-policy.md` were updated before implementation (`22581fc`, `2e56a4f`). Commit `02e1691` adds `return_to_source_decks`, an accessible selection-toolbar action for top or bottom placement. The server resolves source deck ids from durable card rows, validates the complete selected-version map and all card visibility/lock/location rules before grouping, performs the per-deck returns inside the enclosing session transaction, and returns only total count and position. The source deck association is not added to the snapshot or public event. The same commit enforces the documented composite capability matrix and hides pile draw/lock/collect affordances when their full capability set is absent. Validation passes 27/27 contract tests, TypeScript typecheck, Vite build and `git diff --check`; PHP CLI and PostgreSQL integration are unavailable locally.

This is source-only. OVH still desires `02e16913e4fd0af9bab15ffbb21007857716db72` / release `c40e8fb4bcd2dcbd` for all three services, but they remain active on `4e51b2db413f52421e7637d7863913d8c84aa69e` / `a70c82c42a9503a6` with stale/missing manifests and unhealthy provenance. Verify source-deck return and the stricter composite grants only after the current release is deployed.

## Business re-audit — 2026-09-22 (source `1dc3085`)

The revision-1 business specification was reopened in its shared read-only view; its scope still matches the pinned baseline in `spec/00-scope.md`. Source review now includes owner-only private-hand ordering and controls. Spec-first commit `c3b6603` defines the UI and privacy contract; implementation commit `1dc3085` adds the `hand_order` field only to the owning participant's projection, earlier/later reorder actions, selected private hand transfers with exact expected card versions, and top/bottom return to each card's server-resolved source deck. Public activity omits participant and hand identifiers; other participants do not receive the owner's hand order or identities. This closes the source/UI gaps for own-hand reorder, give-to-player, and return-to-source. It does not add hand-to-pile or selected private-hand reveal controls.

The latest source contract suite passes 28/28; TypeScript typecheck, Vite production build, and `git diff --check` passed at implementation commit `1dc3085`. PHP lint/runtime and PostgreSQL integration remain unverified because PHP/PostgreSQL tooling is unavailable here. Those source checks do not prove the database transaction behavior in a deployed runtime.

The authenticated production browser opened `Configured setup verification` at revision 28 with three public `Smoke card` objects, two empty piles, the host capability panel, an empty private hand, and `Connected`. Pressing Enter on a table card changed the durable view to revision 29 and `Face-down card`; the connection then changed to `Realtime connection closed; retrying`. Reopening the table confirmed the face-down state persisted. Pressing Enter again restored its original face-up label at revision 30, and the same reconnecting state returned. The smoke card's face state was restored; the test advanced its revision by two. The signed-in table exposes none of the newer per-deck draw, recent-action, source-return, or private-hand controls, so it is not serving the audited source. The latest available deployer observation predates `1dc3085` and reported active `4e51b2d` / `a70c82c42a9503a6`; no deployer status tool was available in this audit pass, so exact current manifests and release provenance must be refreshed before apply. This pass used one browser and did not test another participant, mail, or full gameplay.

The result remains **partial**. Live login/session resume and an accessible keyboard card flip/restore work, but mutation-triggered realtime disconnect is reproduced twice and must be verified against the already-fixed current source after deployment. The business UI still lacks visible hand-to-pile and hand reveal actions; public selected move/align and remove/restore controls; pile-to-deck merge and reverse/flip/spread controls; participant recovery/removal/restore/host transfer; and zone editing. Multi-client hidden-state, capability, lock, stale-version, and reconnect acceptance remains open. Hand reorder/give/source-return no longer belong in the source backlog, but must be exercised in the current deployed release.

### Revised business execution order

1. Restore the trusted runtime environment, refresh all three OVH manifests against exact source `d99c0e9`, and deploy web/realtime/mail only after the required secret preflight succeeds. Run remote PHP lint, check manifests/provenance and health, and verify repeat plans are a no-op. Do not inspect or copy values from `tmp/.env`.
2. Run database/runtime and two-browser tests on that exact release: owner/other/spectator hand projections, hand transfer and source return, deck and populated-pile draw/merge/split/collect, locks and composite capabilities, stale revisions, action/event redaction, WebSocket reconnect and snapshot recovery. Use disposable fixtures and do not send mail.
3. Complete the normal UI for hand-to-pile/reveal, selected move/align, remove/restore, pile merge-to-deck and reverse/flip/spread, and participant removal/restore/host transfer/freeze/recovery. Reuse existing services and define any missing behavior in the smallest committed spec first.
4. Specify and implement visual zone editing and deck/zone/table lock scopes; decide explicitly whether access options beyond scoped invitation tokens and permission presets at creation are needed for the MVP.
5. Re-run business conformance and UX audits against the deployed commit with a second participant/browser and narrow-viewport/keyboard/touch checks. Keep SMTP/DKIM/DMARC and encrypted off-host backup/restore as separate open launch gates.

## Private-hand-to-pile implementation response — 2026-09-22

The audit-identified hand-to-pile UI gap is closed in source commit `d99c0e9`, following the spec-first update in `bf14319` (`spec/07-table-interaction.md`, `spec/16-pile-operations.md`). The owner's hand selection toolbar now chooses among safe-labeled, unlocked piles and submits selected card IDs, exact expected versions and the target pile version. `PileService::move` rejects absent, incomplete or stale version maps and an absent/stale target version before mutation, while existing service checks preserve own-hand privacy and locks. Public activity removes card and participant identifiers. The 29/29 Python contract suite, TypeScript typecheck, Vite build and diff check pass; PHP/PostgreSQL runtime validation is still unavailable. This is source-only: the live release remains older and this control still needs deployed browser, capability-denial, stale-version and two-client privacy acceptance. Hand-to-pile is therefore removed from implementation gaps and retained under release acceptance.

## Pile-to-deck merge implementation response — 2026-09-22

Spec-first commit `88f1c34` defines pile-to-deck merge at top, bottom, and server-randomized shuffle-in, with exact expected pile/deck versions, safe deck version projection, and composite capability requirements (`spec/04-actions.md`, `spec/07-table-interaction.md`, `spec/16-pile-operations.md`, `spec/19-capability-policy.md`). Implementation commit `50b9650` adds the accessible, capability-gated destination/control UI; the service validates versions and locks, randomizes shuffle-in server-side, and returns only safe outcome metadata. Deck versions advance on merge and collect/reset paths. Contract tests pass 30/30; TypeScript typecheck, Vite production build, and `git diff --check` pass. PHP/PostgreSQL integration and remote PHP lint remain unverified.

This closes pile-to-deck merge as a source implementation gap only. The latest observed production release remains older than the audited source, so top/bottom/shuffle-in behavior, stale-version errors, lock/capability denial, hidden-state privacy, and realtime behavior remain release acceptance work. Keep deployment provenance and multi-client acceptance as the first gates; do not infer that the browser has verified this source.

### Business execution order after source implementation

1. Restore the trusted production runtime environment, refresh all three OVH manifests against exact source `50b9650`, and deploy only after required secret preflight succeeds. Run remote PHP lint and confirm active source/release, health, provenance, and no-op repeat plans. Never copy values from `tmp/.env`.
2. Run database/runtime and two-browser acceptance on that release: sign-in/resume, multi-deck setup, owner/other/spectator projections, hand transfer/source return/hand-to-pile, draw targets, populated pile split/merge/merge-to-deck (top, bottom, shuffle-in)/collect, locks and composite capabilities, stale revisions, safe history, and WebSocket update/reconnect recovery. Use disposable fixtures; do not send mail.
3. Implement remaining user-visible gaps: selected private-hand reveal; public selected move/align and remove/restore; remaining pile reverse/flip/spread; participant removal/restore/host transfer/freeze/recovery; then generic zone editing and deck/zone/table lock scopes after defining their semantics. Do not reimplement source-complete merge-to-deck or hand-to-pile paths.
4. Decide and document whether access modes beyond scoped invitations or creation-time permission presets are MVP requirements. Re-run business and UX audits against the exact deployed release, with second-client, keyboard, touch, and narrow-viewport evidence.
5. Keep SMTP delivery authorization, sender DKIM/DMARC, and encrypted off-host backup/retention/restore as separate launch gates. The overall business result remains partial until these and production acceptance are complete.

## Live production business audit — 2026-09-22

The authenticated Decks UI at [decks.mmanir.pl](https://decks.mmanir.pl/) was opened in a browser as `decks@mmanir.pl`. The revision-1 shared business spec was read at its supplied read-only Special URL; it is the same scope pinned in `spec/00-scope.md`. No new session was created and no email was sent.

Live checks passed: sign-in reached the owner home; the home exposed create/join forms, a preset and standalone template selector, Player/Spectator choice, invitation link, and resumable owner tables; `Configured setup verification` opened at revision 30 with three public Smoke cards and two empty piles. Pressing Enter on a Smoke card changed its face down and advanced revision 30→31. Pressing Enter again restored its visible face and advanced revision 31→32. Reopening showed the face-up state at revision 32 and `Connected`.

The live release did not pass realtime acceptance: after each accepted flip, status changed immediately to `Realtime connection closed; retrying` and remained there for at least three seconds. Reopening the same session established `Connected` again. This reproduces the mutation-triggered disconnect on current production. It is one-account/one-browser evidence and establishes neither second-client delivery nor privacy boundaries.

Production UI evidence also shows that the release is behind current source. The home offers one standalone deck-template selector rather than the repeatable multi-deck rows; the table has no per-deck controls, action-history panel, source-deck return toolbar, private-hand reorder/give/return or hand-to-pile controls, and no pile-to-deck merge modes. It exposes only the existing pile draw-top/draw-bottom/shuffle/lock controls for the two empty piles. Therefore the recent source-complete hand/pile/card/history features cannot be accepted from this browser pass. No populated pile or private-hand operation was exercised.

### Current business result and remaining plan

The production UI demonstrates working sign-in, owner-table resume, public card face mutation with revision advancement, and restoration of the test card. It is **not business-MVP conformant or accepted**: reconnect fails after a durable mutation, many revision-1 minimum-feature workflows are absent from the deployed UI, and multi-participant privacy/concurrency remain unverified. Current recorded OVH provenance is stale relative to recent source; this browser pass did not query or alter deployer state.

Keep the project execution plan paused. When explicitly resumed, first establish exact active release provenance and deploy the already-audited source through the trusted operator setup; then verify that release's implemented setup, per-deck actions, private-hand controls, action history, pile operations (including all three merge modes), authorization, hidden-state privacy, stale-state rejection, and WebSocket recovery in two browsers. Implement only the genuinely missing MVP items identified after that release check. Keep broader zone editing and optional access modes separate decisions, and keep SMTP/DKIM/DMARC and encrypted off-host backup as operational launch gates.
