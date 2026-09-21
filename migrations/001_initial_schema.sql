CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE app_user (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    email text NOT NULL,
    password_hash text,
    role text NOT NULL DEFAULT 'member' CHECK (role IN ('member', 'admin')),
    enabled boolean NOT NULL DEFAULT true,
    invitation_credits integer NOT NULL DEFAULT 1 CHECK (invitation_credits >= 0),
    security_version integer NOT NULL DEFAULT 1 CHECK (security_version > 0),
    last_login_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX app_user_email_lower_uq ON app_user (lower(email));

CREATE TABLE assets (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_user_id uuid REFERENCES app_user(id) ON DELETE SET NULL,
    content_hash text NOT NULL,
    storage_key text NOT NULL UNIQUE,
    mime_type text NOT NULL,
    byte_size bigint NOT NULL CHECK (byte_size > 0),
    width integer NOT NULL CHECK (width > 0),
    height integer NOT NULL CHECK (height > 0),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX assets_content_hash_uq ON assets (content_hash);

CREATE TABLE deck_templates (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_user_id uuid NOT NULL REFERENCES app_user(id),
    name text NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 160),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE deck_template_versions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    template_id uuid NOT NULL REFERENCES deck_templates(id) ON DELETE RESTRICT,
    version integer NOT NULL CHECK (version > 0),
    default_back_asset_id uuid REFERENCES assets(id) ON DELETE RESTRICT,
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (template_id, version),
    UNIQUE (template_id, id)
);

CREATE TABLE card_definitions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    template_version_id uuid NOT NULL REFERENCES deck_template_versions(id) ON DELETE RESTRICT,
    ordinal integer NOT NULL CHECK (ordinal >= 0),
    display_name text,
    front_asset_id uuid NOT NULL REFERENCES assets(id) ON DELETE RESTRICT,
    back_asset_id uuid REFERENCES assets(id) ON DELETE RESTRICT,
    quantity integer NOT NULL DEFAULT 1 CHECK (quantity > 0 AND quantity <= 10000),
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    UNIQUE (template_version_id, ordinal),
    UNIQUE (template_version_id, id)
);

CREATE TABLE sessions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    title text CHECK (title IS NULL OR length(trim(title)) BETWEEN 1 AND 200),
    status text NOT NULL DEFAULT 'lobby' CHECK (status IN ('lobby', 'active', 'ended')),
    host_user_id uuid NOT NULL REFERENCES app_user(id),
    template_version_id uuid REFERENCES deck_template_versions(id) ON DELETE RESTRICT,
    initial_state jsonb NOT NULL DEFAULT '{}'::jsonb,
    access_settings jsonb NOT NULL DEFAULT '{}'::jsonb,
    revision bigint NOT NULL DEFAULT 0 CHECK (revision >= 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    last_activity_at timestamptz NOT NULL DEFAULT now(),
    ended_at timestamptz
);

CREATE TABLE session_participants (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    session_id uuid NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    user_id uuid NOT NULL REFERENCES app_user(id) ON DELETE RESTRICT,
    role text NOT NULL CHECK (role IN ('host', 'player', 'spectator')),
    capabilities jsonb NOT NULL DEFAULT '{}'::jsonb,
    seat integer,
    removed_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (session_id, id),
    UNIQUE (session_id, user_id)
);

ALTER TABLE sessions
    ADD CONSTRAINT sessions_host_participant_fk
    FOREIGN KEY (id, host_user_id) REFERENCES session_participants(session_id, user_id)
    DEFERRABLE INITIALLY DEFERRED;

CREATE TABLE session_decks (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    session_id uuid NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    template_version_id uuid NOT NULL REFERENCES deck_template_versions(id) ON DELETE RESTRICT,
    label text,
    version bigint NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (session_id, id)
);

CREATE TABLE session_piles (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    session_id uuid NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    label text,
    x numeric NOT NULL DEFAULT 0,
    y numeric NOT NULL DEFAULT 0,
    rotation numeric NOT NULL DEFAULT 0,
    z_index integer NOT NULL DEFAULT 0,
    locked_by uuid REFERENCES app_user(id) ON DELETE SET NULL,
    version bigint NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (session_id, id)
);

CREATE TABLE session_hands (
    session_id uuid NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    participant_id uuid NOT NULL,
    label text,
    version bigint NOT NULL DEFAULT 1 CHECK (version > 0),
    PRIMARY KEY (session_id, participant_id),
    FOREIGN KEY (session_id, participant_id) REFERENCES session_participants(session_id, id) ON DELETE CASCADE
);

