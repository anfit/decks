# UX audit

Audit date: 2026-09-22  
Audited release: production `f9d9480cadf03788` (source `6bb2ea9`).  
Scope: signed-in Decks web UI, login, home/session setup, table interaction, responsive behavior, accessibility, feedback, and privacy clarity.

## Findings

### Login and account entry

The login page has a visible email label, password field, keep-signed-in option, submit button, and recovery link. The live browser check is recorded in the execution plan after the admin session is submitted. The page should retain the current clear labels and add explicit loading, invalid-credential, and network-error states that preserve entered email without exposing password values.

### Home and session setup

The signed-in home shell gives a clear create-table and join-by-token path and shows connection status. It does not list or resume the user's tables, choose a deck/template or preset, set access and participant permissions, or explain player versus spectator roles. A token-only join flow is difficult to discover and gives no context about the table before joining.

During the live check, creating a table returned a token in the documented `hex.hex` form, but pasting that token into the visible Join table field failed in the browser with `InvalidCharacterError` from the frontend's base64 decoding path. This is a P0 usability and functional defect because the only advertised way to enter another table is broken.

Remediation verification: commit `8d59e4f` changed the UI to submit the opaque token unchanged to `POST /api/sessions/join`. Production release `e43714282ca850be` now passes create → share → paste → join in the live browser; the opened table shows its title, lobby, participant and `Connected` status.

### Table workspace

The table exposes useful title, status, revision, card count, zone list, and participant information. The visible action set is sparse: Refresh, Draw top, Shuffle, Cut, Deal one each, Collect all, and Reset table. There is no pile management, selection model, action menu, face-up play from hand, rotate/z-order control, zone interaction, or undo affordance. Reset confirmation is a good safeguard, but ordinary actions have little operation feedback and there are no consistent loading/disabled states.

### Cards and interaction

Cards support pointer dragging and double-click flipping. They lack focusability, keyboard actions, semantic controls, selection feedback, accessible names for the available actions, and a visible action menu. The hand exposes only “Play face down”; “Play face up” is absent. Cards display UUID fragments instead of useful art or labels, which makes a real game table hard to read.

### Responsive and visual design

The fixed green surface and fixed card size provide a minimal tabletop metaphor, but there is no pan/zoom strategy, touch-friendly selection, mobile safe-area handling, or dense-table layout. Focus styling is not intentionally defined (`:focus-visible` is absent), and the UI relies heavily on browser defaults. Empty states do not teach the next action and error text is plain and easy to miss.

### Privacy and clarity

The implementation is careful about not exposing hidden identities in projections. The UI nevertheless labels other participants generically as “Player”, and its limited public representation can make face-down cards and pile counts hard to understand. Improve names and safe counts without exposing private card identity or protected asset paths.

## Priority order

| Priority | Work | Acceptance signal |
| --- | --- | --- |
| P0 | Complete the primary flow | A user can choose a deck/template and preset, create a configured table, join with the intended role, and reach the core draw/deal/play/collect flow from the web UI. |
| P1 | Accessible interaction model | Every card and table action is reachable by keyboard and touch, has an accessible name, visible focus/selection state, clear disabled/loading feedback, and an explicit face-up/down choice. |
| P1 | Table readability | Render safe card backs/counts and meaningful card art/labels, with responsive pan/zoom and a layout that remains usable on narrow screens. |
| P2 | Recovery and guidance | Add table list/resume, role/access explanation, reconnect/error recovery, action history, and useful empty states. |

## Required plan adjustments

1. **S17 — Setup and permissions UX:** add template/preset/deck selection, access and role explanation, capability-aware controls, and table resume/listing.
2. **S18 — Interaction completeness:** implement the missing card/pile actions and expose them through a keyboard/touch-friendly action menu with explicit face-up/down behavior.
3. **S19 — Responsive/accessibility pass:** add focus-visible styles, semantic controls, selection feedback, loading/error states, safe card backs/counts, meaningful labels/art, and browser acceptance coverage.

## Follow-up audit — 2026-09-22

