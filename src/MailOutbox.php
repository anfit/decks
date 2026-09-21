<?php

declare(strict_types=1);

namespace Decks;

use PDO;

final class MailOutbox
{
    public static function enqueue(PDO $database, string $kind, string $recipient, string $subject, string $body): void
    {
        $statement = $database->prepare(
            'INSERT INTO email_outbox(kind, recipient, subject, body) VALUES (:kind, :recipient, :subject, :body)',
        );
        $statement->execute(compact('kind', 'recipient', 'subject', 'body'));
    }
}
