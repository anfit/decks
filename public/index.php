<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Decks\InvitationService;
use Decks\ActionService;
use Decks\AssetService;
use Decks\PasswordResetService;
use Decks\RememberMe;
use Decks\Security;
use Decks\SessionService;
use Decks\TemplateService;
use function Decks\env_required;
use function Decks\csrf_token;
use function Decks\database;
use function Decks\require_csrf;
use function Decks\start_secure_session;

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

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function page(string $title, string $body): never
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h($title) . '</title><style>body{font-family:system-ui,sans-serif;max-width:42rem;margin:3rem auto;padding:0 1rem;color:#1e2933}form{display:grid;gap:.75rem;max-width:28rem}input,button{font:inherit;padding:.65rem}button{background:#1459a6;color:white;border:0;border-radius:.35rem}.error{color:#9e2b2b}</style></head><body>' . $body . '</body></html>';
    exit;
}

function redirect_to(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function remember_cookie_name(): string
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '__Host-decks_remember' : 'decks_remember';
}

function set_remember_cookie(string $value): void
{
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie(remember_cookie_name(), $value, ['expires' => time() + 60 * 60 * 24 * 60, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
}

if (in_array($path, ['/login', '/accept-invitation', '/request-password-reset', '/reset-password', '/logout', '/account/invite'], true)) {
    start_secure_session();
    $database = database();
    if (!isset($_SESSION['user_id']) && isset($_COOKIE[remember_cookie_name()])) {
        $restored = RememberMe::restore($database, (string) $_COOKIE[remember_cookie_name()]);
        if ($restored !== null) {
            $_SESSION['user_id'] = $restored['user_id'];
            $_SESSION['security_version'] = $restored['security_version'];
            set_remember_cookie($restored['token']);
        }
    }
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $error = null;
    if ($path === '/logout' && $method === 'POST') {
        require_csrf($_POST['csrf_token'] ?? null);
        if (isset($_COOKIE[remember_cookie_name()])) RememberMe::revoke($database, (string) $_COOKIE[remember_cookie_name()]);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $parameters['path'], $parameters['domain'] ?? '', (bool) $parameters['secure'], (bool) $parameters['httponly']);
        }
        session_destroy();
        redirect_to('/');
    }
    if ($path === '/login') {
        if ($method === 'POST') {
            try {
                require_csrf($_POST['csrf_token'] ?? null);
                $user = Security::authenticate($database, (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
                if ($user === null) throw new RuntimeException('Email or password is incorrect.');
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['security_version'] = $user['security_version'];
                if (isset($_POST['remember'])) {
                    $remember = RememberMe::issue($database, $user['id']);
                    set_remember_cookie($remember);
                }
                redirect_to('/');
            } catch (Throwable $exception) { $error = $exception->getMessage(); }
        }
        $message = $error ? '<p class="error">' . h($error) . '</p>' : '';
        page('Sign in · Decks', '<h1>Sign in</h1>' . $message . '<form method="post"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><label>Email<input name="email" type="email" autocomplete="username" required></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><label><input name="remember" type="checkbox" checked> Keep me signed in</label><button>Sign in</button></form><p><a href="/request-password-reset">Forgot your password?</a></p>');
    }
    if ($path === '/accept-invitation') {
        $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
        if ($method === 'POST') {
            try {
                require_csrf($_POST['csrf_token'] ?? null);
                $created = InvitationService::accept($database, $token, (string) ($_POST['password'] ?? ''));
                session_regenerate_id(true);
                $_SESSION['user_id'] = $created['id'];
                $_SESSION['security_version'] = $created['security_version'];
                redirect_to('/');
            } catch (Throwable $exception) { $error = $exception->getMessage(); }
        }
        $message = $error ? '<p class="error">' . h($error) . '</p>' : '';
        page('Accept invitation · Decks', '<h1>Accept your Decks invitation</h1>' . $message . '<form method="post"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="token" value="' . h($token) . '"><label>Choose a password<input name="password" type="password" autocomplete="new-password" minlength="16" required></label><button>Create account</button></form>');
    }
    if ($path === '/request-password-reset') {
        if ($method === 'POST') {
            try {
                require_csrf($_POST['csrf_token'] ?? null);
                PasswordResetService::request($database, (string) ($_POST['email'] ?? ''), getenv('DECKS_PUBLIC_BASE_URL') ?: 'http://127.0.0.1:5240');
            } catch (Throwable $exception) { /* Deliberately generic to prevent account enumeration. */ }
            page('Check your email · Decks', '<h1>Check your email</h1><p>If an enabled account matches that address, a reset link will arrive shortly.</p>');
        }
        page('Reset password · Decks', '<h1>Reset your password</h1><form method="post"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><label>Email<input name="email" type="email" autocomplete="email" required></label><button>Send reset link</button></form>');
    }
    if ($path === '/reset-password') {
        $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
        if ($method === 'POST') {
            try {
                require_csrf($_POST['csrf_token'] ?? null);
                PasswordResetService::consume($database, $token, (string) ($_POST['password'] ?? ''));
                redirect_to('/login');
            } catch (Throwable $exception) { $error = $exception->getMessage(); }
        }
        $message = $error ? '<p class="error">' . h($error) . '</p>' : '';
        page('Choose a new password · Decks', '<h1>Choose a new password</h1>' . $message . '<form method="post"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="token" value="' . h($token) . '"><label>New password<input name="password" type="password" autocomplete="new-password" minlength="16" required></label><button>Save password</button></form>');
    }
    if ($path === '/account/invite') {
        $user = Security::currentUser($database);
        if ($user === null) redirect_to('/login?next=%2Faccount%2Finvite');
        if ($method === 'POST') {
            try {
                require_csrf($_POST['csrf_token'] ?? null);
                InvitationService::create($database, $user, (string) ($_POST['email'] ?? ''), getenv('DECKS_PUBLIC_BASE_URL') ?: 'http://127.0.0.1:5240');
                page('Invitation sent · Decks', '<h1>Invitation queued</h1><p>The invitation has been queued for delivery.</p><p><a href="/account/invite">Invite another person</a></p>');
            } catch (Throwable $exception) { $error = $exception->getMessage(); }
        }
        $message = $error ? '<p class="error">' . h($error) . '</p>' : '';
        page('Invite someone · Decks', '<h1>Invite someone to Decks</h1>' . $message . '<form method="post"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><label>Email<input name="email" type="email" autocomplete="email" required></label><button>Queue invitation</button></form>');
    }
}

if (preg_match('#^/protected-assets/([0-9a-fA-F-]{36})$#', $path, $matches)) {
    start_secure_session();
    $database = database();
    $user = Security::currentUser($database);
    if ($user === null) { http_response_code(404); exit; }
    try {
        $asset = AssetService::pathForOwner($database, $user, $matches[1]);
        header('Content-Type: ' . $asset['mime_type']);
        header('Cache-Control: private, no-store');
        $prefix = getenv('DECKS_ASSET_HANDOFF_PREFIX');
        if (is_string($prefix) && $prefix !== '') {
            header('X-Accel-Redirect: ' . rtrim($prefix, '/') . '/' . basename($asset['storage_key']));
            exit;
        }
        readfile($asset['path']);
        exit;
    } catch (Throwable) { http_response_code(404); exit; }
}

if (str_starts_with($path, '/api/')) {
    start_secure_session();
    $database = database();
    $user = Security::currentUser($database);
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    /** @param array<string,mixed> $body */
    function json_response(array $body, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_THROW_ON_ERROR);
        exit;
    }

    function json_body(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    if ($path === '/api/me' && $method === 'GET') {
        if ($user === null) json_response(['error' => 'authentication_required'], 401);
        json_response(['user' => $user]);
    }
    if ($user === null) json_response(['error' => 'authentication_required'], 401);
    try {
        if ($method === 'POST') require_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if ($path === '/api/assets' && $method === 'POST') {
            if (!isset($_FILES['asset']) || !is_array($_FILES['asset'])) json_response(['error' => 'asset_required'], 400);
            json_response(['asset' => AssetService::storeUpload($database, $user, $_FILES['asset'])], 201);
        }
        if ($path === '/api/templates' && $method === 'POST') {
            $body = json_body();
            json_response(['template' => TemplateService::create($database, $user, (string) ($body['name'] ?? ''))], 201);
        }
        if (preg_match('#^/api/templates/([0-9a-fA-F-]{36})/versions$#', $path, $matches) && $method === 'POST') {
            $body = json_body();
            json_response(['version' => TemplateService::createVersion($database, $user, $matches[1], is_array($body['definitions'] ?? null) ? $body['definitions'] : [])], 201);
        }
        if ($path === '/api/sessions' && $method === 'POST') {
            $body = json_body();
            $created = SessionService::create($database, $user, isset($body['title']) ? (string) $body['title'] : null, isset($body['max_participants']) ? (int) $body['max_participants'] : 12);
            json_response(['session' => $created], 201);
        }
        if (preg_match('#^/api/sessions/([0-9a-fA-F-]{36})/join$#', $path, $matches) && $method === 'POST') {
            $body = json_body();
            $joined = SessionService::join($database, $user, (string) ($body['token'] ?? ''), (string) ($body['role'] ?? 'player'));
            json_response(['membership' => $joined], 201);
        }
        if (preg_match('#^/api/sessions/([0-9a-fA-F-]{36})/state$#', $path, $matches) && $method === 'GET') {
            json_response(['state' => ActionService::snapshot($database, $matches[1], (string) $user['id'])]);
        }
        if (preg_match('#^/api/sessions/([0-9a-fA-F-]{36})/changes$#', $path, $matches) && $method === 'GET') {
            $after = filter_var($_GET['after'] ?? 0, FILTER_VALIDATE_INT);
            if ($after === false || $after < 0) json_response(['error' => 'invalid_revision'], 400);
            json_response(['changes' => ActionService::changes($database, $matches[1], (string) $user['id'], $after)]);
        }
        if (preg_match('#^/api/sessions/([0-9a-fA-F-]{36})/actions$#', $path, $matches) && $method === 'POST') {
            $result = ActionService::execute($database, $user, $matches[1], json_body());
            json_response($result);
        }
        json_response(['error' => 'not_found'], 404);
    } catch (Throwable $exception) {
        $status = str_contains(strtolower($exception->getMessage()), 'authentication') ? 401 : 409;
        json_response(['error' => 'request_rejected', 'message' => $exception->getMessage()], $status);
    }
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