The unauthenticated production entry page and sign-in form load at `https://decks.mmanir.pl/`; the sign-in form exposes labeled email/password fields, a keep-signed-in choice, and a password-recovery link. I could not complete sign-in in this pass. The browser safety policy rejected access to the local credentials file and forbids workarounds, so the password must be entered by the operator in the open sign-in form before authenticated acceptance can continue.

Source review at commit `e1ba1e1` shows the earlier setup improvements are implemented: preset/template selection, participant limit, role-aware join, and owned-table resume. The source adds an explicit card-selection mode, selected count, touch/pointer and keyboard selection, live selection feedback, group rotate/reveal/turn and front/back actions, and keeps drag/double-click flip separate from selection. Capability-aware controls and host participant grants also exist in source. The latest source exposes pile split, merge, collect-spread and label actions in a keyboard-accessible disclosure with expected versions and locked-target filtering; piles render on the shared surface with drag, directional move, rotate, and front/back controls. Hosts can start from the lobby, confirm ending an active session, and collect/reset independently of deck capability. These changes have not reached production: the active release remains `a70c82c42a9503a6` / commit `4e51b2d`, while deployer status is stale against the desired release. The attempted apply stopped before mutation because the required runtime-secret variables were unavailable.

The authenticated UX audit therefore remains incomplete. Next acceptance must verify login, create/configure/resume, draw and deal, select multiple visible cards and apply each group operation, populated pile workflows, start/end/reset, a second participant with spectator and denied-capability views, refresh/reconnect, and no leakage of private card identity. Source UI still needs loading/disabled states, meaningful participant names, an access/join policy surface if additional modes are intended, an action history/undo decision, and a deliberate pan/zoom strategy. Face-down backs/counts and useful labels are partially present; card art and visual zone editing remain future UX work. Keep the user-facing audit result provisional until these authenticated checks run against the current production release.

## Signed-in production follow-up — 2026-09-22

The operator completed sign-in through the labeled production form and reached the home page. The authenticated setup exposes a single standalone deck-template selector (the inspected account has one smoke template), preset selection, participant count, player/spectator choice when joining, and “Your tables” resume links. This verifies account entry and the basic authenticated route. The setup controls do not yet let a host choose multiple decks in one flow, configure a non-token access policy, or choose participant capabilities before creation.

The `Configured setup verification` table rendered its lobby/revision/card count, host identity, capability panel, pile controls, three named face-up cards and an empty private hand. Keyboard Enter changed one card to the safe `Face-down card` accessible name and advanced the session revision; a second Enter restored its visible `Smoke card` label. The card's prior visible state is restored. Immediately after the first mutation the connection status changed from `Connected` to `Realtime connection closed; retrying` and still showed the retry message 2.5 seconds later. This makes reconnect behavior the highest-priority UX defect from the live pass; add a repeatable mutation-driven reconnect test and fix any duplicate/stale-socket retry loop before declaring multi-user acceptance.

At the 1280×720 browser viewport, the landing title occupies roughly four large lines before the table controls. The tabletop itself reads clearly at this density, with cards and the empty hand separated, but the empty-hand hint has weak contrast and much of the setup/action panel is below the fold. The signed-in table list includes many similarly named or untitled verification rows and offers no search/filter, making resume harder to scan. Participants are displayed as generic “Player” labels rather than display names. The table gives a clear safe empty-state prompt and the card controls expose semantic keyboard names, while persistent action history, consistent pending/disabled feedback and useful reconnect recovery are still absent.

The live production source is older than the audited repository: all three OVH services run `4e51b2d` / `a70c82c42a9503a6`, while desired source is `b8a6981`; deployer reports stale/missing manifests, despite public `/health` returning `ok`. Newer host lifecycle, group-card and advanced pile controls are therefore source-only and were not represented in this browser run. Keep the interaction audit provisional until current source is deployed and the full flow is repeated.

### Updated UX priority

