<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Decks\Security;

$email = getenv('DECKS_ADMIN_EMAIL');
$password = getenv('DECKS_ADMIN_PASSWORD');
$passwordHash = getenv('DECKS_ADMIN_PASSWORD_HASH');
if (!is_string($email) || $email === '' || ((!is_string($password) || $password === '') && (!is_string($passwordHash) || $passwordHash === ''))) {
    fwrite(STDERR, "Set DECKS_ADMIN_EMAIL and either DECKS_ADMIN_PASSWORD or DECKS_ADMIN_PASSWORD_HASH in the private operator environment.\n");
    exit(2);
}
if (is_string($passwordHash) && $passwordHash !== '') {
    if (password_get_info($passwordHash)['algo'] === 0) {
        fwrite(STDERR, "DECKS_ADMIN_PASSWORD_HASH must be a supported PHP password hash.\n");
        exit(2);
    }
    $storedPasswordHash = $passwordHash;
} elseif (!is_string($password) || !Security::passwordIsValid($password)) {
    fwrite(STDERR, "DECKS_ADMIN_PASSWORD must contain at least 16 Unicode characters and at most 4096 bytes.\n");
    exit(2);
} else {
    $storedPasswordHash = password_hash($password, PASSWORD_DEFAULT);
}

$database = Decks\database();
$database->beginTransaction();
try {
    $email = Security::normalizeEmail($email);
    $statement = $database->prepare('SELECT id FROM app_user WHERE lower(email) = lower(:email) FOR UPDATE');
    $statement->execute(['email' => $email]);
    if ($statement->fetch()) throw new RuntimeException('An account already exists for this address.');
    $insert = $database->prepare("INSERT INTO app_user(email, password_hash, role, invitation_credits) VALUES (:email, :hash, 'admin', 0)");
    $insert->execute(['email' => $email, 'hash' => $storedPasswordHash]);
    Security::audit($database, null, 'account.first_admin_bootstrapped', 'app_user', null, ['email' => $email]);
    $database->commit();
    fwrite(STDOUT, "Created the first Decks administrator.\n");
} catch (Throwable $error) {
    if ($database->inTransaction()) $database->rollBack();
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
