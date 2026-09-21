<?php

declare(strict_types=1);

namespace Decks;

use RuntimeException;

final class SmtpMailer
{
    public static function send(string $recipient, string $subject, string $body): void
    {
        $host = env_required('DECKS_SMTP_HOST');
        $port = (int) (getenv('DECKS_SMTP_PORT') ?: '465');
        $security = strtolower(getenv('DECKS_SMTP_SECURITY') ?: 'ssl');
        $username = getenv('DECKS_SMTP_USERNAME') ?: '';
        $password = getenv('DECKS_SMTP_PASSWORD') ?: '';
        $from = getenv('DECKS_MAIL_FROM') ?: '';
        if (!preg_match('/<([^<>\s]+)>/', $from, $fromMatch)) $from = trim($from);
        else $from = $fromMatch[1];
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SMTP sender or recipient is invalid.');
        }
        $transport = $security === 'ssl' ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
        $socket = @stream_socket_client($transport, $errno, $error, 15, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) throw new RuntimeException("SMTP connection failed: {$error}");
        stream_set_timeout($socket, 15);
        try {
            self::expect($socket, [220]);
            self::command($socket, 'EHLO decks.mmanir.pl', [250]);
            if ($security === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP STARTTLS negotiation failed.');
                }
                self::command($socket, 'EHLO decks.mmanir.pl', [250]);
            } elseif (!in_array($security, ['ssl', 'none'], true)) {
                throw new RuntimeException('DECKS_SMTP_SECURITY must be ssl, tls or none.');
            }
            if ($username !== '') {
                self::command($socket, 'AUTH LOGIN', [334]);
                self::command($socket, base64_encode($username), [334]);
                self::command($socket, base64_encode($password), [235]);
            }
            self::command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            self::command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            self::command($socket, 'DATA', [354]);
            $safeSubject = str_replace(["\r", "\n"], '', $subject);
            $safeBody = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body));
            $message = "From: {$from}\r\nTo: {$recipient}\r\nSubject: {$safeSubject}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $safeBody . "\r\n.";
            fwrite($socket, $message . "\r\n");
            self::expect($socket, [250]);
            self::command($socket, 'QUIT', [221, 250]);
        } finally {
            fclose($socket);
        }
    }

    private static function command($socket, string $command, array $codes): void
    {
        fwrite($socket, $command . "\r\n");
        self::expect($socket, $codes);
    }

    private static function expect($socket, array $codes): void
    {
        $line = fgets($socket);
        if ($line === false || !preg_match('/^(\d{3})/', $line, $match) || !in_array((int) $match[1], $codes, true)) {
            throw new RuntimeException('SMTP server rejected a command.');
        }
        while (strlen($line) >= 4 && $line[3] === '-') {
            $line = fgets($socket);
            if ($line === false) throw new RuntimeException('SMTP server closed the connection.');
        }
    }
}
