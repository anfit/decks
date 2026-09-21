<?php

declare(strict_types=1);

namespace Decks;

use PDO;

final class RememberMe
{
    public static function revoke(PDO $database, string $value): void
    {
        $parts = Token::split($value);
        if ($parts === null) return;
        $database->prepare('DELETE FROM remember_tokens WHERE selector = :selector')->execute(['selector' => $parts['selector']]);
    }

    public static function issue(PDO $database, string $userId, int $days = 60): string
    {
        $token = Token::issue();
        $statement = $database->prepare(
            'INSERT INTO remember_tokens(user_id, selector, secret_hash, expires_at)
             VALUES (:user_id, :selector, :secret_hash, now() + (:days || \' days\')::interval)',
        );
        $statement->execute([
            'user_id' => $userId,
            'selector' => $token['selector'],
            'secret_hash' => $token['hash'],
            'days' => $days,
        ]);
        return $token['value'];
    }

    /** @return array{user_id: string, security_version: int, token: string}|null */
    public static function restore(PDO $database, string $value, int $days = 60): ?array
    {
        $parts = Token::split($value);
        if ($parts === null) return null;
        $database->beginTransaction();
        try {
            $statement = $database->prepare(
                'SELECT r.*, u.enabled, u.security_version FROM remember_tokens r
                 JOIN app_user u ON u.id = r.user_id WHERE r.selector = :selector AND r.expires_at > now() FOR UPDATE',
            );
            $statement->execute(['selector' => $parts['selector']]);
            $row = $statement->fetch();
            if (!is_array($row) || !$row['enabled'] ||
                !Token::matches($parts['secret'], (string) $row['secret_hash'])) {
                if (is_array($row)) {
                    $database->prepare('DELETE FROM remember_tokens WHERE id = :id')->execute(['id' => $row['id']]);
                }
                $database->commit();
                return null;
            }
            $replacement = Token::issue();
            $database->prepare(
                'UPDATE remember_tokens SET selector = :selector, secret_hash = :secret_hash,
                 expires_at = now() + (:days || \' days\')::interval, last_used_at = now() WHERE id = :id',
            )->execute([
                'selector' => $replacement['selector'], 'secret_hash' => $replacement['hash'],
                'days' => $days, 'id' => $row['id'],
            ]);
            $database->commit();
            return ['user_id' => (string) $row['user_id'], 'security_version' => (int) $row['security_version'], 'token' => $replacement['value']];
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }
}
