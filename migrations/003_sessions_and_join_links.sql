ALTER TABLE sessions
    ADD COLUMN join_selector text,
    ADD COLUMN join_secret_hash text,
    ADD COLUMN join_expires_at timestamptz,
    ADD COLUMN max_participants integer NOT NULL DEFAULT 12 CHECK (max_participants BETWEEN 1 AND 100),
    ADD COLUMN frozen_at timestamptz;

CREATE UNIQUE INDEX sessions_join_selector_uq ON sessions(join_selector) WHERE join_selector IS NOT NULL;
