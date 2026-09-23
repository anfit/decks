CREATE TABLE asset_owners (
    asset_id uuid NOT NULL REFERENCES assets(id) ON DELETE CASCADE,
    user_id uuid NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (asset_id, user_id)
);

INSERT INTO asset_owners(asset_id, user_id)
SELECT id, owner_user_id
FROM assets
WHERE owner_user_id IS NOT NULL
ON CONFLICT DO NOTHING;

CREATE INDEX asset_owners_user_asset_idx ON asset_owners(user_id, asset_id);
