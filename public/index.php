<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Decks\InvitationService;
use Decks\ActionService;
use Decks\AssetService;
use Decks\MatPresetService;
use Decks\PasswordResetService;
use Decks\RememberMe;
use Decks\RealtimeTicket;
use Decks\RateLimiter;
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

if (in_array($path, ['/login', '/accept-invitation', '/request-password-reset', '/reset-password', '/logout', '/logout-everywhere', '/account', '/account/invite', '/admin/users', '/admin/invitations'], true) ||
    preg_match('#^/account/invitations/[0-9a-fA-F-]{36}/rescind$#', $path) ||
    preg_match('#^/admin/invitations/[0-9a-fA-F-]{36}/(resend|rescind|restore-credit)$#', $path) ||
    preg_match('#^/admin/users/[0-9a-fA-F-]{36}/(role|enabled|reset)$#', $path)) {
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
    if ($path === '/logout-everywhere' && $method === 'POST') {
        require_csrf($_POST['csrf_token'] ?? null);
        $user = Security::currentUser($database);
        if ($user === null) redirect_to('/login');
        Security::signOutEverywhere($database, $user);
        if (isset($_COOKIE[remember_cookie_name()])) RememberMe::revoke($database, (string) $_COOKIE[remember_cookie_name()]);
        $_SESSION = [];
        session_destroy();
        redirect_to('/login?signed_out_everywhere=1');
    }
    if ($path === '/login') {
        if ($method === 'POST') {
            try {
                require_csrf($_POST['csrf_token'] ?? null);
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
                $ipAllowed = RateLimiter::consume($database, 'login.ip', $ip, RateLimiter::LOGIN_IP_LIMIT, RateLimiter::LOGIN_WINDOW_SECONDS);
                $emailAllowed = RateLimiter::consume($database, 'login.email', $email, RateLimiter::LOGIN_EMAIL_LIMIT, RateLimiter::LOGIN_WINDOW_SECONDS);
                $user = $ipAllowed && $emailAllowed ? Security::authenticate($database, $email, (string) ($_POST['password'] ?? '')) : null;
                if ($user === null) throw new RuntimeException('Email or password is incorrect.');
                RateLimiter::clear($database, 'login.email', $email);
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
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
                $ipAllowed = RateLimiter::consume($database, 'password-reset.ip', $ip, RateLimiter::RESET_IP_LIMIT, RateLimiter::RESET_WINDOW_SECONDS);
                $emailAllowed = RateLimiter::consume($database, 'password-reset.email', $email, RateLimiter::RESET_EMAIL_LIMIT, RateLimiter::RESET_WINDOW_SECONDS);
                if ($ipAllowed && $emailAllowed) {
                    PasswordResetService::request($database, $email, getenv('DECKS_PUBLIC_BASE_URL') ?: 'http://127.0.0.1:5240');
                }
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
    $baseUrl = getenv('DECKS_PUBLIC_BASE_URL') ?: 'http://127.0.0.1:5240';
    if ($path === '/account' || $path === '/account/invite' || preg_match('#^/account/invitations/([0-9a-fA-F-]{36})/rescind$#', $path, $accountInvitation)) {
        $user = Security::currentUser($database);
        if ($user === null) redirect_to('/login?next=%2Faccount');
        if ($method === 'POST') {
            try {
                require_csrf($_POST['csrf_token'] ?? null);
                if ($path === '/account/invite') {
                    InvitationService::create($database, $user, (string) ($_POST['email'] ?? ''), $baseUrl);
                    page('Invitation queued · Decks', '<h1>Invitation queued</h1><p>The invitation has been queued for delivery.</p><p><a href="/account">Return to account</a></p>');
                }
                if (isset($accountInvitation[1])) {
                    InvitationService::rescind($database, $user, $accountInvitation[1]);
                    redirect_to('/account');
                }
                if ($path === '/account' && ($_POST['action'] ?? '') === 'change_password') {
                    Security::changePassword($database, $user, (string) ($_POST['current_password'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['password_confirmation'] ?? ''));
                    $_SESSION = [];
                    session_destroy();
                    redirect_to('/login?password_changed=1');
                }
            } catch (Throwable $exception) { $error = $exception->getMessage(); }
        }
        if ($path === '/account/invite') {
            $message = $error ? '<p class="error">' . h($error) . '</p>' : '';
            page('Invite someone · Decks', '<h1>Invite someone to Decks</h1>' . $message . '<form method="post"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><label>Email<input name="email" type="email" autocomplete="email" required></label><button>Queue invitation</button></form><p><a href="/account">Return to account</a></p>');
        }
        if (isset($accountInvitation[1])) {
            page('Invitation · Decks', '<h1>Invitation</h1><p class="error">' . h($error ?? 'The invitation could not be rescinded.') . '</p><p><a href="/account">Return to account</a></p>');
        }
        $record = Security::accountRecord($database, (string) $user['id']);
        $issued = InvitationService::listIssued($database, (string) $user['id']);
        $message = $error ? '<p class="error">' . h($error) . '</p>' : '';
        $invitationRows = '';
        foreach ($issued as $invitation) {
            $invitationRows .= '<li>' . h((string) $invitation['email']) . ' · ' . h((string) $invitation['status']) . ' · delivery ' . h((string) $invitation['delivery_state']);
            if ($invitation['status'] === 'pending') {
                $invitationRows .= '<form method="post" action="/account/invitations/' . h((string) $invitation['id']) . '/rescind"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><button>Rescind</button></form>';
            }
            $invitationRows .= '</li>';
        }
        if ($invitationRows === '') $invitationRows = '<li>No invitations issued.</li>';
        page('Account · Decks', '<h1>Account</h1>' . $message . '<p><strong>Email:</strong> ' . h((string) ($record['email'] ?? $user['email'])) . '<br><strong>Role:</strong> ' . h((string) ($record['role'] ?? $user['role'])) . '<br><strong>Invitation credits:</strong> ' . h((string) ($record['role'] === 'admin' ? 'unlimited' : ($record['invitation_credits'] ?? 0))) . '</p><p><a href="/account/invite">Invite someone</a> · <a href="/">Decks</a></p><h2>Issued invitations</h2><ul>' . $invitationRows . '</ul><h2>Security</h2><form method="post"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="change_password"><label>Current password<input name="current_password" type="password" autocomplete="current-password" required></label><label>New password<input name="password" type="password" minlength="16" autocomplete="new-password" required></label><label>Confirm new password<input name="password_confirmation" type="password" minlength="16" autocomplete="new-password" required></label><button>Change password</button></form><form method="post" action="/logout-everywhere"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><button>Sign out everywhere</button></form>');
    }

    if ($path === '/admin/users' || $path === '/admin/invitations' || preg_match('#^/admin/invitations/([0-9a-fA-F-]{36})/(resend|rescind|restore-credit)$#', $path, $adminInvitation) || preg_match('#^/admin/users/([0-9a-fA-F-]{36})/(role|enabled|reset)$#', $path, $adminUser)) {
        $user = Security::currentUser($database);
        if ($user === null) redirect_to('/login?next=%2Fadmin%2Fusers');
        try {
            Security::requireCapability($user, 'users.manage');
            if ($method === 'POST') {
                require_csrf($_POST['csrf_token'] ?? null);
                if ($path === '/admin/invitations') {
                    InvitationService::create($database, $user, (string) ($_POST['email'] ?? ''), $baseUrl);
                    $notice = 'Invitation queued.';
                } elseif (isset($adminInvitation[1])) {
                    $result = match ($adminInvitation[2]) {
                        'resend' => InvitationService::resend($database, $user, $adminInvitation[1], $baseUrl),
                        'rescind' => InvitationService::rescind($database, $user, $adminInvitation[1]),
                        default => InvitationService::restoreCredit($database, $user, $adminInvitation[1]),
                    };
                    $notice = 'Invitation operation: ' . (string) ($result['status'] ?? 'complete') . '.';
                } elseif (isset($adminUser[1])) {
                    $target = $adminUser[1];
                    $operation = $adminUser[2];
                    if ($operation === 'role') Security::setRole($database, $user, $target, (string) ($_POST['role'] ?? 'member'));
                    elseif ($operation === 'enabled') Security::setEnabled($database, $user, $target, (string) ($_POST['enabled'] ?? '0') === '1');
                    else PasswordResetService::requestForUser($database, $user, $target, $baseUrl);
                    $notice = 'Account operation completed.';
                }
            }
        } catch (Throwable $exception) { $error = $exception->getMessage(); }
        $users = Security::listUsers($database);
        $invitations = InvitationService::listAll($database);
        $message = $error ? '<p class="error">' . h($error) . '</p>' : (($notice ?? null) ? '<p>' . h($notice) . '</p>' : '');
        $userRows = '';
        foreach ($users as $managed) {
            $id = h((string) $managed['id']);
            $role = (string) $managed['role'];
            $enabled = (bool) $managed['enabled'];
            $userRows .= '<li><strong>' . h((string) $managed['email']) . '</strong> · ' . h($role) . ' · ' . ($enabled ? 'enabled' : 'disabled') . ' · credits ' . h((string) $managed['invitation_credits']) . '<form method="post" action="/admin/users/' . $id . '/role"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><select name="role"><option value="member"' . ($role === 'member' ? ' selected' : '') . '>member</option><option value="admin"' . ($role === 'admin' ? ' selected' : '') . '>admin</option></select><button>Set role</button></form><form method="post" action="/admin/users/' . $id . '/enabled"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="enabled" value="' . ($enabled ? '0' : '1') . '"><button>' . ($enabled ? 'Disable' : 'Enable') . '</button></form><form method="post" action="/admin/users/' . $id . '/reset"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><button>Email reset link</button></form></li>';
        }
        $inviteRows = '';
        foreach ($invitations as $invitation) {
            $id = h((string) $invitation['id']);
            $inviteRows .= '<li>' . h((string) $invitation['email']) . ' · ' . h((string) $invitation['status']) . ' · delivery ' . h((string) $invitation['delivery_state']) . ' · inviter ' . h((string) ($invitation['inviter_email'] ?? 'unknown'));
            if ($invitation['status'] === 'pending') {
                $inviteRows .= '<form method="post" action="/admin/invitations/' . $id . '/resend"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><button>Resend</button></form><form method="post" action="/admin/invitations/' . $id . '/rescind"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><button>Rescind</button></form>';
            }
            if ((bool) $invitation['credit_consumed'] && $invitation['credit_restored_at'] === null && $invitation['status'] !== 'accepted') {
                $inviteRows .= '<form method="post" action="/admin/invitations/' . $id . '/restore-credit"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><button>Restore credit</button></form>';
            }
            $inviteRows .= '</li>';
        }
        if ($userRows === '') $userRows = '<li>No users.</li>';
        if ($inviteRows === '') $inviteRows = '<li>No invitations.</li>';
        page('Users · Decks', '<h1>Users and invitations</h1>' . $message . '<p><a href="/account">Account</a> · <a href="/">Decks</a></p><h2>Invite user</h2><form method="post" action="/admin/invitations"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><label>Email<input name="email" type="email" required></label><button>Queue invitation</button></form><h2>Users</h2><ul>' . $userRows . '</ul><h2>Invitations</h2><ul>' . $inviteRows . '</ul>');
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

    if ($path === '/api/csrf' && $method === 'GET') {
        json_response(['csrf_token' => csrf_token()]);
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
        if ($path === '/api/mats' && $method === 'POST') {
            $body = json_body();
            json_response(['mat' => MatPresetService::createMat($database, $user, (string) ($body['name'] ?? ''), isset($body['background_asset_id']) ? (string) $body['background_asset_id'] : null, isset($body['width']) ? (int) $body['width'] : null, isset($body['height']) ? (int) $body['height'] : null, is_array($body['metadata'] ?? null) ? $body['metadata'] : [])], 201);
        }
        if ($path === '/api/mats-and-presets' && $method === 'GET') {
            json_response(MatPresetService::listOwned($database, $user));
        }
        if ($path === '/api/presets' && $method === 'POST') {
            $body = json_body();
            json_response(['preset' => MatPresetService::createPreset($database, $user, (string) ($body['name'] ?? ''), (string) ($body['template_version_id'] ?? ''), isset($body['mat_version_id']) ? (string) $body['mat_version_id'] : null, is_array($body['configuration'] ?? null) ? $body['configuration'] : [])], 201);
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
        if ($path === '/api/sessions/join' && $method === 'POST') {
            $body = json_body();
            $joined = SessionService::join($database, $user, (string) ($body['token'] ?? ''), (string) ($body['role'] ?? 'player'));
            json_response(['membership' => $joined], 201);
        }
        if (preg_match('#^/api/sessions/([0-9a-fA-F-]{36})/join$#', $path, $matches) && $method === 'POST') {
            $body = json_body();
            $joined = SessionService::join($database, $user, (string) ($body['token'] ?? ''), (string) ($body['role'] ?? 'player'), $matches[1]);
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
        if (preg_match('#^/api/sessions/([0-9a-fA-F-]{36})/realtime-ticket$#', $path, $matches) && $method === 'POST') {
            if (SessionService::membership($database, $matches[1], (string) $user['id']) === null) {
                json_response(['error' => 'table_membership_required'], 403);
            }
            json_response(['ticket' => RealtimeTicket::issue($matches[1], $user)]);
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
$styles = [];
if (is_file($assetManifest)) {
    $manifest = json_decode((string) file_get_contents($assetManifest), true, 512, JSON_THROW_ON_ERROR);
    $manifestEntry = $manifest['src/main.ts'] ?? $manifest['index.html'] ?? null;
    if (is_array($manifestEntry)) {
        $entry = isset($manifestEntry['file']) && is_string($manifestEntry['file']) ? $manifestEntry['file'] : null;
        $styles = array_values(array_filter($manifestEntry['css'] ?? [], 'is_string'));
    }
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
      <?php foreach ($styles as $style): ?><link rel="stylesheet" href="/assets-build/<?= htmlspecialchars($style, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?php endforeach; ?>
      <main id="app" aria-live="polite">
        <section class="welcome" aria-labelledby="welcome-title">
          <p class="eyebrow">Decks</p>
          <h1 id="welcome-title">A shared table for physical card play.</h1>
          <p class="muted">Create a table or join one from an invitation.</p>
          <div id="workspace"></div>
          <p id="connection-status" class="status">Starting…</p>
        </section>
      </main>
      <script type="module" src="/assets-build/<?= htmlspecialchars($entry, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></script>
    <?php else: ?>
      <main><h1>Decks</h1><p>Frontend assets are not built yet.</p></main>
    <?php endif; ?>
  </body>
</html>
