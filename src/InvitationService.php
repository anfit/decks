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
                "INSERT INTO user_invitations(inviter_user_id, email, selector, secret_hash, expires_at, delivery_state)
                 VALUES (:inviter, :email, :selector, :secret_hash, now() + (:days || ' days')::interval, 'queued')
                 RETURNING id, expires_at",
            );
            $insert->execute([
                'inviter' => $actor['id'], 'email' => $email, 'selector' => $token['selector'],
                'secret_hash' => $token['hash'], 'days' => $days,
            ]);
            $row = $insert->fetch();
            if ($user['role'] !== 'admin') {
                $database->prepare('UPDATE app_user SET invitation_credits = invitation_credits - 1, updated_at = now() WHERE id = :id')
                    ->execute(['id' => $actor['id']]);
            }
            $link = rtrim($baseUrl, '/') . '/accept-invitation?token=' . rawurlencode($token['value']);
            MailOutbox::enqueue($database, 'account_invitation', $email, 'You are invited to Decks',
                "You have been invited to Decks. Accept this invitation: {$link}\n\nThis link expires in {$days} days.");
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
            Security::audit($database, null, 'account.invitation_accepted', 'user_invitation', (string) $invite['id'], ['email' => $invite['email']]);
            $database->commit();
            return ['id' => (string) $user['id'], 'email' => (string) $invite['email'], 'security_version' => (int) $user['security_version']];
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }
}
