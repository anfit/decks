<?php

declare(strict_types=1);

namespace Decks;

use PDO;
use RuntimeException;

final class PileService
{
    public static function create(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $label = isset($payload['label']) ? trim((string) $payload['label']) : null;
        if ($label !== null && ($label === '' || strlen($label) > 160)) throw new RuntimeException('Pile label is invalid.');
        $statement = $database->prepare('INSERT INTO session_piles(session_id, label, x, y, rotation, z_index) VALUES (:session, :label, :x, :y, :rotation, :z) RETURNING id');
        $statement->execute(['session' => $session['id'], 'label' => $label, 'x' => (float) ($payload['x'] ?? 0), 'y' => (float) ($payload['y'] ?? 0), 'rotation' => (float) ($payload['rotation'] ?? 0), 'z' => (int) ($payload['z_index'] ?? 0)]);
        return ['pile_id' => (string) $statement->fetchColumn(), 'label' => $label];
    }

    public static function move(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $pileId = (string) ($payload['pile_id'] ?? '');
        $pile = self::pile($database, $session['id'], $pileId);
        self::assertPileUnlocked($pile, $member);
        if (!array_key_exists('expected_pile_version', $payload) || filter_var($payload['expected_pile_version'], FILTER_VALIDATE_INT) === false) throw new RuntimeException('Expected pile version is required.');
        if ((int) $payload['expected_pile_version'] !== (int) $pile['version']) throw new RuntimeException('Pile changed; refresh and try again.');
        $ids = $payload['card_ids'] ?? [];
        if (!is_array($ids) || count($ids) < 1 || count($ids) > 100 || count(array_unique(array_map('strval', $ids))) !== count($ids)) throw new RuntimeException('Card selection is invalid.');
        $versions = $payload['expected_card_versions'] ?? null;
        if (!is_array($versions) || count($versions) !== count($ids)) throw new RuntimeException('Expected card versions are required.');
        $cards = []; $sourcePiles = [];
        foreach ($ids as $id) {
            $cardId = (string) $id;
            if (!array_key_exists($cardId, $versions) || filter_var($versions[$cardId], FILTER_VALIDATE_INT) === false) throw new RuntimeException('Expected card versions are required.');
            $card = self::card($database, $session['id'], (string) $id);
            if ((int) $versions[$cardId] !== (int) $card['version']) throw new RuntimeException('Card changed; refresh and try again.');
            if ((string) $card['location_type'] === 'deck') throw new RuntimeException('Draw a deck card before placing it in a pile.');
            self::assertCanControl($card, $member);
            if ($card['location_type'] === 'pile') {
                $sourcePile = self::pile($database, $session['id'], (string) $card['pile_id']);
                self::assertPileUnlocked($sourcePile, $member);
                $sourcePiles[(string) $sourcePile['id']] = true;
            }
            $cards[] = $card;
        }
        $next = self::nextOrder($database, $session['id'], $pileId);
        $update = $database->prepare("UPDATE session_cards SET location_type = 'pile', deck_id = NULL, pile_id = :pile, hand_participant_id = NULL, order_key = :order, x = NULL, y = NULL, face_state = :face, version = version + 1 WHERE id = :id");
        $moved = []; $sourceHands = [];
        foreach ($cards as $index => $card) {
            $face = ($card['location_type'] === 'hand' || ($payload['face_state'] ?? 'down') !== 'up') ? 'down' : 'up';
            $update->execute(['pile' => $pileId, 'order' => $next + (($index + 1) * 1000), 'face' => $face, 'id' => $card['id']]);
            if ($card['location_type'] === 'hand') $sourceHands[(string) $card['hand_participant_id']] = true;
            $moved[] = (string) $card['id'];
        }
        foreach ($sourceHands as $participantId => $_) $database->prepare('UPDATE session_hands SET version = version + 1 WHERE session_id = :session AND participant_id = :participant')->execute(['session' => $session['id'], 'participant' => $participantId]);
        foreach ($sourcePiles as $sourcePileId => $_) if ($sourcePileId !== $pileId) $database->prepare('UPDATE session_piles SET version = version + 1 WHERE id = :id')->execute(['id' => $sourcePileId]);
        $database->prepare('UPDATE session_piles SET version = version + 1 WHERE id = :id')->execute(['id' => $pileId]);
        return ['pile_id' => $pileId, 'card_ids' => $moved];
    }

