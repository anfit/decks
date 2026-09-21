<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Decks\Security;

$email = getenv('DECKS_ADMIN_EMAIL');
$password = getenv('DECKS_ADMIN_PASSWORD');
if (!is_string($email) || !is_string($password) || $email === '' || $password === '') {
    fwrite(STDERR, "Set DECKS_ADMIN_EMAIL and DECKS_ADMIN_PASSWORD in the private operator environment.\n");
    exit(2);
}
if (!Security::passwordIsValid($password)) {
    fwrite(STDERR, "DECKS_ADMIN_PASSWORD must contain at least 16 Unicode characters and at most 4096 bytes.\n");
    exit(2);
}

$database = Decks\database();
$database->beginTransaction();
try {
    $email = Security::normalizeEmail($email);
    $statement = $database->prepare('SELECT id FROM app_user WHERE lower(email) = lower(:email) FOR UPDATE');
    $statement->execute(['email' => $email]);
    if ($statement->fetch()) throw new RuntimeException('An account already exists for this address.');
    $insert = $database->prepare("INSERT INTO app_user(email, password_hash, role, invitation_credits) VALUES (:email, :hash, 'admin', 0)");
    $insert->execute(['email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
    Security::audit($database, null, 'account.first_admin_bootstrapped', 'app_user', null, ['email' => $email]);
    $database->commit();
    fwrite(STDOUT, "Created the first Decks administrator.\n");
} catch (Throwable $error) {
    if ($database->inTransaction()) $database->rollBack();
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
