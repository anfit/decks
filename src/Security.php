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
}
