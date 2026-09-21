<?php

declare(strict_types=1);

namespace Decks;

use PDO;

final class RateLimiter
{
    public const LOGIN_WINDOW_SECONDS = 900;
    public const LOGIN_IP_LIMIT = 30;
    public const LOGIN_EMAIL_LIMIT = 10;
    public const RESET_WINDOW_SECONDS = 3600;
    public const RESET_IP_LIMIT = 20;
    public const RESET_EMAIL_LIMIT = 5;

    public static function consume(PDO $database, string $action, string $subject, int $limit, int $windowSeconds): bool
    {
        if ($limit < 1 || $windowSeconds < 1) return false;
        $window = time() - (time() % $windowSeconds);
        $hash = self::subjectHash($subject);
        $statement = $database->prepare(
            'INSERT INTO request_rate_limits(action, subject_hash, window_start, attempt_count)
             VALUES (:action, :subject_hash, to_timestamp(:window_start), 1)
             ON CONFLICT (action, subject_hash, window_start)
             DO UPDATE SET attempt_count = request_rate_limits.attempt_count + 1
             RETURNING attempt_count',
        );
        $statement->execute([
            'action' => $action,
            'subject_hash' => $hash,
            'window_start' => $window,
        ]);
        $count = (int) $statement->fetchColumn();
        if (random_int(1, 100) === 1) {
            $database->exec("DELETE FROM request_rate_limits WHERE window_start < now() - interval '2 days'");
        }
        return $count <= $limit;
    }

    public static function clear(PDO $database, string $action, string $subject): void
    {
        $database->prepare('DELETE FROM request_rate_limits WHERE action = :action AND subject_hash = :subject_hash')
            ->execute(['action' => $action, 'subject_hash' => self::subjectHash($subject)]);
    }

    private static function subjectHash(string $subject): string
    {
        $key = getenv('DECKS_RATE_LIMIT_KEY') ?: (getenv('DECKS_APP_KEY') ?: 'decks-development-rate-limit-key');
        return hash_hmac('sha256', $subject, $key);
    }
}
