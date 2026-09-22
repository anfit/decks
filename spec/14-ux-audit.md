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
