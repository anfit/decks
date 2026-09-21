<?php

declare(strict_types=1);

namespace Decks;

use PDO;
use RuntimeException;

final class Security
{
    public static function normalizeEmail(string $email): string
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || strlen($normalized) > 254 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('A valid email address is required.');
        }
        return $normalized;
    }

    public static function passwordIsValid(string $password): bool
    {
        return mb_strlen($password, 'UTF-8') >= 16 && strlen($password) <= 4096;
    }

    public static function authenticate(PDO $database, string $email, string $password): ?array
    {
        $statement = $database->prepare('SELECT * FROM app_user WHERE lower(email) = lower(:email) AND enabled = true');
        $statement->execute(['email' => self::normalizeEmail($email)]);
        $user = $statement->fetch();
        if (!is_array($user) || !is_string($user['password_hash'] ?? null) ||
            !password_verify($password, $user['password_hash'])) {
            return null;
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $database->prepare('UPDATE app_user SET password_hash = :hash, updated_at = now() WHERE id = :id');
            $rehash->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
        }
        $database->prepare('UPDATE app_user SET last_login_at = now(), updated_at = now() WHERE id = :id')
            ->execute(['id' => $user['id']]);
        return self::publicUser($user);
    }

    public static function currentUser(PDO $database, ?array $session = null): ?array
    {
        $session ??= $_SESSION;
        $id = $session['user_id'] ?? null;
        $observedVersion = $session['security_version'] ?? null;
        if (!is_string($id) && !is_int($id)) {
            return null;
        }
        $statement = $database->prepare('SELECT * FROM app_user WHERE id = :id AND enabled = true');
        $statement->execute(['id' => (string) $id]);
        $user = $statement->fetch();
        if (!is_array($user) || ($observedVersion !== null && (int) $observedVersion !== (int) $user['security_version'])) {
            unset($_SESSION['user_id'], $_SESSION['security_version']);
            return null;
        }
        return self::publicUser($user);
    }

    public static function publicUser(array $user): array
    {
        return [
            'id' => (string) $user['id'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'security_version' => (int) $user['security_version'],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function listUsers(PDO $database): array
    {
        return $database->query(
            'SELECT id, email, role, enabled, invitation_credits, last_login_at, created_at, updated_at
             FROM app_user ORDER BY lower(email), id',
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function accountRecord(PDO $database, string $userId): ?array
    {
        $statement = $database->prepare(
            'SELECT id, email, role, enabled, invitation_credits, last_login_at, created_at, updated_at
             FROM app_user WHERE id = :id',
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public static function changePassword(PDO $database, array $actor, string $currentPassword, string $password, string $confirmation): void
    {
        if ($password !== $confirmation) throw new RuntimeException('Passwords do not match.');
        if (!self::passwordIsValid($password)) throw new RuntimeException('Password must contain at least 16 characters and at most 4096 bytes.');
        $database->beginTransaction();
        try {
            $statement = $database->prepare('SELECT * FROM app_user WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $actor['id']]);
            $user = $statement->fetch();
            if (!is_array($user) || !$user['enabled'] || !password_verify($currentPassword, (string) $user['password_hash'])) {
                throw new RuntimeException('Current password is incorrect.');
            }
            $database->prepare(
                'UPDATE app_user SET password_hash = :hash, security_version = security_version + 1, updated_at = now() WHERE id = :id',
            )->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
            $database->prepare('DELETE FROM remember_tokens WHERE user_id = :id')->execute(['id' => $user['id']]);
            $database->prepare('DELETE FROM password_reset_tokens WHERE user_id = :id AND used_at IS NULL')->execute(['id' => $user['id']]);
            self::audit($database, (string) $actor['id'], 'account.password_changed', 'app_user', (string) $user['id']);
            $database->commit();
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function signOutEverywhere(PDO $database, array $actor): void
    {
        $database->beginTransaction();
        try {
            $statement = $database->prepare('SELECT id FROM app_user WHERE id = :id AND enabled = true FOR UPDATE');
            $statement->execute(['id' => $actor['id']]);
            if (!$statement->fetch()) throw new RuntimeException('Account is unavailable.');
            self::revokeAuthentication($database, (string) $actor['id']);
            self::audit($database, (string) $actor['id'], 'account.signed_out_everywhere', 'app_user', (string) $actor['id']);
            $database->commit();
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function setRole(PDO $database, array $actor, string $userId, string $role): void
    {
        self::requireCapability($actor, 'users.manage');
        if (!in_array($role, ['member', 'admin'], true)) throw new RuntimeException('Invalid account role.');
        $database->beginTransaction();
        try {
            $user = self::lockedUser($database, $userId);
            if ($user['enabled'] && $user['role'] === 'admin' && $role !== 'admin') {
                self::protectFinalAdmin($database, $userId);
            }
            if ($user['role'] !== $role) {
                $database->prepare('UPDATE app_user SET role = :role, updated_at = now() WHERE id = :id')
                    ->execute(['role' => $role, 'id' => $userId]);
                self::audit($database, (string) $actor['id'], 'account.role_changed', 'app_user', $userId, ['role' => $role]);
            }
            $database->commit();
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function setEnabled(PDO $database, array $actor, string $userId, bool $enabled): void
    {
        self::requireCapability($actor, 'users.manage');
        $database->beginTransaction();
        try {
            $user = self::lockedUser($database, $userId);
            if (!$enabled && $user['enabled'] && $user['role'] === 'admin') {
                self::protectFinalAdmin($database, $userId);
            }
            if ((bool) $user['enabled'] !== $enabled) {
                $database->prepare('UPDATE app_user SET enabled = :enabled, updated_at = now() WHERE id = :id')
                    ->execute(['enabled' => $enabled, 'id' => $userId]);
                if (!$enabled) self::revokeAuthentication($database, $userId);
                self::audit($database, (string) $actor['id'], $enabled ? 'account.enabled' : 'account.disabled', 'app_user', $userId);
            }
            $database->commit();
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function requireCapability(?array $user, string $capability): void
    {
        if ($user === null) {
            throw new RuntimeException('Authentication required.');
        }
        $allowed = match ($capability) {
            'users.invite' => true,
            'users.manage' => ($user['role'] ?? '') === 'admin',
            default => false,
        };
        if (!$allowed) {
            throw new RuntimeException('Permission denied.');
        }
    }

    public static function audit(PDO $database, ?string $actor, string $action, ?string $entityType = null, ?string $entityId = null, array $details = []): void
    {
        $statement = $database->prepare(
            'INSERT INTO audit_log(actor_user_id, source, action, entity_type, entity_id, details)
             VALUES (:actor, :source, :action, :entity_type, :entity_id, CAST(:details AS jsonb))',
        );
        $statement->execute([
            'actor' => $actor,
            'source' => 'web',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }

    public static function revokeAuthentication(PDO $database, string $userId): void
    {
        $database->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id')->execute(['user_id' => $userId]);
        $database->prepare('UPDATE app_user SET security_version = security_version + 1, updated_at = now() WHERE id = :id')
            ->execute(['id' => $userId]);
    }

    /** @return array<string,mixed> */
    private static function lockedUser(PDO $database, string $userId): array
    {
        $statement = $database->prepare('SELECT * FROM app_user WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();
        if (!is_array($user)) throw new RuntimeException('Account not found.');
        return $user;
    }

    private static function protectFinalAdmin(PDO $database, string $userId): void
    {
        $statement = $database->query("SELECT id FROM app_user WHERE enabled = true AND role = 'admin' FOR UPDATE");
        $ids = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        if (count(array_diff($ids, [$userId])) < 1) {
            throw new RuntimeException('The final enabled administrator cannot be disabled or demoted.');
        }
    }
}
