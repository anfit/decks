<?php

declare(strict_types=1);

namespace Decks;

use PDO;

final class PasswordResetService
{
    public static function request(PDO $database, string $email, string $baseUrl, int $hours = 1): void
    {
        $email = Security::normalizeEmail($email);
        $database->beginTransaction();
        try {
            $statement = $database->prepare('SELECT id, enabled FROM app_user WHERE lower(email) = lower(:email) FOR UPDATE');
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();
            if (is_array($user) && $user['enabled']) {
                $database->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id AND used_at IS NULL')
                    ->execute(['user_id' => $user['id']]);
                $token = Token::issue();
                $insert = $database->prepare(
                    "INSERT INTO password_reset_tokens(user_id, selector, secret_hash, expires_at)
                     VALUES (:user_id, :selector, :secret_hash, now() + (:hours || ' hours')::interval)
                     RETURNING id",
                );
                $insert->execute(['user_id' => $user['id'], 'selector' => $token['selector'], 'secret_hash' => $token['hash'], 'hours' => $hours]);
                $row = $insert->fetch();
                $link = rtrim($baseUrl, '/') . '/reset-password?token=' . rawurlencode($token['value']);
                MailOutbox::enqueue($database, 'password_reset', $email, 'Reset your Decks password',
                    "Reset your Decks password: {$link}\n\nThis link expires in {$hours} hour(s).");
                Security::audit($database, null, 'account.password_reset_requested', 'password_reset', (string) $row['id']);
            }
            $database->commit();
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function requestForUser(PDO $database, array $actor, string $userId, string $baseUrl, int $hours = 1): void
    {
        Security::requireCapability($actor, 'users.manage');
        $database->beginTransaction();
        try {
            $statement = $database->prepare('SELECT id, email, enabled FROM app_user WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $userId]);
            $user = $statement->fetch();
            if (!is_array($user) || !$user['enabled']) throw new \RuntimeException('Enabled account is required.');
            $database->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id AND used_at IS NULL')
                ->execute(['user_id' => $user['id']]);
            $token = Token::issue();
            $insert = $database->prepare(
                "INSERT INTO password_reset_tokens(user_id, selector, secret_hash, expires_at)
                 VALUES (:user_id, :selector, :secret_hash, now() + (:hours || ' hours')::interval)
                 RETURNING id",
            );
            $insert->execute(['user_id' => $user['id'], 'selector' => $token['selector'], 'secret_hash' => $token['hash'], 'hours' => $hours]);
            $row = $insert->fetch();
            $link = rtrim($baseUrl, '/') . '/reset-password?token=' . rawurlencode($token['value']);
            MailOutbox::enqueue($database, 'password_reset', (string) $user['email'], 'Reset your Decks password',
                "Reset your Decks password: {$link}\n\nThis link expires in {$hours} hour(s).");
            Security::audit($database, (string) $actor['id'], 'account.password_reset_requested_by_admin', 'password_reset', (string) $row['id'], ['user_id' => (string) $user['id']]);
            $database->commit();
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function consume(PDO $database, string $value, string $password): array
    {
        $parts = Token::split($value);
        if ($parts === null || !Security::passwordIsValid($password)) {
            throw new \RuntimeException('This reset link is invalid or expired.');
        }
        $database->beginTransaction();
        try {
            $statement = $database->prepare(
                'SELECT r.*, u.email, u.enabled FROM password_reset_tokens r JOIN app_user u ON u.id = r.user_id
                 WHERE r.selector = :selector AND r.expires_at > now() FOR UPDATE',
            );
            $statement->execute(['selector' => $parts['selector']]);
            $row = $statement->fetch();
            if (!is_array($row) || !$row['enabled'] || $row['used_at'] !== null ||
                !Token::matches($parts['secret'], (string) $row['secret_hash'])) {
                throw new \RuntimeException('This reset link is invalid or expired.');
            }
            $database->prepare('UPDATE app_user SET password_hash = :hash, security_version = security_version + 1, updated_at = now() WHERE id = :id')
                ->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $row['user_id']]);
            $database->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id')->execute(['user_id' => $row['user_id']]);
            $database->prepare('UPDATE password_reset_tokens SET used_at = now() WHERE id = :id')->execute(['id' => $row['id']]);
            Security::audit($database, (string) $row['user_id'], 'account.password_reset_completed', 'app_user', (string) $row['user_id']);
            $database->commit();
            return ['id' => (string) $row['user_id'], 'email' => (string) $row['email']];
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }
}
