<?php

declare(strict_types=1);

namespace Decks;

use PDO;
use RuntimeException;

final class LockService
{
    public static function change(PDO $database, array $session, array $member, array $payload, string $scope, bool $locked): array
    {
        $owner = (string) $member['user_id'];
        if ($scope === 'table') {
            $current = $session['locked_by'] ?? null;
            self::assertCanChange($current, $owner, $locked, 'table');
            $database->prepare('UPDATE sessions SET locked_by = :owner WHERE id = :id')
                ->execute(['owner' => $locked ? $owner : null, 'id' => $session['id']]);
            return ['scope' => 'table', 'locked' => $locked];
        }

        if ($scope === 'deck') {
            $id = (string) ($payload['deck_id'] ?? '');
            $statement = $database->prepare('SELECT id, locked_by, version FROM session_decks WHERE session_id = :session AND id = :id FOR UPDATE');
            $statement->execute(['session' => $session['id'], 'id' => $id]);
            $resource = $statement->fetch();
            if (!is_array($resource)) throw new RuntimeException('Deck not found.');
            $expected = filter_var($payload['expected_deck_version'] ?? null, FILTER_VALIDATE_INT);
            if ($expected === false || $expected !== (int) $resource['version']) throw new RuntimeException('Expected deck version is missing or stale; refresh and try again.');
            self::assertCanChange($resource['locked_by'], $owner, $locked, 'deck');
            $database->prepare('UPDATE session_decks SET locked_by = :owner, version = version + 1 WHERE session_id = :session AND id = :id')
                ->execute(['owner' => $locked ? $owner : null, 'session' => $session['id'], 'id' => $id]);
            return ['scope' => 'deck', 'deck_id' => $id, 'locked' => $locked];
        }

        if ($scope === 'zone') {
            $id = (string) ($payload['zone_id'] ?? '');
            $statement = $database->prepare('SELECT id, locked_by FROM session_zones WHERE session_id = :session AND id = :id FOR UPDATE');
            $statement->execute(['session' => $session['id'], 'id' => $id]);
            $resource = $statement->fetch();
            if (!is_array($resource)) throw new RuntimeException('Zone not found.');
            self::assertCanChange($resource['locked_by'], $owner, $locked, 'zone');
            $database->prepare('UPDATE session_zones SET locked_by = :owner, locked = :locked WHERE session_id = :session AND id = :id')
                ->execute(['owner' => $locked ? $owner : null, 'locked' => $locked, 'session' => $session['id'], 'id' => $id]);
            return ['scope' => 'zone', 'zone_id' => $id, 'locked' => $locked];
        }

        throw new RuntimeException('Lock scope is invalid.');
    }

    public static function assertActionAllowed(PDO $database, array $session, array $member, string $type, array $payload): void
    {
        $userId = (string) $member['user_id'];
        $sessionOwner = $session['locked_by'] ?? null;
        if ($sessionOwner !== null && (string) $sessionOwner !== $userId && !in_array($type, ['leave_session', 'unlock_table', 'reset_session'], true)) {
            throw new RuntimeException('The table is locked by another participant.');
        }
        if ($type === 'reset_session') return;

        $zoneRows = self::zones($database, (string) $session['id']);
        if ($type === 'configure_table') {
            foreach ($zoneRows as $zone) self::assertOwnerOrOpen($zone['locked_by'], $userId, 'zone');
        }
        if (in_array($type, ['update_zone', 'delete_zone'], true)) {
            $zoneId = (string) ($payload['zone_id'] ?? '');
            foreach ($zoneRows as $zone) if ((string) $zone['id'] === $zoneId) self::assertOwnerOrOpen($zone['locked_by'], $userId, 'zone');
        }

        $deckIds = self::valuesForKeys($payload, ['deck_id', 'source_deck_id', 'target_deck_id']);
        if ($type === 'collect_all') {
            $statement = $database->prepare('SELECT id FROM session_decks WHERE session_id = :session ORDER BY id');
            $statement->execute(['session' => $session['id']]);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) $deckIds[] = (string) $id;
        }

        $points = [];
        $cardIds = self::cardIds($payload);
        if ($type === 'collect_all') {
            $statement = $database->prepare("SELECT x, y FROM session_cards WHERE session_id = :session AND location_type = 'table'");
            $statement->execute(['session' => $session['id']]);
            foreach ($statement->fetchAll() as $card) $points[] = [(float) $card['x'], (float) $card['y']];
        }
        $cards = [];
        foreach ($cardIds as $id) {
            $statement = $database->prepare('SELECT id, source_deck_id, location_type, deck_id, pile_id, x, y FROM session_cards WHERE session_id = :session AND id = :id');
            $statement->execute(['session' => $session['id'], 'id' => $id]);
            $card = $statement->fetch();
            if (!is_array($card)) continue;
            $cards[] = $card;
            if ($card['location_type'] === 'deck' && $card['deck_id'] !== null) $deckIds[] = (string) $card['deck_id'];
            if (in_array($type, ['restore_card', 'return_to_source_decks'], true)) $deckIds[] = (string) $card['source_deck_id'];
        }
        foreach (array_values(array_unique($deckIds)) as $id) {
            if (in_array($type, ['lock_deck', 'unlock_deck'], true)) continue;
            $statement = $database->prepare('SELECT locked_by FROM session_decks WHERE session_id = :session AND id = :id');
            $statement->execute(['session' => $session['id'], 'id' => $id]);
            $owner = $statement->fetchColumn();
            if ($owner !== false) self::assertOwnerOrOpen($owner, $userId, 'deck');
        }

