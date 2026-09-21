<?php

declare(strict_types=1);

namespace Decks;

use PDO;
use RuntimeException;

final class ActionService
{
    public static function execute(PDO $database, array $user, string $sessionId, array $request): array
    {
        $actionId = (string) ($request['action_id'] ?? '');
        $type = (string) ($request['type'] ?? '');
        $payload = $request['payload'] ?? [];
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $actionId) || $type === '' || !is_array($payload)) {
            throw new RuntimeException('Invalid action request.');
        }
        $requestHash = hash('sha256', self::canonicalJson(['type' => $type, 'payload' => $payload, 'expected_session_revision' => $request['expected_session_revision'] ?? null]));
        $database->beginTransaction();
        try {
            $duplicate = $database->prepare(
                'SELECT request_hash, status, revision, result FROM processed_actions
                 WHERE session_id = :session AND actor_user_id = :actor AND action_id = :action',
            );
            $duplicate->execute(['session' => $sessionId, 'actor' => $user['id'], 'action' => $actionId]);
            $prior = $duplicate->fetch();
            if (is_array($prior)) {
                if (!hash_equals((string) $prior['request_hash'], $requestHash)) throw new RuntimeException('Action ID was already used for another request.');
                $database->commit();
                return ['status' => (string) $prior['status'], 'revision' => (int) $prior['revision'], 'result' => json_decode((string) $prior['result'], true, 512, JSON_THROW_ON_ERROR), 'duplicate' => true];
            }
            $sessionStatement = $database->prepare('SELECT * FROM sessions WHERE id = :id FOR UPDATE');
            $sessionStatement->execute(['id' => $sessionId]);
            $session = $sessionStatement->fetch();
            if (!is_array($session)) throw new RuntimeException('Session not found.');
            $member = SessionService::membership($database, $sessionId, (string) $user['id']);
            if ($member === null) throw new RuntimeException('Active table membership required.');
            if ($session['status'] === 'ended' && !in_array($type, ['leave_session', 'reset_session'], true)) throw new RuntimeException('Session has ended.');
            if (array_key_exists('expected_session_revision', $request) && $request['expected_session_revision'] !== null && (int) $request['expected_session_revision'] !== (int) $session['revision']) {
                throw new RuntimeException('Session changed; refresh and try again.');
            }
            $result = self::apply($database, $session, $member, $user, $type, $payload);
            $database->prepare('UPDATE sessions SET revision = revision + 1, last_activity_at = now() WHERE id = :id')
                ->execute(['id' => $sessionId]);
            $revision = (int) $session['revision'] + 1;
            $event = $database->prepare(
                'INSERT INTO session_events(session_id, revision, actor_user_id, action_type, public_payload)
                 VALUES (:session, :revision, :actor, :type, CAST(:payload AS jsonb))',
            );
            $event->execute(['session' => $sessionId, 'revision' => $revision, 'actor' => $user['id'], 'type' => $type, 'payload' => json_encode($result, JSON_THROW_ON_ERROR)]);
            $stored = $database->prepare(
                'INSERT INTO processed_actions(session_id, actor_user_id, action_id, request_hash, revision, status, result)
                 VALUES (:session, :actor, :action, :hash, :revision, \'accepted\', CAST(:result AS jsonb))',
            );
            $stored->execute(['session' => $sessionId, 'actor' => $user['id'], 'action' => $actionId, 'hash' => $requestHash, 'revision' => $revision, 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);
            $database->prepare("SELECT pg_notify('decks_session_changed', :payload)")
                ->execute(['payload' => json_encode(['session_id' => $sessionId, 'revision' => $revision], JSON_THROW_ON_ERROR)]);
            $database->commit();
            return ['status' => 'accepted', 'revision' => $revision, 'result' => $result, 'duplicate' => false];
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function snapshot(PDO $database, string $sessionId, string $userId): array
    {
        $member = SessionService::membership($database, $sessionId, $userId);
        if ($member === null) throw new RuntimeException('Active table membership required.');
        $sessionStatement = $database->prepare('SELECT id, title, status, host_user_id, revision, created_at, last_activity_at FROM sessions WHERE id = :id');
        $sessionStatement->execute(['id' => $sessionId]);
        $session = $sessionStatement->fetch();
        if (!is_array($session)) throw new RuntimeException('Session not found.');
        $participants = $database->prepare('SELECT id, user_id, role, seat FROM session_participants WHERE session_id = :session AND removed_at IS NULL ORDER BY created_at, id');
        $participants->execute(['session' => $sessionId]);
        $hands = $database->prepare('SELECT hand_participant_id AS participant_id, count(*) AS card_count FROM session_cards WHERE session_id = :session AND location_type = \'hand\' GROUP BY hand_participant_id');
        $hands->execute(['session' => $sessionId]);
        $handCounts = [];
        foreach ($hands as $hand) $handCounts[(string) $hand['participant_id']] = (int) $hand['card_count'];
        $containers = $database->prepare(
            "SELECT location_type, deck_id, pile_id, count(*) AS card_count
             FROM session_cards
             WHERE session_id = :session AND location_type IN ('deck', 'pile')
             GROUP BY location_type, deck_id, pile_id
             ORDER BY location_type, deck_id, pile_id",
        );
        $containers->execute(['session' => $sessionId]);
        $containerProjection = ['decks' => [], 'piles' => []];
        foreach ($containers as $container) {
            $type = (string) $container['location_type'];
            $id = $type === 'deck' ? (string) $container['deck_id'] : (string) $container['pile_id'];
            $containerProjection[$type === 'deck' ? 'decks' : 'piles'][] = ['id' => $id, 'card_count' => (int) $container['card_count']];
        }
        $zones = $database->prepare('SELECT id, name, geometry, priority, behavior FROM session_zones WHERE session_id = :session ORDER BY priority DESC, id');
        $zones->execute(['session' => $sessionId]);
        $zoneProjection = [];
        foreach ($zones as $zone) $zoneProjection[] = ['id' => (string) $zone['id'], 'name' => (string) $zone['name'], 'geometry' => json_decode((string) $zone['geometry'], true, 512, JSON_THROW_ON_ERROR), 'priority' => (int) $zone['priority'], 'behavior' => json_decode((string) $zone['behavior'], true, 512, JSON_THROW_ON_ERROR)];
        $cards = $database->prepare('SELECT id, location_type, deck_id, pile_id, hand_participant_id, card_definition_id, x, y, rotation, z_index, face_state, version FROM session_cards WHERE session_id = :session');
        $cards->execute(['session' => $sessionId]);
        $cardProjection = [];
        foreach ($cards as $card) {
            $isOwnHand = $card['location_type'] === 'hand' && (string) $card['hand_participant_id'] === (string) $member['id'];
            $isPublicFaceUp = ($card['location_type'] === 'table' || $card['location_type'] === 'pile') && $card['face_state'] === 'up';
            if ($card['location_type'] === 'deck' || $card['location_type'] === 'removed') continue;
            if ($card['location_type'] === 'hand' && !$isOwnHand) continue;
            if ($card['location_type'] === 'pile' && !$isPublicFaceUp) continue;
            $projected = [
                'id' => (string) $card['id'], 'location_type' => (string) $card['location_type'],
                'deck_id' => null, 'pile_id' => $isOwnHand || $isPublicFaceUp ? ($card['pile_id'] ? (string) $card['pile_id'] : null) : null,
                'hand_participant_id' => $isOwnHand && $card['hand_participant_id'] ? (string) $card['hand_participant_id'] : null,
                'x' => $card['x'] !== null ? (float) $card['x'] : null, 'y' => $card['y'] !== null ? (float) $card['y'] : null,
                'rotation' => (float) $card['rotation'], 'z_index' => (int) $card['z_index'], 'face_state' => (string) $card['face_state'], 'version' => (int) $card['version'],
            ];
            if ($isOwnHand || $isPublicFaceUp) $projected['card_definition_id'] = (string) $card['card_definition_id'];
            $cardProjection[] = $projected;
        }
        return [
            'revision' => (int) $session['revision'],
            'session' => ['id' => (string) $session['id'], 'title' => $session['title'], 'status' => $session['status'], 'host_user_id' => (string) $session['host_user_id'], 'created_at' => (string) $session['created_at'], 'last_activity_at' => (string) $session['last_activity_at']],
            'participants' => array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'role' => (string) $row['role'], 'is_current' => (string) $row['user_id'] === (string) $userId, 'hand_count' => $handCounts[(string) $row['id']] ?? 0], $participants->fetchAll()),
            'containers' => $containerProjection,
            'zones' => $zoneProjection,
            'cards' => $cardProjection,
        ];
    }

    public static function changes(PDO $database, string $sessionId, string $userId, int $after): array
    {
        $snapshot = self::snapshot($database, $sessionId, $userId);
        $statement = $database->prepare('SELECT revision, action_type, public_payload, created_at FROM session_events WHERE session_id = :session AND revision > :after ORDER BY revision LIMIT 100');
        $statement->execute(['session' => $sessionId, 'after' => $after]);
        $events = [];
        foreach ($statement as $row) $events[] = ['revision' => (int) $row['revision'], 'action_type' => (string) $row['action_type'], 'created_at' => (string) $row['created_at']];
        return ['from_revision' => $after, 'to_revision' => $snapshot['revision'], 'events' => $events, 'snapshot' => $snapshot];
    }

    private static function apply(PDO $database, array $session, array $member, array $user, string $type, array $payload): array
    {
        return match ($type) {
            'start_session' => self::setStatus($database, $session, $member, 'active'),
            'end_session' => self::setStatus($database, $session, $member, 'ended'),
            'leave_session' => self::leave($database, $member),
            'instantiate_deck' => self::instantiateDeck($database, $session, $member, $user, $payload),
            'draw_top' => CardService::draw($database, $session, $member, 'top', $payload),
            'draw_bottom' => CardService::draw($database, $session, $member, 'bottom', $payload),
            'draw_n' => CardService::draw($database, $session, $member, (($payload['direction'] ?? 'top') === 'bottom' ? 'bottom' : 'top'), $payload),
            'deal' => CardService::deal($database, $session, $member, $payload),
            'shuffle_deck' => CardService::shuffle($database, $session, $member, $payload),
            'cut_deck' => CardService::cut($database, $session, $member, $payload),
            'insert_cards' => CardService::insertIntoDeck($database, $session, $member, $payload),
            'split_deck' => CardService::splitDeck($database, $session, $member, $payload),
            'remove_card' => CardService::remove($database, $session, $member, $payload),
            'restore_card' => CardService::restore($database, $session, $member, $payload),
            'lock_card', 'unlock_card' => CardService::lock($database, $session, $member, $payload, $type === 'lock_card'),
            'move_cards' => CardService::moveCards($database, $session, $member, $payload),
            'reorder_hand' => CardService::reorderHand($database, $session, $member, $payload),
            'give_cards' => CardService::giveCards($database, $session, $member, $payload),
            'peek_card' => CardService::peek($database, $session, $member, $payload),
            'move_card' => CardService::moveCard($database, $session, $member, $payload),
            'flip_card', 'turn_face_up', 'turn_face_down' => CardService::face($database, $session, $member, $type, $payload),
            'move_to_hand' => CardService::moveToHand($database, $session, $member, $payload),
            'play_from_hand' => CardService::playFromHand($database, $session, $member, $payload),
            'return_top', 'return_bottom' => CardService::returnToDeck($database, $session, $member, $payload, $type === 'return_top' ? 'top' : 'bottom'),
            'create_pile' => PileService::create($database, $session, $member, $payload),
            'move_to_pile' => PileService::move($database, $session, $member, $payload),
            'shuffle_pile' => PileService::shuffle($database, $session, $member, $payload),
            'reverse_pile' => PileService::reverse($database, $session, $member, $payload, false),
            'flip_pile' => PileService::reverse($database, $session, $member, $payload, true),
            'merge_pile_top', 'merge_pile_bottom' => PileService::mergeIntoDeck($database, $session, $member, $payload, $type === 'merge_pile_top' ? 'top' : 'bottom'),
            'collect_all' => self::collectAll($database, $session, $member),
            'reset_session' => self::resetSession($database, $session, $member, $payload),
            'create_zone' => ZoneService::create($database, $session, $member, $payload),
            'delete_zone' => ZoneService::delete($database, $session, $member, $payload),
            default => throw new RuntimeException('Unsupported action type.'),
        };
    }

    private static function instantiateDeck(PDO $database, array $session, array $member, array $user, array $payload): array
    {
        if ($member['role'] !== 'host' || $session['status'] !== 'lobby') throw new RuntimeException('Only the host can configure a lobby.');
        $versionId = (string) ($payload['template_version_id'] ?? '');
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $versionId)) throw new RuntimeException('A template version is required.');
        return DeckService::instantiate($database, $session, $user, $versionId, isset($payload['label']) ? (string) $payload['label'] : null);
    }

    private static function setStatus(PDO $database, array $session, array $member, string $status): array
    {
        if ($member['role'] !== 'host') throw new RuntimeException('Host permission required.');
        $database->prepare('UPDATE sessions SET status = :status, frozen_at = CASE WHEN :set_frozen = \'true\' THEN now() ELSE frozen_at END, ended_at = CASE WHEN :set_ended = \'true\' THEN now() ELSE ended_at END WHERE id = :id')
            ->execute(['status' => $status, 'set_frozen' => $status === 'active' ? 'true' : 'false', 'set_ended' => $status === 'ended' ? 'true' : 'false', 'id' => $session['id']]);
        return ['session_id' => (string) $session['id'], 'status' => $status];
    }

    private static function leave(PDO $database, array $member): array
    {
        $database->prepare('UPDATE session_participants SET removed_at = now() WHERE id = :id')->execute(['id' => $member['id']]);
        return ['participant_id' => (string) $member['id'], 'removed' => true];
    }

    private static function collectAll(PDO $database, array $session, array $member): array
    {
        if ($member['role'] !== 'host') throw new RuntimeException('Host permission required.');
        $cards = $database->prepare('SELECT id, source_deck_id FROM session_cards WHERE session_id = :session FOR UPDATE');
        $cards->execute(['session' => $session['id']]);
        $rows = $cards->fetchAll();
        $update = $database->prepare("UPDATE session_cards SET location_type = 'deck', deck_id = source_deck_id, pile_id = NULL, hand_participant_id = NULL, order_key = :temporary, x = NULL, y = NULL, face_state = 'down', owner_user_id = NULL, locked_by = NULL, version = version + 1 WHERE id = :id");
        foreach ($rows as $index => $row) $update->execute(['temporary' => -2000000000 + $index, 'id' => $row['id']]);
        $database->prepare('DELETE FROM session_piles WHERE session_id = :session')->execute(['session' => $session['id']]);
        $decks = $database->prepare('SELECT id FROM session_decks WHERE session_id = :session ORDER BY id FOR UPDATE');
        $decks->execute(['session' => $session['id']]);
        $order = $database->prepare('SELECT c.id FROM session_cards c JOIN card_definitions d ON d.id = c.card_definition_id WHERE c.session_id = :session AND c.deck_id = :deck ORDER BY d.ordinal, c.id');
        $assign = $database->prepare('UPDATE session_cards SET order_key = :order WHERE id = :id');
        $count = 0;
        foreach ($decks as $deck) { $order->execute(['session' => $session['id'], 'deck' => $deck['id']]); foreach ($order->fetchAll() as $index => $row) { $assign->execute(['order' => ($index + 1) * 1000, 'id' => $row['id']]); $count++; } }
        return ['collected_cards' => $count];
    }

    private static function resetSession(PDO $database, array $session, array $member, array $payload): array
    {
        if ($member['role'] !== 'host') throw new RuntimeException('Host permission required.');
        $result = self::collectAll($database, $session, $member);
        $database->prepare("UPDATE sessions SET status = 'lobby', frozen_at = NULL, ended_at = NULL WHERE id = :id")->execute(['id' => $session['id']]);
        if (($payload['shuffle'] ?? false) === true) {
            $decks = $database->prepare('SELECT id FROM session_decks WHERE session_id = :session'); $decks->execute(['session' => $session['id']]);
            foreach ($decks as $deck) CardService::shuffle($database, $session, $member, ['deck_id' => $deck['id']]);
        }
        return ['reset' => true, 'shuffled' => ($payload['shuffle'] ?? false) === true, 'collected_cards' => $result['collected_cards']];
    }

    private static function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) return '[' . implode(',', array_map([self::class, 'canonicalJson'], $value)) . ']';
            ksort($value);
            $parts = [];
            foreach ($value as $key => $item) $parts[] = json_encode((string) $key, JSON_THROW_ON_ERROR) . ':' . self::canonicalJson($item);
            return '{' . implode(',', $parts) . '}';
        }
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
