# Decks project instructions

## Source of truth

Read the relevant committed files under `spec/` before changing behavior. The ignored `tmp/decks-implementation-plan.md` is the execution roadmap and may record progress, but it is not a release artifact or a tracked specification.

When a behavior or interface is new or changes, update the smallest applicable spec and commit it before the dependent implementation. Implementation commits should cite the step and spec path.

## Architecture constraints

- PHP/PostgreSQL own durable state and actions.
- Browser and Node WebSocket state are disposable; Node never implements card business rules.
- Use a request-scoped principal/PDO and service-level authorization, following the applicable Scholion conventions documented in `spec/11-users-invitations-mail.md`.
- Keep hidden card identities, ordering and protected asset paths out of unauthorized projections, logs and transient messages.
- Use explicit PostgreSQL transactions and constraints for invariants. Do not add a framework or shared runtime package without a committed architecture decision.

## Validation

Run the narrowest meaningful checks for each change, then the relevant integration/browser checks. Do not claim production readiness from a visual demo alone. Never commit secrets, generated dependencies, local credentials, or contents of `tmp/`.
