<?php

declare(strict_types=1);

namespace Decks;

use PDO;
use RuntimeException;

final class SessionService
{
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

    public static function join(PDO $database, array $user, string $tokenValue, string $role = 'player'): array
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
            $database->prepare('UPDATE sessions SET status = :status, frozen_at = CASE WHEN :set_frozen THEN now() ELSE frozen_at END, ended_at = CASE WHEN :set_ended THEN now() ELSE ended_at END, revision = revision + 1, last_activity_at = now() WHERE id = :id')
                ->execute(['status' => $status, 'set_frozen' => $status === 'active', 'set_ended' => $status === 'ended', 'id' => $sessionId]);
            Security::audit($database, (string) $user['id'], 'session.' . $status, 'session', $sessionId);
            $database->commit();
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    private static function lockedMembership(PDO $database, string $sessionId, string $userId): ?array
    {
        $statement = $database->prepare('SELECT p.*, s.status FROM session_participants p JOIN sessions s ON s.id = p.session_id WHERE p.session_id = :session AND p.user_id = :user AND p.removed_at IS NULL FOR UPDATE OF p, s');
        $statement->execute(['session' => $sessionId, 'user' => $userId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }
}
