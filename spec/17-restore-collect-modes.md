# Restore and collect modes

Status: S18 implementation slice, 2026-09-22.

Host restoration of a removed card accepts `position: top|bottom|shuffle` and inserts it into its immutable source deck atomically. `collect_all` accepts `mode: original|shuffle|preserve`; original follows template ordinals, shuffle applies fresh server randomness per source deck, and preserve keeps the current card ordering as far as the source/container grouping permits. All modes clear spatial/private ownership state, remove piles and advance the session revision through the normal action envelope.

Reset continues to restore the captured lobby configuration and may request a fresh server shuffle. Hidden card identities and random outcomes remain excluded from public event payloads.