1. Resolve the live realtime disconnect/retry behavior and verify a second browser observes mutations and both participants reconnect cleanly.
2. Deploy the current audited source, then accept login, configured creation with multiple decks, resume/join, start/end/reset, draw/deal/play, populated pile workflows and host permissions.
3. Expose remaining supported server operations through an accessible action model; add safe action history, meaningful participant names, pending/disabled state and actionable errors.
4. Improve home list scanning and tabletop viewport ergonomics with search or grouping, a responsive pan/zoom strategy, and deliberate mobile/touch behavior; tune empty-state contrast and card backs/art.
5. Exercise keyboard and touch on narrow viewports, player/spectator privacy, denied capabilities, stale mutations and reconnect on the deployed release.

The authenticated pass verified login and one reversible keyboard card action only. It did not create new production sessions or send mail. No claim is made that the full current source has passed browser acceptance.

## Realtime finding response — 2026-09-22

The repeated reconnect state led to a source fix in commit `a0abcb8` (S08; `spec/05-sync.md`): ordinary rerenders no longer replace the socket; session changes invalidate old callbacks and retry timers; the UI marks Connected only after the active WebSocket opens. The regression contract is included in the 24/24 passing Python suite; TypeScript typecheck, Vite build and diff check pass. The production browser still runs the old release, so the result is not yet accepted in production. Re-run the mutation/reconnect and two-client checks after deployment.

## Multi-deck setup response — 2026-09-22

Commit `93e8d06` (S17; `spec/15-session-setup-and-capabilities.md`) replaces the single deck selector with repeatable, accessible template-version rows. Duplicate versions are permitted to represent multiple copies, and additional deck instances can accompany a preset's pinned deck. Creation submits setup actions in revision order, disables the create button while pending and preserves access to a partially configured session if an action fails. The current production release predates this change, so confirm its layout and multi-deck behavior after deployment.

## Recent-action history response — 2026-09-22

Commit `68e58cb` (S08; `spec/04-actions.md`) adds a compact recent-action list to the table. Entries use only generic actor labels, safe mapped descriptions, revisions and timestamps; unknown events fall back to a generic description, and history failure does not block table use. The deployed release predates this UI; verify its screen-reader behavior, useful ordering and private-event redaction after rollout.

## Current signed-in browser and source recheck — 2026-09-22

The in-app browser is still authenticated and displays the production `Configured setup verification` table (revision 20, three safe `Smoke card` table cards, two empty piles, host capability panel, empty-hand guidance). The live status reads `Realtime connection closed; retrying`. No state was changed during this recheck. The visible page is still the old production release, so it does not show the newest source's sanitized recent-action list or per-deck draw controls. A current deployer check confirms all three OVH services are stale/unhealthy against desired `a9d012e` / release `d6f739601e783bbf`; they remain on `4e51b2d` / `a70c82c42a9503a6`. Public `/health` returns `ok` but does not establish that the user-facing app is current or that realtime is connected.

The latest source improves the table information architecture by grouping actions under each safe deck label and count, with explicit top/bottom/N and destination controls. It also exposes recent activity without identifiers or private payloads, and the selection toolbar now returns selected cards to their server-resolved source decks without showing that association. Source validation is 27/27 Python contract tests, TypeScript typecheck, Vite build and clean diff check. There is still no authenticated browser acceptance for this source, and local PHP/PostgreSQL runtime validation was unavailable. The observed browser therefore confirms a signed-in route and table rendering, while rejecting a claim that the complete product currently works as intended.

## Signed-in live UX recheck — 2026-09-22

The in-app browser reopened the authenticated production home as `decks@mmanir.pl`, opened the existing `Configured setup verification` table, and rendered its three face-up cards, two empty pile panels, host capability area, and empty hand. The initial table state reported `Connected`. A keyboard Enter action flipped a card and advanced the revision from 28 to 29; the state persisted when the table was reopened. A second Enter restored the face-up card and advanced the revision to 30. After both mutations, status changed from `Connected` to `Realtime connection closed; retrying`; this reproduces the production realtime defect. The card face is restored. This is one-client smoke evidence only, not multi-user acceptance.