    public static function shuffle(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $pile = self::pile($database, $session['id'], (string) ($payload['pile_id'] ?? ''));
        self::assertPileUnlocked($pile, $member);
        if (isset($payload['expected_pile_version']) && (int) $payload['expected_pile_version'] !== (int) $pile['version']) throw new RuntimeException('Pile changed; refresh and try again.');
        $cards = $database->prepare("SELECT * FROM session_cards WHERE session_id = :session AND location_type = 'pile' AND pile_id = :pile ORDER BY order_key FOR UPDATE");
        $cards->execute(['session' => $session['id'], 'pile' => $pile['id']]); $rows = $cards->fetchAll();
        foreach ($rows as $card) self::assertCanControl($card, $member);
        for ($i = count($rows) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]]; }
        self::assignOrder($database, $rows);
        $database->prepare('UPDATE session_piles SET version = version + 1 WHERE id = :id')->execute(['id' => $pile['id']]);
        return ['pile_id' => (string) $pile['id'], 'card_count' => count($rows), 'randomized' => true];
    }

    public static function draw(PDO $database, array $session, array $member, array $payload, string $direction): array
    {
        self::assertPlayer($session, $member);
        $pile = self::pile($database, $session['id'], (string) ($payload['pile_id'] ?? ''));
        self::assertPileUnlocked($pile, $member);
        if (isset($payload['expected_pile_version']) && (int) $payload['expected_pile_version'] !== (int) $pile['version']) throw new RuntimeException('Pile changed; refresh and try again.');
        $count = (int) ($payload['count'] ?? 1);
        if ($count < 1 || $count > 100) throw new RuntimeException('Draw count is invalid.');
        $order = $direction === 'bottom' ? 'DESC' : 'ASC';
        $cards = $database->prepare("SELECT * FROM session_cards WHERE session_id = :session AND location_type = 'pile' AND pile_id = :pile ORDER BY order_key {$order} LIMIT {$count} FOR UPDATE");
        $cards->execute(['session' => $session['id'], 'pile' => $pile['id']]);
        $rows = $cards->fetchAll();
        if (count($rows) < $count) throw new RuntimeException('The pile does not contain enough cards.');
        foreach ($rows as $card) self::assertCanControl($card, $member);
        $next = $database->prepare("SELECT coalesce(max(order_key), 0) FROM session_cards WHERE session_id = :session AND location_type = 'hand' AND hand_participant_id = :participant");
        $next->execute(['session' => $session['id'], 'participant' => $member['id']]);
        $orderKey = (int) $next->fetchColumn();
        $update = $database->prepare("UPDATE session_cards SET location_type='hand', deck_id=NULL, pile_id=NULL, hand_participant_id=:participant, order_key=:order_key, x=NULL, y=NULL, face_state='private', owner_user_id=NULL, version=version+1 WHERE id=:id");
        foreach ($rows as $index => $row) $update->execute(['participant' => $member['id'], 'order_key' => $orderKey + (($index + 1) * 1000), 'id' => $row['id']]);
        $database->prepare('UPDATE session_piles SET version = version + 1 WHERE id = :id')->execute(['id' => $pile['id']]);
        $database->prepare('UPDATE session_hands SET version = version + 1 WHERE session_id = :session AND participant_id = :participant')->execute(['session' => $session['id'], 'participant' => $member['id']]);
        return ['pile_id' => (string) $pile['id'], 'card_count' => count($rows), 'direction' => $direction];
    }

    public static function split(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $source = self::pile($database, $session['id'], (string) ($payload['pile_id'] ?? ''));
        self::assertPileUnlocked($source, $member);
        if (isset($payload['expected_pile_version']) && (int) $payload['expected_pile_version'] !== (int) $source['version']) throw new RuntimeException('Pile changed; refresh and try again.');
        $count = (int) ($payload['count'] ?? 0);
        if ($count < 1) throw new RuntimeException('Split count is invalid.');
        $cards = $database->prepare("SELECT * FROM session_cards WHERE session_id = :session AND location_type = 'pile' AND pile_id = :pile ORDER BY order_key FOR UPDATE");
        $cards->execute(['session' => $session['id'], 'pile' => $source['id']]);
        $rows = $cards->fetchAll();
        if ($count >= count($rows)) throw new RuntimeException('Split must leave cards in the source pile.');
        foreach (array_slice($rows, 0, $count) as $card) self::assertCanControl($card, $member);
        $insert = $database->prepare('INSERT INTO session_piles(session_id, label, x, y, rotation, z_index) VALUES (:session, :label, :x, :y, :rotation, :z) RETURNING id');
        $insert->execute(['session' => $session['id'], 'label' => isset($payload['label']) ? trim((string) $payload['label']) : null, 'x' => (float) ($payload['x'] ?? $source['x']), 'y' => (float) ($payload['y'] ?? $source['y']), 'rotation' => (float) ($payload['rotation'] ?? $source['rotation']), 'z' => (int) ($payload['z_index'] ?? $source['z_index'])]);
        $targetId = (string) $insert->fetchColumn();
        $update = $database->prepare("UPDATE session_cards SET location_type='pile', deck_id=NULL, pile_id=:pile, hand_participant_id=NULL, order_key=:order, x=NULL, y=NULL, version=version+1 WHERE id=:id");
        foreach (array_slice($rows, 0, $count) as $index => $card) $update->execute(['pile' => $targetId, 'order' => ($index + 1) * 1000, 'id' => $card['id']]);
        $database->prepare('UPDATE session_piles SET version = version + 1 WHERE id = :id')->execute(['id' => $source['id']]);
        return ['source_pile_id' => (string) $source['id'], 'pile_id' => $targetId, 'card_count' => $count];
    }

    public static function merge(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $sourceId = (string) ($payload['source_pile_id'] ?? ''); $targetId = (string) ($payload['target_pile_id'] ?? '');
        if ($sourceId === '' || $targetId === '' || $sourceId === $targetId) throw new RuntimeException('Source and target piles are invalid.');
        $statement = $database->prepare('SELECT * FROM session_piles WHERE session_id = :session AND id IN (:source, :target) ORDER BY id FOR UPDATE');
        $statement->execute(['session' => $session['id'], 'source' => $sourceId, 'target' => $targetId]);
        $piles = []; foreach ($statement->fetchAll() as $row) $piles[(string) $row['id']] = $row;
        if (!isset($piles[$sourceId], $piles[$targetId])) throw new RuntimeException('Pile not found.');
        self::assertPileUnlocked($piles[$sourceId], $member); self::assertPileUnlocked($piles[$targetId], $member);
        if (isset($payload['expected_source_version']) && (int) $payload['expected_source_version'] !== (int) $piles[$sourceId]['version']) throw new RuntimeException('Source pile changed; refresh and try again.');
        if (isset($payload['expected_target_version']) && (int) $payload['expected_target_version'] !== (int) $piles[$targetId]['version']) throw new RuntimeException('Target pile changed; refresh and try again.');
        $cards = $database->prepare("SELECT * FROM session_cards WHERE session_id = :session AND location_type = 'pile' AND pile_id = :pile ORDER BY order_key FOR UPDATE");
        $cards->execute(['session' => $session['id'], 'pile' => $sourceId]); $moving = $cards->fetchAll();
        foreach ($moving as $card) self::assertCanControl($card, $member);
        $existing = $database->prepare("SELECT * FROM session_cards WHERE session_id = :session AND location_type = 'pile' AND pile_id = :pile ORDER BY order_key FOR UPDATE");
        $existing->execute(['session' => $session['id'], 'pile' => $targetId]); $remaining = $existing->fetchAll();
        foreach ($remaining as $card) self::assertCanControl($card, $member);
        $position = ($payload['position'] ?? 'bottom') === 'top' ? 'top' : 'bottom';
        $ordered = $position === 'top' ? array_merge($moving, $remaining) : array_merge($remaining, $moving);
        $update = $database->prepare("UPDATE session_cards SET pile_id=:pile, order_key=:temporary, version=version+1 WHERE session_id=:session AND id=:id");
        foreach ($ordered as $index => $card) $update->execute(['pile' => $targetId, 'temporary' => -1000000 + $index, 'session' => $session['id'], 'id' => $card['id']]);
        self::assignOrder($database, $ordered);
        $database->prepare('UPDATE session_piles SET version = version + 1 WHERE id IN (:source, :target)')->execute(['source' => $sourceId, 'target' => $targetId]);
        $database->prepare('DELETE FROM session_piles WHERE session_id = :session AND id = :source')->execute(['session' => $session['id'], 'source' => $sourceId]);
        return ['source_pile_id' => $sourceId, 'target_pile_id' => $targetId, 'position' => $position, 'card_count' => count($moving)];
    }

    public static function collectSpread(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $pile = self::pile($database, $session['id'], (string) ($payload['pile_id'] ?? '')); self::assertPileUnlocked($pile, $member);
        if (isset($payload['expected_pile_version']) && (int) $payload['expected_pile_version'] !== (int) $pile['version']) throw new RuntimeException('Pile changed; refresh and try again.');
        $ids = $payload['card_ids'] ?? []; if (!is_array($ids) || count($ids) < 1 || count($ids) > 100 || count(array_unique(array_map('strval', $ids))) !== count($ids)) throw new RuntimeException('Card selection is invalid.');
        $cards = []; foreach ($ids as $id) { $card = self::card($database, $session['id'], (string) $id); if ($card['location_type'] !== 'table') throw new RuntimeException('Only table cards can be collected.'); self::assertCanControl($card, $member); $cards[] = $card; }
        $next = self::nextOrder($database, $session['id'], $pile['id']);
        $update = $database->prepare("UPDATE session_cards SET location_type='pile', deck_id=NULL, pile_id=:pile, hand_participant_id=NULL, order_key=:order, x=NULL, y=NULL, version=version+1 WHERE session_id=:session AND id=:id");
        foreach ($cards as $index => $card) $update->execute(['pile' => $pile['id'], 'order' => $next + (($index + 1) * 1000), 'session' => $session['id'], 'id' => $card['id']]);
        $database->prepare('UPDATE session_piles SET version = version + 1 WHERE id = :id')->execute(['id' => $pile['id']]);
        return ['pile_id' => (string) $pile['id'], 'card_count' => count($cards)];
    }

    public static function updateGeometry(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member); $pile = self::pile($database, $session['id'], (string) ($payload['pile_id'] ?? '')); self::assertPileUnlocked($pile, $member);
        if (isset($payload['expected_pile_version']) && (int) $payload['expected_pile_version'] !== (int) $pile['version']) throw new RuntimeException('Pile changed; refresh and try again.');
        $values = ['x' => (float) ($payload['x'] ?? $pile['x']), 'y' => (float) ($payload['y'] ?? $pile['y']), 'rotation' => (float) ($payload['rotation'] ?? $pile['rotation']), 'z' => (int) ($payload['z_index'] ?? $pile['z_index'])];
        if (!is_finite($values['x']) || !is_finite($values['y']) || !is_finite($values['rotation']) || $values['z'] < -1000000 || $values['z'] > 1000000) throw new RuntimeException('Pile geometry is invalid.');
        $database->prepare('UPDATE session_piles SET x=:x, y=:y, rotation=:rotation, z_index=:z, version=version+1 WHERE id=:id')->execute($values + ['id' => $pile['id']]);
        return ['pile_id' => (string) $pile['id'], 'x' => $values['x'], 'y' => $values['y'], 'rotation' => $values['rotation'], 'z_index' => $values['z']];
    }

    public static function label(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member); $pile = self::pile($database, $session['id'], (string) ($payload['pile_id'] ?? '')); self::assertPileUnlocked($pile, $member);
        if (isset($payload['expected_pile_version']) && (int) $payload['expected_pile_version'] !== (int) $pile['version']) throw new RuntimeException('Pile changed; refresh and try again.');
        $label = trim((string) ($payload['label'] ?? '')); if ($label !== '' && strlen($label) > 160) throw new RuntimeException('Pile label is invalid.');
        $database->prepare('UPDATE session_piles SET label=:label, version=version+1 WHERE id=:id')->execute(['label' => $label !== '' ? $label : null, 'id' => $pile['id']]);
        return ['pile_id' => (string) $pile['id'], 'label' => $label !== '' ? $label : null];
    }

    public static function lock(PDO $database, array $session, array $member, array $payload, bool $locked): array
    {
        self::assertPlayer($session, $member); $pile = self::pile($database, $session['id'], (string) ($payload['pile_id'] ?? ''));
        if (!array_key_exists('expected_pile_version', $payload) || filter_var($payload['expected_pile_version'], FILTER_VALIDATE_INT) === false) throw new RuntimeException('Expected pile version is required.');
        if ((int) $payload['expected_pile_version'] !== (int) $pile['version']) throw new RuntimeException('Pile changed; refresh and try again.');
        if ($locked && $pile['locked_by'] !== null && (string) $pile['locked_by'] !== (string) $member['user_id']) throw new RuntimeException('Pile is locked by another participant.');
        $database->prepare('UPDATE session_piles SET locked_by=:owner, version=version+1 WHERE id=:id')->execute(['owner' => $locked ? $member['user_id'] : null, 'id' => $pile['id']]);
        return ['pile_id' => (string) $pile['id'], 'locked' => $locked];
    }

    public static function reverse(PDO $database, array $session, array $member, array $payload, bool $flipFaces): array
    {
        self::assertPlayer($session, $member);
        $pile = self::pile($database, $session['id'], (string) ($payload['pile_id'] ?? ''));
        self::assertPileUnlocked($pile, $member);
        if (!array_key_exists('expected_pile_version', $payload) || filter_var($payload['expected_pile_version'], FILTER_VALIDATE_INT) === false) throw new RuntimeException('Expected pile version is required.');
        if ((int) $payload['expected_pile_version'] !== (int) $pile['version']) throw new RuntimeException('Pile changed; refresh and try again.');
        $cards = $database->prepare("SELECT * FROM session_cards WHERE session_id = :session AND location_type = 'pile' AND pile_id = :pile ORDER BY order_key FOR UPDATE");
        $cards->execute(['session' => $session['id'], 'pile' => $pile['id']]); $rows = $cards->fetchAll();
        foreach ($rows as $card) self::assertCanControl($card, $member);
        $rows = array_reverse($rows);
        $update = $database->prepare('UPDATE session_cards SET order_key = :order, face_state = :face, version = version + 1 WHERE id = :id');
        foreach ($rows as $index => $row) {
            $face = $flipFaces ? (($row['face_state'] ?? 'down') === 'up' ? 'down' : 'up') : (string) $row['face_state'];
            $update->execute(['order' => ($index + 1) * 1000, 'face' => $face, 'id' => $row['id']]);
        }
        $database->prepare('UPDATE session_piles SET version = version + 1 WHERE id = :id')->execute(['id' => $pile['id']]);
        return ['pile_id' => (string) $pile['id'], 'card_count' => count($rows), 'reversed' => true, 'faces_flipped' => $flipFaces];
    }

    public static function spread(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $pile = self::pile($database, $session['id'], (string) ($payload['pile_id'] ?? ''));
        self::assertPileUnlocked($pile, $member);
        if (!array_key_exists('expected_pile_version', $payload) || filter_var($payload['expected_pile_version'], FILTER_VALIDATE_INT) === false) throw new RuntimeException('Expected pile version is required.');
        if ((int) $payload['expected_pile_version'] !== (int) $pile['version']) throw new RuntimeException('Pile changed; refresh and try again.');
        $spacing = (float) ($payload['spacing'] ?? 28);
        if (!is_finite($spacing) || $spacing < 1 || $spacing > 500) throw new RuntimeException('Spread spacing is invalid.');
        $axis = ($payload['axis'] ?? 'x') === 'y' ? 'y' : 'x';
        $cards = $database->prepare("SELECT * FROM session_cards WHERE session_id = :session AND location_type = 'pile' AND pile_id = :pile ORDER BY order_key FOR UPDATE");
        $cards->execute(['session' => $session['id'], 'pile' => $pile['id']]);
        $rows = $cards->fetchAll();
        foreach ($rows as $index => $card) {
            self::assertCanControl($card, $member);
            $x = (float) $pile['x'] + ($axis === 'x' ? $index * $spacing : 0);
            $y = (float) $pile['y'] + ($axis === 'y' ? $index * $spacing : 0);
            $database->prepare("UPDATE session_cards SET location_type='table', deck_id=NULL, pile_id=NULL, hand_participant_id=NULL, order_key=NULL, x=:x, y=:y, z_index=:z, owner_user_id=NULL, version=version+1 WHERE id=:id")
                ->execute(['x' => $x, 'y' => $y, 'z' => (int) $pile['z_index'] + $index, 'id' => $card['id']]);
        }
        $database->prepare('UPDATE session_piles SET version = version + 1 WHERE id = :id')->execute(['id' => $pile['id']]);
        return ['pile_id' => (string) $pile['id'], 'card_count' => count($rows), 'axis' => $axis, 'spacing' => $spacing];
    }

    public static function mergeIntoDeck(PDO $database, array $session, array $member, array $payload, string $position): array
    {
        self::assertPlayer($session, $member);
        $pile = self::pile($database, $session['id'], (string) ($payload['pile_id'] ?? ''));
        self::assertPileUnlocked($pile, $member);
        if (!array_key_exists('expected_pile_version', $payload) || filter_var($payload['expected_pile_version'], FILTER_VALIDATE_INT) === false) throw new RuntimeException('Expected pile version is required.');
        if ((int) $payload['expected_pile_version'] !== (int) $pile['version']) throw new RuntimeException('Pile changed; refresh and try again.');
        $deckId = (string) ($payload['deck_id'] ?? '');
        $deck = $database->prepare('SELECT id, version FROM session_decks WHERE session_id = :session AND id = :id FOR UPDATE');
        $deck->execute(['session' => $session['id'], 'id' => $deckId]); $deckRow = $deck->fetch(); if (!is_array($deckRow)) throw new RuntimeException('Deck not found.');
        if (!array_key_exists('expected_deck_version', $payload) || filter_var($payload['expected_deck_version'], FILTER_VALIDATE_INT) === false) throw new RuntimeException('Expected deck version is required.');
        if ((int) $payload['expected_deck_version'] !== (int) $deckRow['version']) throw new RuntimeException('Deck changed; refresh and try again.');
        if (!in_array($position, ['top', 'bottom', 'shuffle'], true)) throw new RuntimeException('Deck position is invalid.');
        $cards = $database->prepare("SELECT * FROM session_cards WHERE session_id = :session AND location_type = 'pile' AND pile_id = :pile ORDER BY order_key FOR UPDATE");
        $cards->execute(['session' => $session['id'], 'pile' => $pile['id']]); $moving = $cards->fetchAll();
        if ($moving === []) throw new RuntimeException('The pile is empty.');
        foreach ($moving as $card) self::assertCanControl($card, $member);
        $all = $database->prepare("SELECT * FROM session_cards WHERE session_id = :session AND location_type = 'deck' AND deck_id = :deck ORDER BY order_key FOR UPDATE");
        $all->execute(['session' => $session['id'], 'deck' => $deckId]); $remaining = $all->fetchAll();
        foreach ($remaining as $card) self::assertCanControl($card, $member);
        $ordered = match ($position) { 'top' => array_merge($moving, $remaining), 'bottom' => array_merge($remaining, $moving), default => array_merge($moving, $remaining) };
        if ($position === 'shuffle') {
            for ($index = count($ordered) - 1; $index > 0; $index--) { $swap = random_int(0, $index); [$ordered[$index], $ordered[$swap]] = [$ordered[$swap], $ordered[$index]]; }
        }
        $update = $database->prepare("UPDATE session_cards SET location_type = 'deck', deck_id = :deck, pile_id = NULL, hand_participant_id = NULL, order_key = :temp, x = NULL, y = NULL, face_state = 'down', version = version + 1 WHERE id = :id");
        foreach ($ordered as $index => $card) $update->execute(['deck' => $deckId, 'temp' => -1000000 + $index, 'id' => $card['id']]);
        self::assignOrder($database, array_map(static fn (array $card): array => ['id' => $card['id']], $ordered));
        $database->prepare('UPDATE session_decks SET version = version + 1 WHERE id = :id')->execute(['id' => $deckId]);
        $database->prepare('UPDATE session_piles SET version = version + 1 WHERE id = :id')->execute(['id' => $pile['id']]);
        return ['position' => $position, 'card_count' => count($moving), 'randomized' => $position === 'shuffle'];
    }

    private static function assertPlayer(array $session, array $member): void { if (!in_array($member['role'], ['host', 'player'], true)) throw new RuntimeException('Player permission required.'); if ($session['status'] === 'ended') throw new RuntimeException('Session has ended.'); }
    private static function assertPileUnlocked(array $pile, array $member): void { if ($pile['locked_by'] !== null && (string) $pile['locked_by'] !== (string) $member['user_id']) throw new RuntimeException('Pile is locked by another participant.'); }
    private static function assertCanControl(array $card, array $member): void { if ($card['location_type'] === 'hand' && (string) $card['hand_participant_id'] !== (string) $member['id']) throw new RuntimeException('That hand is private.'); if ($card['location_type'] === 'pile' && $card['face_state'] === 'private') throw new RuntimeException('That pile card is private.'); if ($card['location_type'] === 'table' && $card['face_state'] === 'private' && (string) $card['owner_user_id'] !== (string) $member['user_id']) throw new RuntimeException('That table card is private.'); if ($card['locked_by'] !== null && (string) $card['locked_by'] !== (string) $member['user_id']) throw new RuntimeException('That card is locked.'); if ($card['location_type'] === 'removed') throw new RuntimeException('That card is removed from play.'); }
    private static function pile(PDO $database, string $sessionId, string $pileId): array { $s = $database->prepare('SELECT * FROM session_piles WHERE session_id = :session AND id = :id FOR UPDATE'); $s->execute(['session' => $sessionId, 'id' => $pileId]); $row = $s->fetch(); if (!is_array($row)) throw new RuntimeException('Pile not found.'); return $row; }
    private static function card(PDO $database, string $sessionId, string $cardId): array { $s = $database->prepare('SELECT * FROM session_cards WHERE session_id = :session AND id = :id FOR UPDATE'); $s->execute(['session' => $sessionId, 'id' => $cardId]); $row = $s->fetch(); if (!is_array($row)) throw new RuntimeException('Card not found.'); return $row; }
    private static function nextOrder(PDO $database, string $sessionId, string $pileId): int { $s = $database->prepare("SELECT coalesce(max(order_key), 0) FROM session_cards WHERE session_id = :session AND location_type = 'pile' AND pile_id = :pile"); $s->execute(['session' => $sessionId, 'pile' => $pileId]); return (int) $s->fetchColumn(); }
    private static function assignOrder(PDO $database, array $cards): void { $u = $database->prepare('UPDATE session_cards SET order_key = :order WHERE id = :id'); foreach ($cards as $i => $card) $u->execute(['order' => -2000000000 + $i, 'id' => $card['id']]); foreach ($cards as $i => $card) $u->execute(['order' => ($i + 1) * 1000, 'id' => $card['id']]); }
}
