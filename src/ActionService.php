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
            $sessionStatement = $database->prepare('SELECT * FROM sessions WHERE id = :id FOR UPDATE');
            $sessionStatement->execute(['id' => $sessionId]);
            $session = $sessionStatement->fetch();
            if (!is_array($session)) throw new RuntimeException('Session not found.');
            $member = SessionService::membership($database, $sessionId, (string) $user['id']);
            if ($member === null) throw new RuntimeException('Active table membership required.');
            if (filter_var($session['interaction_frozen'], FILTER_VALIDATE_BOOLEAN) && !self::allowedWhileFrozen($type, $member)) {
                throw new RuntimeException('Table interaction is frozen by the host.');
            }
            foreach (self::requiredCapabilities($type, $payload) as $requiredCapability) {
                if (!SessionService::hasCapability($member, $requiredCapability)) throw new RuntimeException('This participant is not allowed to perform that action.');
            }
            LockService::assertActionAllowed($database, $session, $member, $type, $payload);
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
            if ($session['status'] === 'ended' && !in_array($type, ['leave_session', 'reset_session'], true)) throw new RuntimeException('Session has ended.');
            if (in_array($type, ['create_zone', 'update_zone', 'delete_zone', 'lock_zone', 'unlock_zone', 'lock_table', 'unlock_table', 'freeze_table', 'unfreeze_table'], true) && (!array_key_exists('expected_session_revision', $request) || !is_int($request['expected_session_revision']))) {
                throw new RuntimeException('Expected session revision is required for zone, table lock, and freeze changes.');
            }
            if (array_key_exists('expected_session_revision', $request) && $request['expected_session_revision'] !== null && (int) $request['expected_session_revision'] !== (int) $session['revision']) {
                throw new RuntimeException('Session changed; refresh and try again.');
            }
            $result = self::apply($database, $session, $member, $user, $type, $payload);
            $noChange = ($result['_no_change'] ?? false) === true;
            unset($result['_no_change']);
            $revision = (int) $session['revision'];
            if (!$noChange) {
                $database->prepare('UPDATE sessions SET revision = revision + 1, last_activity_at = now() WHERE id = :id')
                    ->execute(['id' => $sessionId]);
                $revision++;
                $event = $database->prepare(
                    'INSERT INTO session_events(session_id, revision, actor_user_id, action_type, public_payload)
                     VALUES (:session, :revision, :actor, :type, CAST(:payload AS jsonb))',
                );
                $event->execute(['session' => $sessionId, 'revision' => $revision, 'actor' => $user['id'], 'type' => $type, 'payload' => json_encode(self::sanitizeEvent($type, $result), JSON_THROW_ON_ERROR)]);
            }
            $stored = $database->prepare(
                'INSERT INTO processed_actions(session_id, actor_user_id, action_id, request_hash, revision, status, result)
                 VALUES (:session, :actor, :action, :hash, :revision, \'accepted\', CAST(:result AS jsonb))',
            );
            $stored->execute(['session' => $sessionId, 'actor' => $user['id'], 'action' => $actionId, 'hash' => $requestHash, 'revision' => $revision, 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);
            if (!$noChange) {
                $database->prepare("SELECT pg_notify('decks_session_changed', :payload)")
                    ->execute(['payload' => json_encode(['session_id' => $sessionId, 'revision' => $revision], JSON_THROW_ON_ERROR)]);
            }
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
        $sessionStatement = $database->prepare('SELECT id, title, status, host_user_id, revision, created_at, last_activity_at, access_settings, locked_by, interaction_frozen FROM sessions WHERE id = :id');
        $sessionStatement->execute(['id' => $sessionId]);
        $session = $sessionStatement->fetch();
        if (!is_array($session)) throw new RuntimeException('Session not found.');
        $participants = $database->prepare('SELECT id, user_id, role, seat, capabilities FROM session_participants WHERE session_id = :session AND removed_at IS NULL ORDER BY created_at, id');
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
        $decks = $database->prepare(
            "SELECT d.id, d.label, d.version, d.locked_by, count(c.id) AS card_count
             FROM session_decks d
             LEFT JOIN session_cards c ON c.session_id = d.session_id AND c.location_type = 'deck' AND c.deck_id = d.id
             WHERE d.session_id = :session
             GROUP BY d.id, d.label, d.version, d.locked_by
             ORDER BY d.created_at, d.id",
        );
        $decks->execute(['session' => $sessionId]);
        $containerProjection['decks'] = [];
        foreach ($decks as $deck) $containerProjection['decks'][] = ['id' => (string) $deck['id'], 'label' => $deck['label'] !== null ? (string) $deck['label'] : null, 'card_count' => (int) $deck['card_count'], 'version' => (int) $deck['version'], 'locked' => $deck['locked_by'] !== null, 'locked_by_current' => $deck['locked_by'] !== null && (string) $deck['locked_by'] === $userId];
        $pileMeta = $database->prepare('SELECT id, label, x, y, rotation, z_index, locked_by, version FROM session_piles WHERE session_id = :session ORDER BY z_index, id');
        $pileMeta->execute(['session' => $sessionId]);
        $pileById = [];
        foreach ($pileMeta as $pile) {
            $pileById[(string) $pile['id']] = ['id' => (string) $pile['id'], 'card_count' => 0, 'label' => $pile['label'] !== null ? (string) $pile['label'] : null, 'x' => (float) $pile['x'], 'y' => (float) $pile['y'], 'rotation' => (float) $pile['rotation'], 'z_index' => (int) $pile['z_index'], 'locked' => $pile['locked_by'] !== null, 'locked_by_current' => $pile['locked_by'] !== null && (string) $pile['locked_by'] === $userId, 'version' => (int) $pile['version']];
        }
        foreach ($containerProjection['piles'] as $pile) if (isset($pileById[$pile['id']])) $pileById[$pile['id']]['card_count'] = $pile['card_count'];
        $containerProjection['piles'] = array_values($pileById);
        $zones = $database->prepare('SELECT id, name, geometry, priority, behavior, locked_by FROM session_zones WHERE session_id = :session ORDER BY priority DESC, id');
        $zones->execute(['session' => $sessionId]);
        $zoneProjection = [];
        foreach ($zones as $zone) $zoneProjection[] = ['id' => (string) $zone['id'], 'name' => (string) $zone['name'], 'geometry' => json_decode((string) $zone['geometry'], true, 512, JSON_THROW_ON_ERROR), 'priority' => (int) $zone['priority'], 'behavior' => json_decode((string) $zone['behavior'], true, 512, JSON_THROW_ON_ERROR), 'locked' => $zone['locked_by'] !== null, 'locked_by_current' => $zone['locked_by'] !== null && (string) $zone['locked_by'] === $userId];
        $cards = $database->prepare("SELECT c.id, c.location_type, c.deck_id, c.pile_id, c.hand_participant_id, c.card_definition_id, d.display_name, CASE WHEN COALESCE(d.back_asset_id, v.default_back_asset_id) IS NOT NULL THEN 1 ELSE 0 END AS has_back_art, c.x, c.y, c.rotation, c.z_index, c.order_key, c.face_state, c.owner_user_id, c.locked_by, c.version FROM session_cards c LEFT JOIN card_definitions d ON d.id = c.card_definition_id LEFT JOIN deck_template_versions v ON v.id = d.template_version_id WHERE c.session_id = :session");
        $cards->execute(['session' => $sessionId]);
        $cardProjection = [];
        foreach ($cards as $card) {
            $isOwnHand = $card['location_type'] === 'hand' && (string) $card['hand_participant_id'] === (string) $member['id'];
            $isOwnPrivateTable = $card['location_type'] === 'table' && $card['face_state'] === 'private' && (string) $card['owner_user_id'] === (string) $userId;
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
                'locked' => $card['locked_by'] !== null, 'locked_by_current' => $card['locked_by'] !== null && (string) $card['locked_by'] === $userId,
            ];
            if ($isOwnHand) $projected['hand_order'] = (int) $card['order_key'];
            if ($card['location_type'] === 'table' && $card['face_state'] !== 'up' && !$isOwnPrivateTable && (int) $card['has_back_art'] === 1) $projected['has_back_art'] = true;
            if ($isOwnHand || $isOwnPrivateTable || $isPublicFaceUp) {
                $projected['card_definition_id'] = (string) $card['card_definition_id'];
                if ($card['display_name'] !== null && trim((string) $card['display_name']) !== '') $projected['card_label'] = trim((string) $card['display_name']);
            }
            $cardProjection[] = $projected;
        }
        $removedCardProjection = [];
        if (($member['role'] ?? '') === 'host' && SessionService::hasCapability($member, 'card.manage')) {
            $removedCards = $database->prepare("SELECT id, version FROM session_cards WHERE session_id = :session AND location_type = 'removed' ORDER BY id");
            $removedCards->execute(['session' => $sessionId]);
            foreach ($removedCards as $removedCard) $removedCardProjection[] = ['id' => (string) $removedCard['id'], 'version' => (int) $removedCard['version']];
        }
        $removedParticipantProjection = [];
        if (($member['role'] ?? '') === 'host' && SessionService::hasCapability($member, 'participant.manage')) {
            $removedParticipants = $database->prepare("SELECT id, role FROM session_participants WHERE session_id = :session AND removed_at IS NOT NULL AND role <> 'host' ORDER BY removed_at, id");
            $removedParticipants->execute(['session' => $sessionId]);
            foreach ($removedParticipants as $removedParticipant) $removedParticipantProjection[] = ['id' => (string) $removedParticipant['id'], 'role' => (string) $removedParticipant['role']];
        }
        return [
            'revision' => (int) $session['revision'],
            'session' => ['id' => (string) $session['id'], 'title' => $session['title'], 'status' => (string) $session['status'], 'host_user_id' => (string) $session['host_user_id'], 'created_at' => (string) $session['created_at'], 'last_activity_at' => (string) $session['last_activity_at'], 'locked' => $session['locked_by'] !== null, 'locked_by_current' => $session['locked_by'] !== null && (string) $session['locked_by'] === $userId, 'interaction_frozen' => filter_var($session['interaction_frozen'], FILTER_VALIDATE_BOOLEAN)],
            'configuration' => json_decode((string) $session['access_settings'], true, 512, JSON_THROW_ON_ERROR),
            'participants' => array_map(static function (array $row) use ($userId, $handCounts, $member): array {
                $isCurrent = (string) $row['user_id'] === (string) $userId;
                $projection = ['id' => (string) $row['id'], 'role' => (string) $row['role'], 'is_current' => $isCurrent, 'hand_count' => $handCounts[(string) $row['id']] ?? 0];
                if ($isCurrent || ($member['role'] ?? '') === 'host') $projection['capabilities'] = SessionService::capabilities($row);
                return $projection;
            }, $participants->fetchAll()),
            'containers' => $containerProjection,
            'zones' => $zoneProjection,
            'cards' => $cardProjection,
            'removed_cards' => $removedCardProjection,
            'removed_participants' => $removedParticipantProjection,
        ];
    }

    public static function changes(PDO $database, string $sessionId, string $userId, int $after): array
    {
        $snapshot = self::snapshot($database, $sessionId, $userId);
        $statement = $database->prepare('SELECT revision, action_type, actor_user_id, created_at FROM session_events WHERE session_id = :session AND revision > :after ORDER BY revision LIMIT 100');
        $statement->execute(['session' => $sessionId, 'after' => $after]);
        $events = [];
        foreach ($statement as $row) $events[] = [
            'revision' => (int) $row['revision'],
            'action_type' => (string) $row['action_type'],
            'actor' => $row['actor_user_id'] !== null && (string) $row['actor_user_id'] === $userId ? 'you' : 'participant',
            'created_at' => (string) $row['created_at'],
        ];
        return ['from_revision' => $after, 'to_revision' => $snapshot['revision'], 'events' => $events, 'snapshot' => $snapshot];
    }

    public static function capabilityForAction(string $type): ?string
    {
        return match ($type) {
            'leave_session' => null,
            'start_session', 'end_session', 'reset_session', 'collect_all', 'configure_table', 'instantiate_deck', 'freeze_table', 'unfreeze_table' => 'session.manage',
            'transfer_host', 'remove_participant', 'restore_participant', 'set_participant_capabilities' => 'participant.manage',
            'create_zone', 'update_zone', 'delete_zone' => 'zone.manage',
            'draw_top', 'draw_bottom', 'draw_n', 'return_top', 'return_bottom', 'return_to_source_decks', 'shuffle_deck', 'cut_deck', 'insert_cards', 'split_deck', 'deal' => 'deck.manage',
            'move_card', 'move_cards', 'rotate_card', 'rotate_cards', 'set_cards_face', 'reorder_cards', 'flip_card', 'turn_face_up', 'turn_face_down', 'move_to_hand', 'play_from_hand', 'reorder_hand', 'give_cards', 'peek_card', 'remove_card', 'restore_card' => 'card.manage',
            'create_pile', 'move_to_pile', 'draw_pile_top', 'draw_pile_bottom', 'split_pile', 'merge_piles', 'collect_spread', 'move_pile', 'rotate_pile', 'label_pile', 'shuffle_pile', 'reverse_pile', 'flip_pile', 'spread_pile', 'merge_pile_top', 'merge_pile_bottom', 'merge_pile_shuffle' => 'pile.manage',
            'lock_card', 'unlock_card', 'lock_pile', 'unlock_pile', 'lock_deck', 'unlock_deck', 'lock_zone', 'unlock_zone', 'lock_table', 'unlock_table' => 'lock.manage',
            'undo_action' => 'card.undo',
            default => null,
        };
    }

    private static function requiredCapabilities(string $type, array $payload): array
    {
        $required = [];
        $base = self::capabilityForAction($type);
        if ($base !== null) $required[] = $base;
        $additional = match ($type) {
            'return_top', 'return_bottom', 'return_to_source_decks', 'insert_cards', 'draw_pile_top', 'draw_pile_bottom', 'move_to_pile', 'collect_spread', 'spread_pile', 'lock_card', 'unlock_card' => ['card.manage'],
            'split_deck', 'lock_pile', 'unlock_pile' => ['pile.manage'],
            'lock_deck', 'unlock_deck' => ['deck.manage'],
            'lock_zone', 'unlock_zone' => ['zone.manage'],
            'lock_table', 'unlock_table' => ['session.manage'],
            'merge_pile_top', 'merge_pile_bottom', 'merge_pile_shuffle' => ['deck.manage'],
            default => [],
        };
        foreach ($additional as $capability) $required[] = $capability;
        if (in_array($type, ['draw_top', 'draw_bottom', 'draw_n'], true) && ($payload['target'] ?? 'table') === 'pile') $required[] = 'pile.manage';
        return array_values(array_unique($required));
    }

    private static function allowedWhileFrozen(string $type, array $member): bool
    {
        if ($type === 'leave_session') return true;
        if (($member['role'] ?? '') !== 'host') return false;
        return in_array($type, [
            'freeze_table', 'unfreeze_table', 'end_session', 'reset_session',
            'transfer_host', 'remove_participant', 'restore_participant', 'set_participant_capabilities', 'unlock_table',
        ], true);
    }

    private static function apply(PDO $database, array $session, array $member, array $user, string $type, array $payload): array
    {
        return match ($type) {
            'start_session' => self::setStatus($database, $session, $member, 'active'),
            'end_session' => self::setStatus($database, $session, $member, 'ended'),
            'freeze_table', 'unfreeze_table' => self::setInteractionFrozen($database, $session, $member, $type === 'freeze_table'),
            'leave_session' => self::leave($database, $member),
            'transfer_host' => SessionService::transferHost($database, $session, $member, $payload),
            'remove_participant' => SessionService::removeParticipant($database, $session, $member, $payload),
            'restore_participant' => SessionService::restoreParticipant($database, $session, $member, $payload),
            'set_participant_capabilities' => SessionService::setCapabilities($database, $session, $member, $payload),
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
            'rotate_cards' => CardService::rotateCards($database, $session, $member, $payload),
            'set_cards_face' => CardService::setCardsFace($database, $session, $member, $payload),
            'reorder_cards' => CardService::reorderCards($database, $session, $member, $payload),
            'reorder_hand' => CardService::reorderHand($database, $session, $member, $payload),
            'give_cards' => CardService::giveCards($database, $session, $member, $payload),
            'peek_card' => CardService::peek($database, $session, $member, $payload),
            'move_card' => CardService::moveCard($database, $session, $member, $payload),
            'rotate_card' => CardService::rotateCard($database, $session, $member, $payload),
            'undo_action' => self::undoAction($database, $session, $member, $user, $payload),
            'flip_card', 'turn_face_up', 'turn_face_down' => CardService::face($database, $session, $member, $type, $payload),
            'move_to_hand' => CardService::moveToHand($database, $session, $member, $payload),
            'play_from_hand' => CardService::playFromHand($database, $session, $member, $payload),
            'return_top', 'return_bottom' => CardService::returnToDeck($database, $session, $member, $payload, $type === 'return_top' ? 'top' : 'bottom'),
            'return_to_source_decks' => CardService::returnToSourceDecks($database, $session, $member, $payload),
            'create_pile' => PileService::create($database, $session, $member, $payload),
            'move_to_pile' => PileService::move($database, $session, $member, $payload),
            'draw_pile_top', 'draw_pile_bottom' => PileService::draw($database, $session, $member, $payload, $type === 'draw_pile_top' ? 'top' : 'bottom'),
            'split_pile' => PileService::split($database, $session, $member, $payload),
            'merge_piles' => PileService::merge($database, $session, $member, $payload),
            'collect_spread' => PileService::collectSpread($database, $session, $member, $payload),
            'move_pile', 'rotate_pile' => PileService::updateGeometry($database, $session, $member, $payload),
            'label_pile' => PileService::label($database, $session, $member, $payload),
            'lock_pile', 'unlock_pile' => PileService::lock($database, $session, $member, $payload, $type === 'lock_pile'),
            'lock_deck', 'unlock_deck' => LockService::change($database, $session, $member, $payload, 'deck', $type === 'lock_deck'),
            'lock_zone', 'unlock_zone' => LockService::change($database, $session, $member, $payload, 'zone', $type === 'lock_zone'),
            'lock_table', 'unlock_table' => LockService::change($database, $session, $member, $payload, 'table', $type === 'lock_table'),
            'shuffle_pile' => PileService::shuffle($database, $session, $member, $payload),
            'reverse_pile' => PileService::reverse($database, $session, $member, $payload, false),
            'flip_pile' => PileService::reverse($database, $session, $member, $payload, true),
            'spread_pile' => PileService::spread($database, $session, $member, $payload),
            'merge_pile_top', 'merge_pile_bottom', 'merge_pile_shuffle' => PileService::mergeIntoDeck($database, $session, $member, $payload, match ($type) { 'merge_pile_top' => 'top', 'merge_pile_bottom' => 'bottom', default => 'shuffle' }),
            'collect_all' => self::collectAll($database, $session, $member, $payload),
            'reset_session' => self::resetSession($database, $session, $member, $payload),
            'create_zone' => ZoneService::create($database, $session, $member, $payload),
            'update_zone' => ZoneService::update($database, $session, $member, $payload),
            'delete_zone' => ZoneService::delete($database, $session, $member, $payload),
            'configure_table' => self::configureTable($database, $session, $member, $user, $payload),
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

    private static function setInteractionFrozen(PDO $database, array $session, array $member, bool $freeze): array
    {
        if (($member['role'] ?? '') !== 'host' || $session['status'] === 'ended') throw new RuntimeException('Only the host can freeze an available table.');
        $current = filter_var($session['interaction_frozen'], FILTER_VALIDATE_BOOLEAN);
        if ($current === $freeze) return ['session_id' => (string) $session['id'], 'interaction_frozen' => $freeze, '_no_change' => true];
        $database->prepare('UPDATE sessions SET interaction_frozen = :frozen WHERE id = :id')
            ->execute(['frozen' => $freeze, 'id' => $session['id']]);
        return ['session_id' => (string) $session['id'], 'interaction_frozen' => $freeze];
    }

    private static function leave(PDO $database, array $member): array
    {
        $database->prepare('UPDATE session_participants SET removed_at = now() WHERE id = :id')->execute(['id' => $member['id']]);
        return ['participant_id' => (string) $member['id'], 'removed' => true];
    }

    private static function collectAll(PDO $database, array $session, array $member, array $payload = []): array
    {
        if ($member['role'] !== 'host') throw new RuntimeException('Host permission required.');
        $mode = (string) ($payload['mode'] ?? 'original');
        if (!in_array($mode, ['original', 'shuffle', 'preserve'], true)) throw new RuntimeException('Collect mode is invalid.');
        $cards = $database->prepare('SELECT id, source_deck_id FROM session_cards WHERE session_id = :session FOR UPDATE');
        $cards->execute(['session' => $session['id']]);
        $rows = $cards->fetchAll();
        $update = $database->prepare("UPDATE session_cards SET location_type = 'deck', deck_id = source_deck_id, pile_id = NULL, hand_participant_id = NULL, order_key = :temporary, x = NULL, y = NULL, face_state = 'down', owner_user_id = NULL, locked_by = NULL, version = version + 1 WHERE id = :id");
        foreach ($rows as $index => $row) $update->execute(['temporary' => -2000000000 + $index, 'id' => $row['id']]);
        $database->prepare('DELETE FROM session_piles WHERE session_id = :session')->execute(['session' => $session['id']]);
        $decks = $database->prepare('SELECT id FROM session_decks WHERE session_id = :session ORDER BY id FOR UPDATE');
        $decks->execute(['session' => $session['id']]);
        $orderSql = $mode === 'preserve'
            ? "SELECT c.id FROM session_cards c WHERE c.session_id = :session AND c.deck_id = :deck ORDER BY c.order_key NULLS LAST, c.id"
            : 'SELECT c.id FROM session_cards c JOIN card_definitions d ON d.id = c.card_definition_id WHERE c.session_id = :session AND c.deck_id = :deck ORDER BY d.ordinal, c.id';
        $order = $database->prepare($orderSql);
        $assign = $database->prepare('UPDATE session_cards SET order_key = :order WHERE id = :id');
        $count = 0;
        foreach ($decks as $deck) {
            $order->execute(['session' => $session['id'], 'deck' => $deck['id']]);
            $deckRows = $order->fetchAll();
            foreach ($deckRows as $index => $row) { $assign->execute(['order' => ($index + 1) * 1000, 'id' => $row['id']]); $count++; }
            if ($mode === 'shuffle') CardService::shuffle($database, $session, $member, ['deck_id' => (string) $deck['id']]);
            else $database->prepare('UPDATE session_decks SET version = version + 1 WHERE id = :id')->execute(['id' => $deck['id']]);
        }
        return ['collected_cards' => $count, 'mode' => $mode, 'randomized' => $mode === 'shuffle'];
    }

    private static function resetSession(PDO $database, array $session, array $member, array $payload): array
    {
        if ($member['role'] !== 'host') throw new RuntimeException('Host permission required.');
        $database->prepare('UPDATE sessions SET locked_by = NULL WHERE id = :id')->execute(['id' => $session['id']]);
        $database->prepare('UPDATE session_decks SET locked_by = NULL, version = version + 1 WHERE session_id = :session')->execute(['session' => $session['id']]);
        $database->prepare('UPDATE session_zones SET locked_by = NULL, locked = false WHERE session_id = :session')->execute(['session' => $session['id']]);
        $result = self::collectAll($database, $session, $member);
        $database->prepare("UPDATE sessions SET status = 'lobby', frozen_at = NULL, interaction_frozen = false, ended_at = NULL WHERE id = :id")->execute(['id' => $session['id']]);
        $initial = $database->prepare('SELECT initial_state FROM sessions WHERE id = :id');
        $initial->execute(['id' => $session['id']]);
        $initialState = json_decode((string) $initial->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        if (is_array($initialState) && is_array($initialState['configuration'] ?? null)) {
            $configuration = $initialState['configuration'];
            $presetId = isset($configuration['preset_id']) && is_string($configuration['preset_id']) ? $configuration['preset_id'] : null;
            $database->prepare('UPDATE sessions SET access_settings = CAST(:settings AS jsonb), preset_id = :preset WHERE id = :id')->execute(['settings' => json_encode($configuration, JSON_THROW_ON_ERROR), 'preset' => $presetId, 'id' => $session['id']]);
            $database->prepare('DELETE FROM session_zones WHERE session_id = :session')->execute(['session' => $session['id']]);
            $insertZone = $database->prepare('INSERT INTO session_zones(session_id, name, geometry, priority, behavior, owner_user_id) VALUES (:session, :name, CAST(:geometry AS jsonb), :priority, CAST(:behavior AS jsonb), :owner)');
            foreach (($initialState['zones'] ?? []) as $zone) $insertZone->execute(['session' => $session['id'], 'name' => $zone['name'], 'geometry' => json_encode($zone['geometry'], JSON_THROW_ON_ERROR), 'priority' => (int) $zone['priority'], 'behavior' => json_encode($zone['behavior'], JSON_THROW_ON_ERROR), 'owner' => $member['user_id']]);
        }
        if (($payload['shuffle'] ?? false) === true) {
            $decks = $database->prepare('SELECT id FROM session_decks WHERE session_id = :session'); $decks->execute(['session' => $session['id']]);
            foreach ($decks as $deck) CardService::shuffle($database, $session, $member, ['deck_id' => $deck['id']]);
        }
        return ['reset' => true, 'shuffled' => ($payload['shuffle'] ?? false) === true, 'collected_cards' => $result['collected_cards'], 'initial_state_restored' => is_array($initialState) && isset($initialState['configuration'])];
    }

    private static function configureTable(PDO $database, array $session, array $member, array $user, array $payload): array
    {
        if ($member['role'] !== 'host' || $session['status'] !== 'lobby') throw new RuntimeException('Only the host can configure a lobby.');
        $configuration = ['mat' => [], 'preset_id' => null];
        $preset = null;
        if (isset($payload['preset_id']) && $payload['preset_id'] !== null) {
            $preset = MatPresetService::normalizedPreset($database, $user, (string) $payload['preset_id']);
            $configuration = ['mat' => $preset['mat'], 'preset_id' => $preset['preset_id'], 'template_version_id' => $preset['template_version_id'], 'options' => $preset['configuration']['options'] ?? []];
            $database->prepare('UPDATE sessions SET template_version_id = :template, preset_id = :preset WHERE id = :id')->execute(['template' => $preset['template_version_id'], 'preset' => $preset['preset_id'], 'id' => $session['id']]);
            $database->prepare('DELETE FROM session_zones WHERE session_id = :session')->execute(['session' => $session['id']]);
            $insertZone = $database->prepare('INSERT INTO session_zones(session_id, name, geometry, priority, behavior, owner_user_id) VALUES (:session, :name, CAST(:geometry AS jsonb), :priority, CAST(:behavior AS jsonb), :owner)');
            foreach (($preset['configuration']['zones'] ?? []) as $zone) $insertZone->execute(['session' => $session['id'], 'name' => $zone['name'], 'geometry' => json_encode($zone['geometry'], JSON_THROW_ON_ERROR), 'priority' => $zone['priority'], 'behavior' => json_encode($zone['behavior'], JSON_THROW_ON_ERROR), 'owner' => $user['id']]);
        } else {
            $database->prepare('UPDATE sessions SET preset_id = NULL WHERE id = :id')->execute(['id' => $session['id']]);
        }
        if (isset($payload['mat'])) {
            if (!is_array($payload['mat'])) throw new RuntimeException('Mat configuration is invalid.');
            $label = trim((string) ($payload['mat']['label'] ?? '')); $color = strtolower(trim((string) ($payload['mat']['color'] ?? '')));
            if ($label !== '' && strlen($label) > 120) throw new RuntimeException('Mat label is invalid.');
            if ($color !== '' && !preg_match('/^#[0-9a-f]{6}$/', $color)) throw new RuntimeException('Mat color is invalid.');
            $configuration['mat'] = array_filter(['label' => $label ?: null, 'color' => $color ?: null], static fn (mixed $value): bool => $value !== null);
        }
        if (isset($payload['preset_id']) && $payload['preset_id'] !== null && !preg_match('/^[0-9a-fA-F-]{36}$/', (string) $payload['preset_id'])) throw new RuntimeException('Preset reference is invalid.');
        if ($preset === null) $configuration['preset_id'] = null;
        $database->prepare('UPDATE sessions SET access_settings = CAST(:settings AS jsonb) WHERE id = :id')->execute(['settings' => json_encode($configuration, JSON_THROW_ON_ERROR), 'id' => $session['id']]);
        self::captureInitialState($database, (string) $session['id'], $configuration);
        return ['configuration' => $configuration];
    }

    private static function undoAction(PDO $database, array $session, array $member, array $user, array $payload): array
    {
        if (!in_array($member['role'], ['host', 'player'], true)) throw new RuntimeException('Player permission required.');
        $targetId = (string) ($payload['action_id'] ?? '');
        $statement = $database->prepare('SELECT action_id, result FROM processed_actions WHERE session_id = :session AND actor_user_id = :actor AND action_id = :action');
        $statement->execute(['session' => $session['id'], 'actor' => $user['id'], 'action' => $targetId]);
        $target = $statement->fetch();
        if (!is_array($target)) throw new RuntimeException('Undo target is unavailable.');
        $result = json_decode((string) $target['result'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result) || !is_array($result['undo'] ?? null)) throw new RuntimeException('That action cannot be undone.');
        $inverse = $result['undo'];
        $cardId = (string) ($inverse['card_id'] ?? '');
        $card = $database->prepare("SELECT id, location_type, x, y, rotation, z_index, face_state, owner_user_id, locked_by, version FROM session_cards WHERE session_id = :session AND id = :id FOR UPDATE");
        $card->execute(['session' => $session['id'], 'id' => $cardId]);
        $current = $card->fetch();
        if (!is_array($current) || $current['location_type'] !== 'table') throw new RuntimeException('Undo target is no longer on the table.');
        if ($current['locked_by'] !== null && (string) $current['locked_by'] !== (string) $member['user_id']) throw new RuntimeException('That card is locked.');
        if ((int) $current['version'] !== (int) ($inverse['expected_version'] ?? -1)) throw new RuntimeException('The card changed; undo is no longer safe.');
        $previous = $inverse['previous'] ?? null;
        if (!is_array($previous)) throw new RuntimeException('Undo target is invalid.');
        LockService::assertActionAllowed($database, $session, $member, 'move_card', ['card_id' => $cardId, 'x' => $previous['x'] ?? null, 'y' => $previous['y'] ?? null]);
        $database->prepare('UPDATE session_cards SET x=:x, y=:y, rotation=:rotation, z_index=:z, face_state=:face, owner_user_id=:owner, version=version+1 WHERE id=:id')
            ->execute(['x' => $previous['x'], 'y' => $previous['y'], 'rotation' => $previous['rotation'], 'z' => $previous['z_index'], 'face' => $previous['face_state'], 'owner' => $previous['owner_user_id'], 'id' => $cardId]);
        return ['undone_action_id' => $targetId, 'card_id' => $cardId, 'version' => (int) $current['version'] + 1];
    }

    public static function captureInitialState(PDO $database, string $sessionId, array $configuration): void
    {
        $session = $database->prepare("SELECT status FROM sessions WHERE id = :id FOR UPDATE");
        $session->execute(['id' => $sessionId]);
        if ((string) $session->fetchColumn() !== 'lobby') return;
        $zones = $database->prepare('SELECT name, geometry, priority, behavior FROM session_zones WHERE session_id = :session ORDER BY priority DESC, id');
        $zones->execute(['session' => $sessionId]);
        $projection = [];
        foreach ($zones as $zone) $projection[] = ['name' => (string) $zone['name'], 'geometry' => json_decode((string) $zone['geometry'], true, 512, JSON_THROW_ON_ERROR), 'priority' => (int) $zone['priority'], 'behavior' => json_decode((string) $zone['behavior'], true, 512, JSON_THROW_ON_ERROR)];
        $database->prepare('UPDATE sessions SET initial_state = CAST(:state AS jsonb) WHERE id = :id')->execute(['state' => json_encode(['configuration' => $configuration, 'zones' => $projection], JSON_THROW_ON_ERROR), 'id' => $sessionId]);
    }

    /** Keep durable activity metadata useful without retaining hidden card identities or peek results. */
    private static function sanitizeEvent(string $type, array $result): array
    {
        $sanitize = static function (mixed $value) use (&$sanitize): mixed {
            if (is_array($value)) {
                $clean = [];
                foreach ($value as $key => $item) {
                    if (in_array((string) $key, ['card_id', 'card_ids', 'card_definition_id', 'cards', 'secret', 'token', 'owner_user_id', 'undo', 'capabilities', 'participant_id', 'recipient_participant_id', 'hand_participant_id', 'user_id'], true)) continue;
                    $clean[$key] = $sanitize($item);
                }
                return $clean;
            }
            return $value;
        };
        $clean = $sanitize($result);
        if ($type === 'peek_card') return ['action' => 'peek_card', 'revealed' => true];
        return is_array($clean) ? $clean : ['action' => $type];
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
