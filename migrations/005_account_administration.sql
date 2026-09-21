ALTER TABLE user_invitations
    ADD COLUMN credit_restored_at timestamptz,
    ADD COLUMN credit_restored_by uuid REFERENCES app_user(id) ON DELETE SET NULL,
    ADD COLUMN credit_consumed boolean NOT NULL DEFAULT false;

ALTER TABLE email_outbox
    ADD COLUMN user_invitation_id uuid REFERENCES user_invitations(id) ON DELETE SET NULL;

CREATE INDEX email_outbox_invitation_idx ON email_outbox(user_invitation_id, id)
    WHERE user_invitation_id IS NOT NULL;

CREATE TABLE request_rate_limits (
    action text NOT NULL,
    subject_hash text NOT NULL,
    window_start timestamptz NOT NULL,
    attempt_count integer NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
    PRIMARY KEY (action, subject_hash, window_start)
);

CREATE INDEX request_rate_limits_prune_idx ON request_rate_limits(window_start);
