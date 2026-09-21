<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use PDO;

$directory = dirname(__DIR__) . '/migrations';
$files = glob($directory . '/*.sql') ?: [];
sort($files, SORT_STRING);
$pdo = \Decks\database();
$pdo->beginTransaction();
try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version text PRIMARY KEY, checksum text NOT NULL, applied_at timestamptz NOT NULL DEFAULT now())');
    $applied = $pdo->query('SELECT version, checksum FROM schema_migrations FOR UPDATE')->fetchAll();
    $known = [];
    foreach ($applied as $row) {
        $known[(string) $row['version']] = (string) $row['checksum'];
    }
    foreach ($files as $file) {
        $version = basename($file, '.sql');
        $sql = (string) file_get_contents($file);
        $checksum = hash('sha256', $sql);
        if (isset($known[$version])) {
            if (!hash_equals($known[$version], $checksum)) {
                throw new RuntimeException("Migration checksum changed: {$version}");
            }
            continue;
        }
        $pdo->exec($sql);
        $statement = $pdo->prepare('INSERT INTO schema_migrations(version, checksum) VALUES (:version, :checksum)');
        $statement->execute(['version' => $version, 'checksum' => $checksum]);
        fwrite(STDOUT, "Applied {$version}\n");
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
