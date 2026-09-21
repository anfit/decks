ALTER TABLE user_invitations DROP CONSTRAINT user_invitations_delivery_state_check;
ALTER TABLE user_invitations ADD CONSTRAINT user_invitations_delivery_state_check
    CHECK (delivery_state IN ('pending', 'queued', 'sent', 'failed', 'expired', 'rescinded', 'accepted'));
