<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use function Decks\env_required;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'status' => 'ok',
        'service' => 'decks',
        'environment' => getenv('DECKS_ENV') ?: 'development',
    ], JSON_THROW_ON_ERROR);
    exit;
}

if (str_starts_with($path, '/api/')) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'not_found'], JSON_THROW_ON_ERROR);
    exit;
}

$assetManifest = dirname(__DIR__) . '/public/assets-build/.vite/manifest.json';
$entry = null;
if (is_file($assetManifest)) {
    $manifest = json_decode((string) file_get_contents($assetManifest), true, 512, JSON_THROW_ON_ERROR);
    $entry = $manifest['src/main.ts']['file'] ?? null;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Decks</title>
  </head>
  <body>
    <?php if (is_string($entry)): ?>
      <script type="module" src="/assets-build/<?= htmlspecialchars($entry, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></script>
    <?php else: ?>
      <main><h1>Decks</h1><p>Frontend assets are not built yet.</p></main>
    <?php endif; ?>
  </body>
</html>
