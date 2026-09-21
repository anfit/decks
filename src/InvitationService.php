<?php

declare(strict_types=1);

namespace Decks;

use PDO;
use RuntimeException;

final class InvitationService
{
    public static function create(PDO $database, array $actor, string $email, string $baseUrl, int $days = 7): array
    {
        Security::requireCapability($actor, 'users.invite');
        $email = Security::normalizeEmail($email);
        $token = Token::issue();
        $database->beginTransaction();
        try {
            $lock = $database->prepare('SELECT pg_advisory_xact_lock(hashtext(:key))');
            $lock->execute(['key' => 'deck-account-invite:' . $email]);
            $database->prepare(
                "UPDATE user_invitations SET active = false, delivery_state = 'expired'
                 WHERE lower(email) = lower(:email) AND active = true AND expires_at <= now()",
            )->execute(['email' => $email]);
            $existing = $database->prepare(
                'SELECT id, delivery_state FROM user_invitations
                 WHERE lower(email) = lower(:email) AND accepted_at IS NULL AND rescinded_at IS NULL AND active = true
                   AND expires_at > now() FOR UPDATE',
            );
            $existing->execute(['email' => $email]);
            if ($existing->fetch()) {
                throw new RuntimeException('An active invitation already exists for this address.');
            }
            $userStatement = $database->prepare('SELECT id, role, invitation_credits FROM app_user WHERE id = :id FOR UPDATE');
            $userStatement->execute(['id' => $actor['id']]);
            $user = $userStatement->fetch();
            if (!is_array($user)) throw new RuntimeException('Inviter account not found.');
            if ($user['role'] !== 'admin' && (int) $user['invitation_credits'] < 1) {
                throw new RuntimeException('No account invitation credits remain.');
            }
            $insert = $database->prepare(
                "INSERT INTO user_invitations(inviter_user_id, email, selector, secret_hash, expires_at, delivery_state, credit_consumed)
                 VALUES (:inviter, :email, :selector, :secret_hash, now() + (:days || ' days')::interval, 'queued', :credit_consumed)
                 RETURNING id, expires_at",
            );
            $insert->execute([
                'inviter' => $actor['id'], 'email' => $email, 'selector' => $token['selector'],
                'secret_hash' => $token['hash'], 'days' => $days, 'credit_consumed' => $user['role'] === 'admin' ? 'false' : 'true',
            ]);
            $row = $insert->fetch();
            if ($user['role'] !== 'admin') {
                $database->prepare('UPDATE app_user SET invitation_credits = invitation_credits - 1, updated_at = now() WHERE id = :id')
                    ->execute(['id' => $actor['id']]);
            }
            $link = rtrim($baseUrl, '/') . '/accept-invitation?token=' . rawurlencode($token['value']);
            MailOutbox::enqueue($database, 'account_invitation', $email, 'You are invited to Decks',
                "You have been invited to Decks. Accept this invitation: {$link}\n\nThis link expires in {$days} days.", (string) $row['id']);
            Security::audit($database, (string) $actor['id'], 'account.invitation_created', 'user_invitation', (string) $row['id'], ['email' => $email]);
            $database->commit();
            return ['id' => (string) $row['id'], 'email' => $email, 'expires_at' => (string) $row['expires_at']];
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function accept(PDO $database, string $value, string $password): array
    {
        $parts = Token::split($value);
        if ($parts === null || !Security::passwordIsValid($password)) {
            throw new RuntimeException('This invitation is invalid or expired.');
        }
        $database->beginTransaction();
        try {
            $statement = $database->prepare('SELECT * FROM user_invitations WHERE selector = :selector AND expires_at > now() FOR UPDATE');
            $statement->execute(['selector' => $parts['selector']]);
            $invite = $statement->fetch();
            if (!is_array($invite) || !$invite['active'] || $invite['accepted_at'] !== null ||
                $invite['rescinded_at'] !== null ||
                !Token::matches($parts['secret'], (string) $invite['secret_hash'])) {
                throw new RuntimeException('This invitation is invalid or expired.');
            }
            $existing = $database->prepare('SELECT id FROM app_user WHERE lower(email) = lower(:email) FOR UPDATE');
            $existing->execute(['email' => $invite['email']]);
            if ($existing->fetch()) throw new RuntimeException('An account already exists for this address.');
            $insert = $database->prepare(
                "INSERT INTO app_user(email, password_hash, role, invitation_credits)
                 VALUES (:email, :password_hash, 'member', 1) RETURNING id, security_version",
            );
            $insert->execute(['email' => $invite['email'], 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $user = $insert->fetch();
            $database->prepare("UPDATE user_invitations SET accepted_at = now(), active = false, delivery_state = 'accepted' WHERE id = :id")
                ->execute(['id' => $invite['id']]);
            self::cancelPendingMail($database, (string) $invite['id'], 'Invitation accepted.');
            Security::audit($database, null, 'account.invitation_accepted', 'user_invitation', (string) $invite['id'], ['email' => $invite['email']]);
            $database->commit();
            return ['id' => (string) $user['id'], 'email' => (string) $invite['email'], 'security_version' => (int) $user['security_version']];
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public static function listIssued(PDO $database, string $userId): array
    {
        $statement = $database->prepare(
            "SELECT id, email, expires_at, accepted_at, rescinded_at, delivery_state, credit_consumed, credit_restored_at,
                    CASE WHEN accepted_at IS NOT NULL THEN 'accepted'
                         WHEN rescinded_at IS NOT NULL THEN 'rescinded'
                         WHEN expires_at <= now() THEN 'expired'
                         ELSE 'pending' END AS status
             FROM user_invitations WHERE inviter_user_id = :user_id ORDER BY created_at DESC, id DESC",
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public static function listAll(PDO $database): array
    {
        return $database->query(
            "SELECT i.id, i.email, i.expires_at, i.accepted_at, i.rescinded_at, i.delivery_state,
                    i.credit_consumed, i.credit_restored_at, i.created_at,
                    u.email AS inviter_email,
                    CASE WHEN i.accepted_at IS NOT NULL THEN 'accepted'
                         WHEN i.rescinded_at IS NOT NULL THEN 'rescinded'
                         WHEN i.expires_at <= now() THEN 'expired'
                         ELSE 'pending' END AS status
             FROM user_invitations i LEFT JOIN app_user u ON u.id = i.inviter_user_id
             ORDER BY i.created_at DESC, i.id DESC",
        )->fetchAll();
    }

    public static function resend(PDO $database, array $actor, string $invitationId, string $baseUrl, int $days = 7): array
    {
        Security::requireCapability($actor, 'users.manage');
        $token = Token::issue();
        $database->beginTransaction();
        try {
            $invite = self::lockedInvitation($database, $invitationId);
            if (!self::pending($invite)) {
                $database->rollBack();
                return ['status' => 'unchanged'];
            }
            self::cancelPendingMail($database, $invitationId, 'Superseded by resend.');
            $database->prepare(
                "UPDATE user_invitations SET selector = :selector, secret_hash = :secret_hash,
                 expires_at = now() + (:days || ' days')::interval, active = true, delivery_state = 'queued'
                 WHERE id = :id",
            )->execute(['selector' => $token['selector'], 'secret_hash' => $token['hash'], 'days' => $days, 'id' => $invitationId]);
            $link = rtrim($baseUrl, '/') . '/accept-invitation?token=' . rawurlencode($token['value']);
            MailOutbox::enqueue($database, 'account_invitation', (string) $invite['email'], 'You are invited to Decks',
                "You have been invited to Decks. Accept this invitation: {$link}\n\nThis link expires in {$days} days.", $invitationId);
            Security::audit($database, (string) $actor['id'], 'account.invitation_resent', 'user_invitation', $invitationId, ['email' => (string) $invite['email']]);
            $database->commit();
            return ['status' => 'resent'];
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function rescind(PDO $database, array $actor, string $invitationId): array
    {
        Security::requireCapability($actor, 'users.invite');
        $database->beginTransaction();
        try {
            $invite = self::lockedInvitation($database, $invitationId);
            if (($actor['role'] ?? '') !== 'admin' && (string) $invite['inviter_user_id'] !== (string) $actor['id']) {
                throw new RuntimeException('Permission denied.');
            }
            if (!self::pending($invite)) {
                $database->rollBack();
                return ['status' => 'unchanged'];
            }
            $inviter = $database->prepare('SELECT id, role FROM app_user WHERE id = :id FOR UPDATE');
            $inviter->execute(['id' => $invite['inviter_user_id']]);
            $inviterRow = $inviter->fetch();
            $restore = is_array($inviterRow) && $invite['credit_consumed'] && $invite['credit_restored_at'] === null;
            if ($restore) {
                $database->prepare('UPDATE app_user SET invitation_credits = invitation_credits + 1, updated_at = now() WHERE id = :id')
                    ->execute(['id' => $invite['inviter_user_id']]);
            }
            $database->prepare(
                'UPDATE user_invitations SET active = false, rescinded_at = now(), delivery_state = \'rescinded\',
                 credit_restored_at = CASE WHEN CAST(:restore AS boolean) THEN now() ELSE credit_restored_at END,
                 credit_restored_by = CASE WHEN CAST(:restore AS boolean) THEN :actor ELSE credit_restored_by END WHERE id = :id',
            )->execute(['restore' => $restore ? 'true' : 'false', 'actor' => $actor['id'], 'id' => $invitationId]);
            self::cancelPendingMail($database, $invitationId, 'Invitation rescinded.');
            Security::audit($database, (string) $actor['id'], 'account.invitation_rescinded', 'user_invitation', $invitationId, ['email' => (string) $invite['email']]);
            if ($restore) Security::audit($database, (string) $actor['id'], 'account.invitation_credit_restored', 'user_invitation', $invitationId, ['inviter_user_id' => (string) $invite['inviter_user_id']]);
            $database->commit();
            return ['status' => 'rescinded', 'credit_restored' => $restore];
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function restoreCredit(PDO $database, array $actor, string $invitationId): array
    {
        Security::requireCapability($actor, 'users.manage');
        $database->beginTransaction();
        try {
            $invite = self::lockedInvitation($database, $invitationId);
            $eligible = $invite['credit_consumed'] && $invite['credit_restored_at'] === null && $invite['accepted_at'] === null &&
                ($invite['rescinded_at'] !== null || strtotime((string) $invite['expires_at']) <= time() || $invite['delivery_state'] === 'failed');
            if (!$eligible) {
                $database->rollBack();
                return ['status' => 'unchanged'];
            }
            $inviter = $database->prepare('SELECT id, role FROM app_user WHERE id = :id FOR UPDATE');
            $inviter->execute(['id' => $invite['inviter_user_id']]);
            $inviterRow = $inviter->fetch();
            if (!is_array($inviterRow)) {
                $database->rollBack();
                return ['status' => 'unchanged'];
            }
            $database->prepare('UPDATE app_user SET invitation_credits = invitation_credits + 1, updated_at = now() WHERE id = :id')
                ->execute(['id' => $invite['inviter_user_id']]);
            $database->prepare('UPDATE user_invitations SET credit_restored_at = now(), credit_restored_by = :actor WHERE id = :id')
                ->execute(['actor' => $actor['id'], 'id' => $invitationId]);
            Security::audit($database, (string) $actor['id'], 'account.invitation_credit_restored', 'user_invitation', $invitationId, ['inviter_user_id' => (string) $invite['inviter_user_id']]);
            $database->commit();
            return ['status' => 'restored'];
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private static function lockedInvitation(PDO $database, string $id): array
    {
        $statement = $database->prepare('SELECT * FROM user_invitations WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $invite = $statement->fetch();
        if (!is_array($invite)) throw new RuntimeException('Invitation not found.');
        return $invite;
    }

    private static function pending(array $invite): bool
    {
        return (bool) $invite['active'] && $invite['accepted_at'] === null && $invite['rescinded_at'] === null && strtotime((string) $invite['expires_at']) > time();
    }

    private static function cancelPendingMail(PDO $database, string $invitationId, string $reason): void
    {
        $database->prepare(
            "UPDATE email_outbox SET claimed_at = NULL, last_error = :reason, body = '[redacted]'
             WHERE user_invitation_id = :invitation_id AND sent_at IS NULL AND claimed_at IS NULL",
        )->execute(['reason' => $reason, 'invitation_id' => $invitationId]);
    }
}