At the 1280×720 browser viewport, the tabletop/card presentation remains understandable and the three individual cards are visually distinct, but the empty-hand helper text is very low contrast against its light surface. The home still contains a long oversized heading and a crowded list with several duplicate or untitled test tables. The current production page has no per-deck draw panel, recent-action panel, source-deck return toolbar, or private-hand reorder/give/return controls, which confirms that the live UI is behind current source. Neither multi-deck setup nor narrow-width layout/touch behavior was exercised in this pass.

Source commit `1dc3085` now supplies owner-only hand ordering, accessible earlier/later buttons, private give-to-participant selection, and source-deck returns from the owner's hand. These are source-level improvements only; the production page cannot be used to accept them. The earlier socket lifecycle fix at `a0abcb8` likewise remains unverified live because production still reproduces retrying immediately after mutations. Contract suite and frontend build checks pass for the latest source, but PHP/runtime behavior and multi-client privacy remain untested.

### UX audit disposition

1. Release the current source through the trusted, secret-complete deploy setup, then repeat keyboard flip/restore while observing a stable connection and an independent second browser receiving the authorized update.
2. Test the actual current per-deck, action-history, source-return, and private-hand controls after deployment; include player/spectator privacy, capability and stale-state feedback. The new hand controls remove reorder/give/source-return from the remaining implementation backlog, not from acceptance testing.
3. Add clear private-hand reveal, selected move/align, remove/restore, pile-to-deck and remaining pile-operation affordances, plus host participant recovery controls. Hand-to-pile now exists in source and needs deployed acceptance. Make unavailable actions explain the permission or lock reason without leaking private details.
4. Improve the empty-hand contrast, table-list scanability (names/search/grouping or equivalent), long heading density, card back/art treatment, and narrow-screen pan/zoom. Complete keyboard and touch review at a narrow viewport.
5. Keep the UX result provisional until the exact deployed source has passed authenticated two-client browser acceptance; retain SMTP and backup readiness as distinct launch gates.

## Source response: hand-to-pile control — 2026-09-22

After the audit, commit `d99c0e9` added an accessible destination selector and button to the owner's selected-hand toolbar. It lists only unlocked piles by safe label and count, requires both card and pile capabilities, and sends exact card and pile versions. The control is not yet live: the production release predates it, and the current browser cannot exercise populated hands or verify its two-client behavior. Contract tests are 29/29, TypeScript typecheck and production build pass; PHP/runtime behavior remains unverified. Keep the control in deployment acceptance, not in the remaining source UX backlog.

## Source response: pile-to-deck merge controls — 2026-09-22

Following spec-first commit `88f1c34`, source commit `50b9650` adds accessible merge-to-deck controls for top, bottom, and server-randomized shuffle-in. Destination labels/counts are safe projections; controls require the pile/deck capability combination and submit exact pile/deck versions. Validation passes 30/30 Python contract tests, TypeScript typecheck, and Vite production build; `git diff --check` is clean. PHP/runtime and browser behavior are unverified, and production still needs deployment before the controls can be exercised. Remove pile-to-deck merge from the source UX backlog, but retain all three modes in deployed acceptance, including keyboard operation, lock/capability denial, stale state and privacy.

The remaining source-level UX backlog is selected private-hand reveal, public selected move/align and remove/restore, remaining pile reverse/flip/spread, participant recovery controls, zone editing/lock scopes, and the previously observed contrast, table-list density, card-art/back and narrow-viewport issues. The UX conclusion remains provisional until the exact deployed source passes authenticated two-client browser acceptance with keyboard and touch checks.

## Source interaction follow-up — 2026-09-23

The selected public-card movement/alignment controls (`fb6ea9a`), selected-card remove and host recovery controls (`ac1ad77`), and pile reverse/physical-flip/spread controls (`7bbdfbc`) close three previously listed source UX gaps. Directional group movement preserves relative positions and clamps at the table origin; left/top alignment requires a multi-card selection. Remove requires one selected card and confirmation. Restore rows are generic and host-only. Pile actions show explicit names and are disabled for empty or locked piles; spreading also explains the card-management permission requirement when unavailable. The 31/31 contract suite, TypeScript typecheck, and Vite build pass.

