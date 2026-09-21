CREATE TABLE mat_versions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_user_id uuid NOT NULL REFERENCES app_user(id) ON DELETE RESTRICT,
    name text NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 160),
    background_asset_id uuid REFERENCES assets(id) ON DELETE RESTRICT,
    width integer CHECK (width IS NULL OR width BETWEEN 1 AND 10000),
    height integer CHECK (height IS NULL OR height BETWEEN 1 AND 10000),
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX mat_versions_owner_created_idx ON mat_versions(owner_user_id, created_at DESC);

CREATE TABLE table_presets (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_user_id uuid NOT NULL REFERENCES app_user(id) ON DELETE RESTRICT,
    name text NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 160),
    template_version_id uuid NOT NULL REFERENCES deck_template_versions(id) ON DELETE RESTRICT,
    mat_version_id uuid REFERENCES mat_versions(id) ON DELETE RESTRICT,
    configuration jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX table_presets_owner_created_idx ON table_presets(owner_user_id, created_at DESC);

ALTER TABLE sessions
    ADD COLUMN IF NOT EXISTS preset_id uuid REFERENCES table_presets(id) ON DELETE RESTRICT;
