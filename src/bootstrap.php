<?php

declare(strict_types=1);

namespace Decks;

use PDO;
use RuntimeException;

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require_once $file;
});

/**
 * Establish the small request scope shared by the web entry point.
 * Domain services will be added behind this boundary as implementation grows.
 */
function env_required(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException("Missing required environment variable: {$name}");
    }
    return $value;
}

function database(): PDO
{
    static $database;
    if ($database instanceof PDO) {
        return $database;
    }

    $host = getenv('DECKS_DB_HOST') ?: '127.0.0.1';
    $port = getenv('DECKS_DB_PORT') ?: '5432';
    $name = env_required('DECKS_DB_NAME');
    $user = env_required('DECKS_DB_USER');
    $password = env_required('DECKS_DB_PASSWORD');
    $database = new PDO(
        "pgsql:host={$host};port={$port};dbname={$name}",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    return $database;
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('decks');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Strict',
        'path' => '/',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_secure_session();
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(?string $token): void
{
    start_secure_session();
    $known = $_SESSION['csrf_token'] ?? null;
    if (!is_string($known) || !is_string($token) || !hash_equals($known, $token)) {
        throw new RuntimeException('Invalid request token.');
    }
}