No browser session was run against these controls. The signed-in production browser still reflects an older release and previously reported `Realtime connection closed; retrying` immediately after each mutation. The current local environment lacks the documented deploy variables and deployer/PHP commands, so this change is not live UX evidence. Pile mutation controls are shown only to participants with pile-management capability on unlocked piles; empty-pile actions that need cards are disabled.

Keep these three features in exact-release acceptance. Remaining UX work is participant-management and zone affordances where required by the business scope, clearer home/table-list scanning, empty-hand contrast, table width/space use, meaningful backs/art, responsive pan/zoom and touch behavior, plus accessible keyboard/screen-reader review of the current source in a second-client session. The UX conclusion remains provisional.

## Live production UX audit — 2026-09-22

At the browser's 1280×720 viewport, sign-in worked from the already populated Decks account form. The authenticated home places an oversized multi-line heading above the create/join controls, pushing the core forms below the first screen. The table list includes many duplicate or untitled verification tables, which makes finding a real session difficult. The home does expose clear field labels, a role selector, and an invitation route.

In `Configured setup verification`, the green table surface and three cards are visually distinct and the cards have accessible names/help text; Enter flips a focused Smoke card. The empty-hand message is extremely low contrast on its light panel. The table view uses a narrow centered column with substantial unused space and vertical scrolling. Basic pile buttons are available, but this deployed UI does not expose the newer per-deck, history, private-hand, source-return, hand-to-pile or pile-to-deck controls in current source. No second-browser, narrow viewport, touch, or populated-hand/pile UX was tested.

Keyboard flip and restore are usable and persisted (revision 30→31→32), but each mutation immediately left the visible status in `Realtime connection closed; retrying` for at least three seconds; reopening the table recovered `Connected`. This is a high-priority interaction/recovery defect for the live release. The smoke card is restored face up. No other durable changes were made.

### UX disposition

Prioritize the release mismatch and mutation-triggered realtime failure before broadening visual polish. Once the current source is actually served, accept its added controls in the browser and check their disabled/error states, keyboard reachability, hidden-data wording, and screen-reader labels. Separately improve the oversized home heading and table-list scanning, empty-hand contrast, table width/space use, meaningful card-back/art, and narrow-screen pan/zoom. The result remains **provisional**: only one authenticated browser at a desktop-sized viewport was checked, and the tested source-level controls are not visible in production.

## Host participant administration follow-up — 2026-09-23

Spec-first updates in `spec/15-session-setup-and-capabilities.md` (`d2fd63c`, `4c711f7`) define a host-only generic participant panel. Source commit `03b8de6` adds confirmation-gated host transfer/removal, generic restoration rows with a Player/Spectator role choice, and keeps capability choices in the same panel. It never renders account emails or raw IDs; only the authorized host receives opaque recovery action targets. Host transfer resets explicit overrides so the new host retains its recovery controls. The panel is hidden after session end.

The source interaction passed 32/32 contract tests, TypeScript typecheck, production asset build and diff check. It has not been exercised in a browser or two-account session. Production remains behind source and the current shell lacks the trusted deploy configuration/toolchain; keep all participant operations in deployment acceptance and the UX result provisional.

## Visual zone editor follow-up — 2026-09-23

The committed `spec/08-presets-zones.md` contract now defines host-only lobby editing. Source commit `8678fbf` displays zones as named rectangles behind cards/piles and lets the host add, edit, and confirm deletion of zones using labeled inputs for geometry, priority and effect. Starting a session removes the editor and the server rejects any zone mutation after the lobby. The control passes 33/33 contract tests, TypeScript, Vite build and diff check.

This interface has not been visually tested in a browser. Keep the overlay legibility, scrolling/overlap behavior, mobile fields, screen-reader labels, zone precedence and reset behavior in browser acceptance. Production remains on an older release and the current environment still lacks deployment access, so the UX audit remains provisional.
