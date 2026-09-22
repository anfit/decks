<?php

declare(strict_types=1);

namespace Decks;

use PDO;
use RuntimeException;

final class SessionService
{
    /** @var list<string> */
    private const TABLE_CAPABILITIES = [
        'session.manage',
        'participant.manage',
        'zone.manage',
        'deck.manage',
        'card.manage',
        'pile.manage',
        'lock.manage',
        'card.undo',
    ];

    public static function create(PDO $database, array $user, ?string $title = null, int $maxParticipants = 12): array
    {
        if (($user['id'] ?? '') === '') throw new RuntimeException('Authentication required.');
        if ($maxParticipants < 1 || $maxParticipants > 100) throw new RuntimeException('Invalid participant limit.');
        $join = Token::issue();
        $database->beginTransaction();
        try {
            $insert = $database->prepare(
                'INSERT INTO sessions(title, host_user_id, join_selector, join_secret_hash, join_expires_at, max_participants)
                 VALUES (:title, :host, :selector, :secret_hash, now() + interval \'30 days\', :max)
                 RETURNING id, revision, join_expires_at',
            );
            $insert->execute(['title' => $title, 'host' => $user['id'], 'selector' => $join['selector'], 'secret_hash' => $join['hash'], 'max' => $maxParticipants]);
            $session = $insert->fetch();
            $participant = $database->prepare(
                "INSERT INTO session_participants(session_id, user_id, role) VALUES (:session, :user, 'host') RETURNING id",
            );
            $participant->execute(['session' => $session['id'], 'user' => $user['id']]);
            $participantRow = $participant->fetch();
            $database->prepare('INSERT INTO session_hands(session_id, participant_id) VALUES (:session, :participant)')
                ->execute(['session' => $session['id'], 'participant' => $participantRow['id']]);
            Security::audit($database, (string) $user['id'], 'session.created', 'session', (string) $session['id']);
            $database->commit();
            return ['id' => (string) $session['id'], 'revision' => (int) $session['revision'], 'join_token' => $join['value'], 'join_expires_at' => (string) $session['join_expires_at']];
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    /** List resumable memberships without exposing join credentials or private state. */
    public static function listOwned(PDO $database, array $user): array
    {
        $statement = $database->prepare(
            'SELECT s.id, s.title, s.status, s.revision, s.created_at, s.last_activity_at, p.role
             FROM sessions s
             JOIN session_participants p ON p.session_id = s.id
             WHERE p.user_id = :user AND p.removed_at IS NULL
             ORDER BY s.last_activity_at DESC, s.id',
        );
        $statement->execute(['user' => $user['id']]);
        return ['sessions' => array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'title' => $row['title'] !== null ? (string) $row['title'] : null,
            'status' => (string) $row['status'],
            'revision' => (int) $row['revision'],
            'role' => (string) $row['role'],
            'created_at' => (string) $row['created_at'],
            'last_activity_at' => (string) $row['last_activity_at'],
        ], $statement->fetchAll())];
    }

    public static function join(PDO $database, array $user, string $tokenValue, string $role = 'player', ?string $expectedSessionId = null): array
    {
        if (!in_array($role, ['player', 'spectator'], true)) throw new RuntimeException('Invalid table role.');
        $parts = Token::split($tokenValue);
        if ($parts === null) throw new RuntimeException('This table invitation is invalid or expired.');
        $database->beginTransaction();
        try {
            $sessionStatement = $database->prepare(
                'SELECT * FROM sessions WHERE join_selector = :selector AND join_expires_at > now() FOR UPDATE',
            );
            $sessionStatement->execute(['selector' => $parts['selector']]);
            $session = $sessionStatement->fetch();
            if (!is_array($session) || !Token::matches($parts['secret'], (string) $session['join_secret_hash']) || $session['status'] === 'ended') {
                throw new RuntimeException('This table invitation is invalid or expired.');
            }
            if ($expectedSessionId !== null && (string) $session['id'] !== $expectedSessionId) throw new RuntimeException('This table invitation belongs to another table.');
            $existing = $database->prepare('SELECT * FROM session_participants WHERE session_id = :session AND user_id = :user FOR UPDATE');
            $existing->execute(['session' => $session['id'], 'user' => $user['id']]);
            $participant = $existing->fetch();
            if (is_array($participant)) {
                if ($participant['removed_at'] !== null) {
                    $database->prepare('UPDATE session_participants SET removed_at = NULL, role = :role WHERE id = :id')
                        ->execute(['role' => $role, 'id' => $participant['id']]);
                    $participant['role'] = $role;
                }
                $role = (string) $participant['role'];
                if ($role === 'player') {
                    $database->prepare('INSERT INTO session_hands(session_id, participant_id) VALUES (:session, :participant) ON CONFLICT DO NOTHING')
                        ->execute(['session' => $session['id'], 'participant' => $participant['id']]);
                }
            } else {
                $count = $database->prepare('SELECT count(*) FROM session_participants WHERE session_id = :session AND removed_at IS NULL');
                $count->execute(['session' => $session['id']]);
                if ((int) $count->fetchColumn() >= (int) $session['max_participants']) throw new RuntimeException('This table is full.');
                $insert = $database->prepare("INSERT INTO session_participants(session_id, user_id, role) VALUES (:session, :user, :role) RETURNING id");
                $insert->execute(['session' => $session['id'], 'user' => $user['id'], 'role' => $role]);
                $participant = $insert->fetch();
                if ($role === 'player') {
                    $database->prepare('INSERT INTO session_hands(session_id, participant_id) VALUES (:session, :participant)')
                        ->execute(['session' => $session['id'], 'participant' => $participant['id']]);
                }
            }
            Security::audit($database, (string) $user['id'], 'session.joined', 'session', (string) $session['id'], ['role' => $role]);
            $database->commit();
            return ['session_id' => (string) $session['id'], 'participant_id' => (string) $participant['id'], 'role' => $role];
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function membership(PDO $database, string $sessionId, string $userId, bool $includeRemoved = false): ?array
    {
        $sql = 'SELECT p.*, s.status, s.revision, s.host_user_id FROM session_participants p JOIN sessions s ON s.id = p.session_id WHERE p.session_id = :session AND p.user_id = :user';
        if (!$includeRemoved) $sql .= ' AND p.removed_at IS NULL';
        $statement = $database->prepare($sql);
        $statement->execute(['session' => $sessionId, 'user' => $userId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** Return only valid, allowlisted explicit capability decisions. */
    public static function capabilities(array $member): array
    {
        $raw = $member['capabilities'] ?? [];
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) return [];
        $capabilities = [];
        foreach (self::TABLE_CAPABILITIES as $capability) {
            if (array_key_exists($capability, $decoded) && is_bool($decoded[$capability])) {
                $capabilities[$capability] = $decoded[$capability];
            }
        }
        return $capabilities;
    }

    public static function hasCapability(array $member, string $capability): bool
    {
        $explicit = self::capabilities($member);
        if (array_key_exists($capability, $explicit)) return $explicit[$capability];
        if (($member['role'] ?? '') === 'host') return true;
        if (($member['role'] ?? '') === 'spectator') return false;
        return in_array($capability, ['deck.manage', 'card.manage', 'pile.manage', 'lock.manage', 'card.undo'], true);
    }

    public static function setCapabilities(PDO $database, array $session, array $member, array $payload): array
    {
        if (($member['role'] ?? null) !== 'host') throw new RuntimeException('Host permission required.');
        $participantId = (string) ($payload['participant_id'] ?? '');
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $participantId)) throw new RuntimeException('Participant reference is invalid.');
        if ($participantId === (string) ($member['id'] ?? '')) throw new RuntimeException('The host cannot change their own capabilities.');
        $requested = $payload['capabilities'] ?? null;
        if (!is_array($requested)) throw new RuntimeException('Capability map is invalid.');
        $normalized = [];
        foreach ($requested as $capability => $enabled) {
            if (!in_array((string) $capability, self::TABLE_CAPABILITIES, true) || !is_bool($enabled)) {
                throw new RuntimeException('Capability map contains an invalid entry.');
            }
            $normalized[(string) $capability] = $enabled;
        }
        $target = $database->prepare('SELECT id FROM session_participants WHERE session_id = :session AND id = :participant AND removed_at IS NULL FOR UPDATE');
        $target->execute(['session' => $session['id'], 'participant' => $participantId]);
        if (!$target->fetch()) throw new RuntimeException('Participant not found.');
        $database->prepare('UPDATE session_participants SET capabilities = CAST(:capabilities AS jsonb) WHERE session_id = :session AND id = :participant')
            ->execute(['capabilities' => json_encode($normalized, JSON_THROW_ON_ERROR), 'session' => $session['id'], 'participant' => $participantId]);
        Security::audit($database, (string) $member['user_id'], 'session.participant_capabilities_updated', 'session_participant', $participantId, ['session_id' => (string) $session['id'], 'capability_count' => count($normalized)]);
        return ['participant_id' => $participantId, 'updated' => true, 'capability_count' => count($normalized)];
    }

    public static function leave(PDO $database, array $user, string $sessionId): void
    {
        $database->beginTransaction();
        try {
            $member = self::lockedMembership($database, $sessionId, (string) $user['id']);
            if ($member === null) throw new RuntimeException('Table membership not found.');
            $database->prepare('UPDATE session_participants SET removed_at = now() WHERE id = :id')->execute(['id' => $member['id']]);
            $database->prepare('UPDATE sessions SET revision = revision + 1, last_activity_at = now() WHERE id = :id')->execute(['id' => $sessionId]);
            Security::audit($database, (string) $user['id'], 'session.left', 'session', $sessionId);
            $database->commit();
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function setStatus(PDO $database, array $user, string $sessionId, string $status): void
    {
        if (!in_array($status, ['active', 'ended'], true)) throw new RuntimeException('Invalid session status.');
        $database->beginTransaction();
        try {
            $member = self::lockedMembership($database, $sessionId, (string) $user['id']);
            if ($member === null || $member['role'] !== 'host') throw new RuntimeException('Host permission required.');
            $session = $database->prepare('SELECT status FROM sessions WHERE id = :id FOR UPDATE');
            $session->execute(['id' => $sessionId]);
            if (!$session->fetch()) throw new RuntimeException('Session not found.');
            $database->prepare('UPDATE sessions SET status = :status, frozen_at = CASE WHEN :set_frozen = \'true\' THEN now() ELSE frozen_at END, ended_at = CASE WHEN :set_ended = \'true\' THEN now() ELSE ended_at END, revision = revision + 1, last_activity_at = now() WHERE id = :id')
                ->execute(['status' => $status, 'set_frozen' => $status === 'active' ? 'true' : 'false', 'set_ended' => $status === 'ended' ? 'true' : 'false', 'id' => $sessionId]);
            Security::audit($database, (string) $user['id'], 'session.' . $status, 'session', $sessionId);
            $database->commit();
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function transferHost(PDO $database, array $session, array $member, array $payload): array
    {
        if (($member['role'] ?? null) !== 'host') throw new RuntimeException('Host permission required.');
        $targetId = (string) ($payload['participant_id'] ?? '');
        $target = $database->prepare("SELECT id, user_id, role FROM session_participants WHERE session_id = :session AND id = :id AND removed_at IS NULL AND role IN ('player','spectator') FOR UPDATE");
        $target->execute(['session' => $session['id'], 'id' => $targetId]);
        $row = $target->fetch();
        if (!is_array($row)) throw new RuntimeException('Host transfer target is invalid.');
        $database->prepare("UPDATE session_participants SET role = 'player' WHERE session_id = :session AND role = 'host'")->execute(['session' => $session['id']]);
        $database->prepare("UPDATE session_participants SET role = 'host' WHERE session_id = :session AND id = :target")->execute(['session' => $session['id'], 'target' => $targetId]);
        $database->prepare('INSERT INTO session_hands(session_id, participant_id) VALUES (:session, :participant) ON CONFLICT DO NOTHING')->execute(['session' => $session['id'], 'participant' => $targetId]);
        $database->prepare('UPDATE sessions SET host_user_id = :user WHERE id = :session')->execute(['user' => $row['user_id'], 'session' => $session['id']]);
        Security::audit($database, (string) $member['user_id'], 'session.host_transferred', 'session_participant', $targetId, ['session_id' => (string) $session['id']]);
        return ['participant_id' => $targetId, 'host_user_id' => (string) $row['user_id']];
    }

    public static function removeParticipant(PDO $database, array $session, array $member, array $payload): array
    {
        if (($member['role'] ?? null) !== 'host') throw new RuntimeException('Host permission required.');
        $targetId = (string) ($payload['participant_id'] ?? '');
        if ($targetId === (string) $member['id']) throw new RuntimeException('The host cannot remove themself.');
        $target = $database->prepare("UPDATE session_participants SET removed_at = now() WHERE session_id = :session AND id = :id AND removed_at IS NULL AND role <> 'host'");
        $target->execute(['session' => $session['id'], 'id' => $targetId]);
        if ($target->rowCount() !== 1) throw new RuntimeException('Participant not found.');
        Security::audit($database, (string) $member['user_id'], 'session.participant_removed', 'session_participant', $targetId, ['session_id' => (string) $session['id']]);
        return ['participant_id' => $targetId, 'removed' => true];
    }

    public static function restoreParticipant(PDO $database, array $session, array $member, array $payload): array
    {
        if (($member['role'] ?? null) !== 'host') throw new RuntimeException('Host permission required.');
        $targetId = (string) ($payload['participant_id'] ?? '');
        $role = ($payload['role'] ?? 'player') === 'spectator' ? 'spectator' : 'player';
        $target = $database->prepare('UPDATE session_participants SET removed_at = NULL, role = :role WHERE session_id = :session AND id = :id AND removed_at IS NOT NULL');
        $target->execute(['role' => $role, 'session' => $session['id'], 'id' => $targetId]);
        if ($target->rowCount() !== 1) throw new RuntimeException('Removed participant not found.');
        if ($role === 'player') $database->prepare('INSERT INTO session_hands(session_id, participant_id) VALUES (:session, :participant) ON CONFLICT DO NOTHING')->execute(['session' => $session['id'], 'participant' => $targetId]);
        Security::audit($database, (string) $member['user_id'], 'session.participant_restored', 'session_participant', $targetId, ['session_id' => (string) $session['id'], 'role' => $role]);
        return ['participant_id' => $targetId, 'restored' => true, 'role' => $role];
    }

    private static function lockedMembership(PDO $database, string $sessionId, string $userId): ?array
    {
        $statement = $database->prepare('SELECT p.*, s.status FROM session_participants p JOIN sessions s ON s.id = p.session_id WHERE p.session_id = :session AND p.user_id = :user AND p.removed_at IS NULL FOR UPDATE OF p, s');
        $statement->execute(['session' => $sessionId, 'user' => $userId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }
}
