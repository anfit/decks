<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Decks\SmtpMailer;

$loop = in_array('--loop', $argv, true);
$database = Decks\database();
$maxAttempts = 8;

do {
    $database->beginTransaction();
    try {
        $claim = $database->query(
            "SELECT id, recipient, subject, body, attempts FROM email_outbox
             WHERE sent_at IS NULL AND available_at <= now() AND attempts < {$maxAttempts}
             ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 1",
        )->fetch();
        if (!is_array($claim)) {
            $database->commit();
            if (!$loop) break;
            sleep(5);
            continue;
        }
        $database->prepare('UPDATE email_outbox SET claimed_at = now(), attempts = attempts + 1 WHERE id = :id')
            ->execute(['id' => $claim['id']]);
        $database->commit();
        try {
            SmtpMailer::send((string) $claim['recipient'], (string) $claim['subject'], (string) $claim['body']);
            $database->prepare("UPDATE email_outbox SET sent_at = now(), claimed_at = NULL, body = '[redacted]', last_error = NULL WHERE id = :id")
                ->execute(['id' => $claim['id']]);
        } catch (Throwable $error) {
            $attempt = (int) $claim['attempts'] + 1;
            $delay = min(86400, 30 * (2 ** min($attempt, 10)));
            $message = substr(preg_replace('/[\r\n]+/', ' ', $error->getMessage()) ?: 'delivery failed', 0, 500);
            $database->prepare('UPDATE email_outbox SET claimed_at = NULL, available_at = now() + (:delay || \' seconds\')::interval, last_error = :error WHERE id = :id')
                ->execute(['delay' => $delay, 'error' => $message, 'id' => $claim['id']]);
            fwrite(STDERR, "Mail delivery failed for outbox row {$claim['id']} (attempt {$attempt}).\n");
        }
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->rollBack();
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
} while ($loop);
