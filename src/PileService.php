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
        if (isset($payload['expected_pile_version']) && (int) $payload['expected_pile_version'] !== (int) $pile['version']) throw new RuntimeException('Pile changed; refresh and try again.');
        $ids = $payload['card_ids'] ?? [];
        if (!is_array($ids) || count($ids) < 1 || count($ids) > 100) throw new RuntimeException('Card selection is invalid.');
        $cards = [];
        foreach ($ids as $id) {
            $card = self::card($database, $session['id'], (string) $id);
            if ((string) $card['location_type'] === 'deck') throw new RuntimeException('Draw a deck card before placing it in a pile.');
            self::assertCanControl($card, $member);
            $cards[] = $card;
        }
        $next = self::nextOrder($database, $session['id'], $pileId);
        $update = $database->prepare("UPDATE session_cards SET location_type = 'pile', deck_id = NULL, pile_id = :pile, hand_participant_id = NULL, order_key = :order, x = NULL, y = NULL, face_state = :face, version = version + 1 WHERE id = :id");
        $moved = [];
        foreach ($cards as $index => $card) {
            $face = ($card['location_type'] === 'hand' || ($payload['face_state'] ?? 'down') !== 'up') ? 'down' : 'up';
            $update->execute(['pile' => $pileId, 'order' => $next + (($index + 1) * 1000), 'face' => $face, 'id' => $card['id']]);
            $moved[] = (string) $card['id'];
        }
        $database->prepare('UPDATE session_piles SET version = version + 1 WHERE id = :id')->execute(['id' => $pileId]);
        return ['pile_id' => $pileId, 'card_ids' => $moved];
    }

    public static function shuffle(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $pile = self::pile($database, $session['id'], (string) ($payload['pile_id'] ?? ''));
        if (isset($payload['expected_pile_version']) && (int) $payload['expected_pile_version'] !== (int) $pile['version']) throw new RuntimeException('Pile changed; refresh and try again.');
        $cards = $database->prepare("SELECT id FROM session_cards WHERE session_id = :session AND location_type = 'pile' AND pile_id = :pile ORDER BY order_key FOR UPDATE");
        $cards->execute(['session' => $session['id'], 'pile' => $pile['id']]); $rows = $cards->fetchAll();
        for ($i = count($rows) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]]; }
        self::assignOrder($database, $rows);
        $database->prepare('UPDATE session_piles SET version = version + 1 WHERE id = :id')->execute(['id' => $pile['id']]);
        return ['pile_id' => (string) $pile['id'], 'card_count' => count($rows), 'randomized' => true];
    }

    public static function reverse(PDO $database, array $session, array $member, array $payload, bool $flipFaces): array
    {
        self::assertPlayer($session, $member);
        $pile = self::pile($database, $session['id'], (string) ($payload['pile_id'] ?? ''));
        if (isset($payload['expected_pile_version']) && (int) $payload['expected_pile_version'] !== (int) $pile['version']) throw new RuntimeException('Pile changed; refresh and try again.');
        $cards = $database->prepare("SELECT id, face_state FROM session_cards WHERE session_id = :session AND location_type = 'pile' AND pile_id = :pile ORDER BY order_key FOR UPDATE");
        $cards->execute(['session' => $session['id'], 'pile' => $pile['id']]); $rows = array_reverse($cards->fetchAll());
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
        if (isset($payload['expected_pile_version']) && (int) $payload['expected_pile_version'] !== (int) $pile['version']) throw new RuntimeException('Pile changed; refresh and try again.');
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
        $deckId = (string) ($payload['deck_id'] ?? '');
        $deck = $database->prepare('SELECT id FROM session_decks WHERE session_id = :session AND id = :id FOR UPDATE');
        $deck->execute(['session' => $session['id'], 'id' => $deckId]); if (!$deck->fetch()) throw new RuntimeException('Deck not found.');
        $cards = $database->prepare("SELECT * FROM session_cards WHERE session_id = :session AND location_type = 'pile' AND pile_id = :pile ORDER BY order_key FOR UPDATE");
        $cards->execute(['session' => $session['id'], 'pile' => $pile['id']]); $moving = $cards->fetchAll();
        foreach ($moving as $card) self::assertCanControl($card, $member);
        $all = $database->prepare("SELECT id FROM session_cards WHERE session_id = :session AND location_type = 'deck' AND deck_id = :deck ORDER BY order_key");
        $all->execute(['session' => $session['id'], 'deck' => $deckId]); $remaining = $all->fetchAll();
        $ordered = $position === 'top' ? array_merge($moving, $remaining) : array_merge($remaining, $moving);
        $update = $database->prepare("UPDATE session_cards SET location_type = 'deck', deck_id = :deck, pile_id = NULL, hand_participant_id = NULL, order_key = :temp, x = NULL, y = NULL, face_state = 'down', version = version + 1 WHERE id = :id");
        foreach ($ordered as $index => $card) $update->execute(['deck' => $deckId, 'temp' => -1000000 + $index, 'id' => $card['id']]);
        self::assignOrder($database, array_map(static fn (array $card): array => ['id' => $card['id']], $ordered));
        $database->prepare('UPDATE session_decks SET version = version + 1 WHERE id = :id')->execute(['id' => $deckId]);
        $database->prepare('UPDATE session_piles SET version = version + 1 WHERE id = :id')->execute(['id' => $pile['id']]);
        return ['pile_id' => (string) $pile['id'], 'deck_id' => $deckId, 'position' => $position, 'card_count' => count($moving)];
    }

    private static function assertPlayer(array $session, array $member): void { if (!in_array($member['role'], ['host', 'player'], true)) throw new RuntimeException('Player permission required.'); if ($session['status'] === 'ended') throw new RuntimeException('Session has ended.'); }
    private static function assertCanControl(array $card, array $member): void { if ($card['location_type'] === 'hand' && (string) $card['hand_participant_id'] !== (string) $member['id']) throw new RuntimeException('That hand is private.'); if ($card['location_type'] === 'pile' && $card['face_state'] === 'private') throw new RuntimeException('That pile card is private.'); if ($card['locked_by'] !== null && (string) $card['locked_by'] !== (string) $member['user_id']) throw new RuntimeException('That card is locked.'); if ($card['location_type'] === 'removed') throw new RuntimeException('That card is removed from play.'); }
    private static function pile(PDO $database, string $sessionId, string $pileId): array { $s = $database->prepare('SELECT * FROM session_piles WHERE session_id = :session AND id = :id FOR UPDATE'); $s->execute(['session' => $sessionId, 'id' => $pileId]); $row = $s->fetch(); if (!is_array($row)) throw new RuntimeException('Pile not found.'); return $row; }
    private static function card(PDO $database, string $sessionId, string $cardId): array { $s = $database->prepare('SELECT * FROM session_cards WHERE session_id = :session AND id = :id FOR UPDATE'); $s->execute(['session' => $sessionId, 'id' => $cardId]); $row = $s->fetch(); if (!is_array($row)) throw new RuntimeException('Card not found.'); return $row; }
    private static function nextOrder(PDO $database, string $sessionId, string $pileId): int { $s = $database->prepare("SELECT coalesce(max(order_key), 0) FROM session_cards WHERE session_id = :session AND location_type = 'pile' AND pile_id = :pile"); $s->execute(['session' => $sessionId, 'pile' => $pileId]); return (int) $s->fetchColumn(); }
    private static function assignOrder(PDO $database, array $cards): void { $u = $database->prepare('UPDATE session_cards SET order_key = :order WHERE id = :id'); foreach ($cards as $i => $card) $u->execute(['order' => -2000000000 + $i, 'id' => $card['id']]); foreach ($cards as $i => $card) $u->execute(['order' => ($i + 1) * 1000, 'id' => $card['id']]); }
}