CREATE TABLE session_cards (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    session_id uuid NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    card_definition_id uuid NOT NULL REFERENCES card_definitions(id) ON DELETE RESTRICT,
    source_deck_id uuid NOT NULL,
    location_type text NOT NULL CHECK (location_type IN ('deck', 'pile', 'hand', 'table', 'removed')),
    deck_id uuid,
    pile_id uuid,
    hand_participant_id uuid,
    order_key bigint,
    x numeric,
    y numeric,
    rotation numeric NOT NULL DEFAULT 0,
    z_index integer NOT NULL DEFAULT 0,
    face_state text NOT NULL DEFAULT 'down' CHECK (face_state IN ('up', 'down', 'private')),
    owner_user_id uuid REFERENCES app_user(id) ON DELETE SET NULL,
    locked_by uuid REFERENCES app_user(id) ON DELETE SET NULL,
    version bigint NOT NULL DEFAULT 1 CHECK (version > 0),
    CONSTRAINT card_location_shape CHECK (
        (location_type = 'deck' AND deck_id IS NOT NULL AND pile_id IS NULL AND hand_participant_id IS NULL AND order_key IS NOT NULL AND x IS NULL AND y IS NULL)
        OR (location_type = 'pile' AND deck_id IS NULL AND pile_id IS NOT NULL AND hand_participant_id IS NULL AND order_key IS NOT NULL AND x IS NULL AND y IS NULL)
        OR (location_type = 'hand' AND deck_id IS NULL AND pile_id IS NULL AND hand_participant_id IS NOT NULL AND order_key IS NOT NULL AND x IS NULL AND y IS NULL)
        OR (location_type = 'table' AND deck_id IS NULL AND pile_id IS NULL AND hand_participant_id IS NULL AND order_key IS NULL AND x IS NOT NULL AND y IS NOT NULL)
        OR (location_type = 'removed' AND deck_id IS NULL AND pile_id IS NULL AND hand_participant_id IS NULL AND order_key IS NULL AND x IS NULL AND y IS NULL)
    ),
    FOREIGN KEY (session_id, source_deck_id) REFERENCES session_decks(session_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (session_id, deck_id) REFERENCES session_decks(session_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (session_id, pile_id) REFERENCES session_piles(session_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (session_id, hand_participant_id) REFERENCES session_hands(session_id, participant_id) ON DELETE RESTRICT
);
CREATE UNIQUE INDEX session_cards_deck_order_uq ON session_cards(session_id, deck_id, order_key) WHERE location_type = 'deck';
CREATE UNIQUE INDEX session_cards_pile_order_uq ON session_cards(session_id, pile_id, order_key) WHERE location_type = 'pile';
CREATE UNIQUE INDEX session_cards_hand_order_uq ON session_cards(session_id, hand_participant_id, order_key) WHERE location_type = 'hand';
CREATE INDEX session_cards_session_location_idx ON session_cards(session_id, location_type);

CREATE TABLE session_zones (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    session_id uuid NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    name text NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 160),
    geometry jsonb NOT NULL,
    priority integer NOT NULL DEFAULT 0,
    behavior jsonb NOT NULL DEFAULT '{}'::jsonb,
    owner_user_id uuid REFERENCES app_user(id) ON DELETE SET NULL,
    locked boolean NOT NULL DEFAULT false,
    UNIQUE (session_id, id)
);

CREATE TABLE session_events (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    session_id uuid NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    revision bigint NOT NULL CHECK (revision > 0),
    actor_user_id uuid REFERENCES app_user(id) ON DELETE SET NULL,
    action_type text NOT NULL,
    public_payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (session_id, revision)
);
CREATE INDEX session_events_session_revision_idx ON session_events(session_id, revision);

CREATE TABLE processed_actions (
    session_id uuid NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    actor_user_id uuid NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    action_id uuid NOT NULL,
    request_hash text NOT NULL,
    revision bigint,
    status text NOT NULL CHECK (status IN ('accepted', 'rejected')),
    result jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (session_id, actor_user_id, action_id)
);

CREATE TABLE audit_log (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    actor_user_id uuid REFERENCES app_user(id) ON DELETE SET NULL,
    source text NOT NULL CHECK (source IN ('web', 'system', 'mail')),
    action text NOT NULL,
    entity_type text,
    entity_id uuid,
    details jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE remember_tokens (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id uuid NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    selector text NOT NULL UNIQUE,
    secret_hash text NOT NULL,
    expires_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    last_used_at timestamptz
);

CREATE TABLE user_invitations (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    inviter_user_id uuid REFERENCES app_user(id) ON DELETE SET NULL,
    email text NOT NULL,
    selector text NOT NULL UNIQUE,
    secret_hash text NOT NULL,
    expires_at timestamptz NOT NULL,
    accepted_at timestamptz,
    rescinded_at timestamptz,
    active boolean NOT NULL DEFAULT true,
    delivery_state text NOT NULL DEFAULT 'pending' CHECK (delivery_state IN ('pending', 'queued', 'sent', 'failed', 'expired', 'rescinded')),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX user_invitations_active_email_uq ON user_invitations(lower(email))
    WHERE accepted_at IS NULL AND rescinded_at IS NULL AND active;

CREATE TABLE password_reset_tokens (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id uuid NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    selector text NOT NULL UNIQUE,
    secret_hash text NOT NULL,
    expires_at timestamptz NOT NULL,
    used_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE email_outbox (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind text NOT NULL CHECK (kind IN ('account_invitation', 'password_reset')),
    recipient text NOT NULL,
    subject text NOT NULL,
    body text NOT NULL,
    available_at timestamptz NOT NULL DEFAULT now(),
    claimed_at timestamptz,
    sent_at timestamptz,
    attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    last_error text,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX email_outbox_pending_idx ON email_outbox(available_at, id) WHERE sent_at IS NULL;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version text PRIMARY KEY,
    checksum text NOT NULL,
    applied_at timestamptz NOT NULL DEFAULT now()
);
