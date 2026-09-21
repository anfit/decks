<?php

declare(strict_types=1);

namespace Decks;

final class Token
{
    /** @return array{selector: string, secret: string, value: string, hash: string} */
    public static function issue(): array
    {
        $selector = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        return [
            'selector' => $selector,
            'secret' => $secret,
            'value' => $selector . '.' . $secret,
            'hash' => hash('sha256', $secret),
        ];
    }

    public static function split(string $value): ?array
    {
        $parts = explode('.', $value, 2);
        if (count($parts) !== 2 || !ctype_xdigit($parts[0]) || !ctype_xdigit($parts[1]) ||
            strlen($parts[0]) !== 32 || strlen($parts[1]) !== 64) {
            return null;
        }
        return ['selector' => $parts[0], 'secret' => $parts[1]];
    }

    public static function matches(string $secret, string $hash): bool
    {
        return hash_equals($hash, hash('sha256', $secret));
    }
}
