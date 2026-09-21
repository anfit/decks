<?php

declare(strict_types=1);

namespace Decks;

use RuntimeException;

/** Short-lived, audience-scoped credential for the separate WebSocket process. */
final class RealtimeTicket
{
    public static function issue(string $sessionId, array $user, int $ttl = 300): string
    {
        $key = getenv('DECKS_REALTIME_SIGNING_KEY');
        if (!is_string($key) || strlen($key) < 32) throw new RuntimeException('Realtime signing is not configured.');
        $payload = [
            'session_id' => $sessionId,
            'user_id' => (string) ($user['id'] ?? ''),
            'security_version' => (int) ($user['security_version'] ?? 0),
            'exp' => time() + max(30, min($ttl, 600)),
            'nonce' => bin2hex(random_bytes(12)),
        ];
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $sessionId) || $payload['user_id'] === '') throw new RuntimeException('Invalid realtime audience.');
        $body = self::encode(json_encode($payload, JSON_THROW_ON_ERROR));
        return $body . '.' . self::encode(hash_hmac('sha256', $body, $key, true));
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
