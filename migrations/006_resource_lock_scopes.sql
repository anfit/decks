ALTER TABLE sessions
    ADD COLUMN locked_by uuid REFERENCES app_user(id) ON DELETE SET NULL;

ALTER TABLE session_decks
    ADD COLUMN locked_by uuid REFERENCES app_user(id) ON DELETE SET NULL;

ALTER TABLE session_zones
    ADD COLUMN locked_by uuid REFERENCES app_user(id) ON DELETE SET NULL;

UPDATE session_zones
SET locked_by = owner_user_id
WHERE locked = true AND owner_user_id IS NOT NULL;

UPDATE session_zones SET locked = false WHERE locked_by IS NULL;
