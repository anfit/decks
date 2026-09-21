<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use PDO;
use RuntimeException;

$apply = in_array('--apply', $argv, true);
$json = in_array('--json', $argv, true);
if ($apply && getenv('DECKS_RETENTION_CONFIRM') !== 'apply') {
    fwrite(STDERR, "DECKS_RETENTION_CONFIRM=apply is required for --apply.\n");
    exit(2);
}

$database = Decks\database();
$rules = [
    'reset_days' => retention_days('DECKS_RETENTION_RESET_DAYS'),
    'remember_days' => retention_days('DECKS_RETENTION_REMEMBER_DAYS'),
    'rate_limit_days' => retention_days('DECKS_RETENTION_RATE_LIMIT_DAYS'),
    'outbox_days' => retention_days('DECKS_RETENTION_OUTBOX_DAYS'),
    'session_days' => retention_days('DECKS_RETENTION_SESSION_DAYS'),
];
$allowSessionDelete = getenv('DECKS_RETENTION_ALLOW_SESSION_DELETE') === '1';

/** @var array<string,int|string|bool> $report */
$report = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'reset_tokens' => 0,
    'remember_tokens' => 0,
    'rate_limit_windows' => 0,
    'outbox_rows' => 0,
    'ended_sessions' => 0,
    'ended_sessions_deleted' => 0,
    'asset_candidates' => 0,
    'session_delete_allowed' => $allowSessionDelete,
];

$report['reset_tokens'] = count_rows($database, 'password_reset_tokens', $rules['reset_days'], 'expires_at <= now()');
$report['remember_tokens'] = count_rows($database, 'remember_tokens', $rules['remember_days'], 'expires_at <= now()');
$report['rate_limit_windows'] = count_rows($database, 'request_rate_limits', $rules['rate_limit_days'], 'window_start < now()');
$report['outbox_rows'] = count_rows($database, 'email_outbox', $rules['outbox_days'], '(sent_at IS NOT NULL OR attempts >= 8)');
$report['ended_sessions'] = count_sessions($database, $rules['session_days']);
$report['asset_candidates'] = count_assets($database, $rules['session_days']);

if ($apply) {
    $database->beginTransaction();
    try {
        if ($rules['reset_days'] !== null) {
            $database->prepare("DELETE FROM password_reset_tokens WHERE created_at < now() - (:days::integer * interval '1 day') AND (used_at IS NOT NULL OR expires_at <= now())")
                ->execute(['days' => $rules['reset_days']]);
        }
        if ($rules['remember_days'] !== null) {
            $database->prepare("DELETE FROM remember_tokens WHERE created_at < now() - (:days::integer * interval '1 day') AND expires_at <= now()")
                ->execute(['days' => $rules['remember_days']]);
        }
        if ($rules['rate_limit_days'] !== null) {
            $database->prepare("DELETE FROM request_rate_limits WHERE window_start < now() - (:days::integer * interval '1 day')")
                ->execute(['days' => $rules['rate_limit_days']]);
        }
        if ($rules['outbox_days'] !== null) {
            $database->prepare("UPDATE email_outbox SET body = '[redacted]' WHERE created_at < now() - (:days::integer * interval '1 day') AND (sent_at IS NOT NULL OR attempts >= 8)")
                ->execute(['days' => $rules['outbox_days']]);
            $database->prepare("DELETE FROM email_outbox WHERE created_at < now() - (:days::integer * interval '1 day') AND (sent_at IS NOT NULL OR attempts >= 8)")
                ->execute(['days' => $rules['outbox_days']]);
        }
        if ($allowSessionDelete && $rules['session_days'] !== null) {
            $database->prepare("DELETE FROM sessions WHERE status = 'ended' AND ended_at < now() - (:days::integer * interval '1 day')")
                ->execute(['days' => $rules['session_days']]);
            $report['ended_sessions_deleted'] = $report['ended_sessions'];
        }
        $database->commit();
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->rollBack();
        throw $error;
    }
}

if ($json) {
    echo json_encode(['rules' => $rules, 'report' => $report], JSON_THROW_ON_ERROR) . "\n";
} else {
    echo ($apply ? 'Retention applied.' : 'Retention dry run.') . "\n";
    foreach ($report as $key => $value) echo $key . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) . "\n";
}

function retention_days(string $name): ?int
{
    $raw = getenv($name);
    if ($raw === false || $raw === '') return null;
    if (!ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 3650) throw new RuntimeException("{$name} must be between 1 and 3650.");
    return (int) $raw;
}

function count_rows(PDO $database, string $table, ?int $days, string $condition): int
{
    if ($days === null) return 0;
    $statement = $database->prepare("SELECT count(*) FROM {$table} WHERE created_at < now() - (:days::integer * interval '1 day') AND {$condition}");
    $statement->execute(['days' => $days]);
    return (int) $statement->fetchColumn();
}

function count_sessions(PDO $database, ?int $days): int
{
    if ($days === null) return 0;
    $statement = $database->prepare("SELECT count(*) FROM sessions WHERE status = 'ended' AND ended_at < now() - (:days::integer * interval '1 day')");
    $statement->execute(['days' => $days]);
    return (int) $statement->fetchColumn();
}

function count_assets(PDO $database, ?int $days): int
{
    if ($days === null) return 0;
    $statement = $database->prepare(
        "SELECT count(*) FROM assets a
         WHERE a.created_at < now() - (:days::integer * interval '1 day')
           AND NOT EXISTS (SELECT 1 FROM deck_template_versions v WHERE v.default_back_asset_id = a.id)
           AND NOT EXISTS (SELECT 1 FROM card_definitions c WHERE c.front_asset_id = a.id OR c.back_asset_id = a.id)",
    );
    $statement->execute(['days' => $days]);
    return (int) $statement->fetchColumn();
}
