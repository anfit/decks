ALTER TABLE sessions
    ADD COLUMN interaction_frozen boolean NOT NULL DEFAULT false;