        $pileIds = self::valuesForKeys($payload, ['pile_id', 'source_pile_id', 'target_pile_id']);
        if ($type === 'collect_all') {
            $statement = $database->prepare('SELECT id FROM session_piles WHERE session_id = :session ORDER BY id');
            $statement->execute(['session' => $session['id']]);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) $pileIds[] = (string) $id;
        }
        foreach ($cards as $card) if ($card['location_type'] === 'pile' && $card['pile_id'] !== null) $pileIds[] = (string) $card['pile_id'];
        $points = array_merge($points, self::points($payload));
        if (in_array($type, ['create_zone', 'update_zone'], true)) $points = [];
        foreach (array_values(array_unique($pileIds)) as $id) {
            $statement = $database->prepare('SELECT locked_by, x, y FROM session_piles WHERE session_id = :session AND id = :id');
            $statement->execute(['session' => $session['id'], 'id' => $id]);
            $pile = $statement->fetch();
            if (!is_array($pile)) continue;
            if (!in_array($type, ['lock_pile', 'unlock_pile', 'lock_card', 'unlock_card', 'collect_all'], true)) self::assertOwnerOrOpen($pile['locked_by'], $userId, 'pile');
            $points[] = [(float) $pile['x'], (float) $pile['y']];
        }
        foreach ($cards as $card) {
            if ($card['location_type'] === 'table' && $card['x'] !== null && $card['y'] !== null) $points[] = [(float) $card['x'], (float) $card['y']];
        }
        foreach ($points as [$x, $y]) self::assertPointOutsideForeignLocks($zoneRows, $userId, (float) $x, (float) $y);
    }

    private static function assertCanChange(mixed $current, string $userId, bool $locked, string $scope): void
    {
        if ($locked && $current !== null && (string) $current !== $userId) throw new RuntimeException(ucfirst($scope) . ' is already locked by another participant.');
        if (!$locked && $current === null) throw new RuntimeException(ucfirst($scope) . ' is not locked.');
    }

    private static function assertOwnerOrOpen(mixed $owner, string $userId, string $scope): void
    {
        if ($owner !== null && (string) $owner !== $userId) throw new RuntimeException(ucfirst($scope) . ' is locked by another participant.');
    }

    private static function zones(PDO $database, string $sessionId): array
    {
        $statement = $database->prepare('SELECT id, geometry, locked_by FROM session_zones WHERE session_id = :session ORDER BY id');
        $statement->execute(['session' => $sessionId]);
        return $statement->fetchAll();
    }

    private static function assertPointOutsideForeignLocks(array $zones, string $userId, float $x, float $y): void
    {
        foreach ($zones as $zone) {
            $geometry = json_decode((string) $zone['geometry'], true, 512, JSON_THROW_ON_ERROR);
            if ($zone['locked_by'] !== null && (string) $zone['locked_by'] !== $userId &&
                $x >= (float) $geometry['x'] && $x < (float) $geometry['x'] + (float) $geometry['width'] &&
                $y >= (float) $geometry['y'] && $y < (float) $geometry['y'] + (float) $geometry['height']) {
                throw new RuntimeException('An affected table zone is locked by another participant.');
            }
        }
    }

    private static function cardIds(array $payload): array
    {
        $ids = self::valuesForKeys($payload, ['card_id', 'card_ids']);
        if (isset($payload['expected_card_versions']) && is_array($payload['expected_card_versions'])) {
            foreach (array_keys($payload['expected_card_versions']) as $id) if (is_string($id)) $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    private static function valuesForKeys(array $payload, array $keys): array
    {
        $values = [];
        $visit = static function (array $node) use (&$visit, &$values, $keys): void {
            foreach ($node as $key => $value) {
                if (in_array((string) $key, $keys, true)) {
                    if (is_string($value) && $value !== '') $values[] = $value;
                    if (is_array($value)) foreach ($value as $item) if (is_string($item) && $item !== '') $values[] = $item;
                }
                if (is_array($value)) $visit($value);
            }
        };
        $visit($payload);
        return array_values(array_unique($values));
    }

    private static function points(array $payload): array
    {
        $points = [];
        $visit = static function (array $node) use (&$visit, &$points): void {
            if (isset($node['x'], $node['y']) && is_numeric($node['x']) && is_numeric($node['y'])) {
                $x = (float) $node['x']; $y = (float) $node['y'];
                if (is_finite($x) && is_finite($y)) $points[] = [$x, $y];
            }
            foreach ($node as $value) if (is_array($value)) $visit($value);
        };
        $visit($payload);
        return $points;
    }
}
