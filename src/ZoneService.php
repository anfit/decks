<?php

declare(strict_types=1);

namespace Decks;

use PDO;
use RuntimeException;

final class ZoneService
{
    public static function create(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertConfigurable($session, $member);
        $zone = self::normalize($payload);
        $insert = $database->prepare('INSERT INTO session_zones(session_id, name, geometry, priority, behavior, owner_user_id) VALUES (:session, :name, CAST(:geometry AS jsonb), :priority, CAST(:behavior AS jsonb), :owner) RETURNING id');
        $insert->execute(['session' => $session['id'], 'name' => $zone['name'], 'geometry' => json_encode($zone['geometry'], JSON_THROW_ON_ERROR), 'priority' => $zone['priority'], 'behavior' => json_encode($zone['behavior'], JSON_THROW_ON_ERROR), 'owner' => $member['user_id']]);
        $zoneId = (string) $insert->fetchColumn();
        self::captureInitialState($database, (string) $session['id']);
        return ['zone_id' => $zoneId, 'name' => $zone['name']];
    }

    public static function update(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertConfigurable($session, $member);
        $zoneId = (string) ($payload['zone_id'] ?? '');
        if ($zoneId === '') throw new RuntimeException('Zone reference is invalid.');
        $zone = self::normalize($payload);
        $update = $database->prepare('UPDATE session_zones SET name = :name, geometry = CAST(:geometry AS jsonb), priority = :priority, behavior = CAST(:behavior AS jsonb) WHERE session_id = :session AND id = :id');
        $update->execute(['name' => $zone['name'], 'geometry' => json_encode($zone['geometry'], JSON_THROW_ON_ERROR), 'priority' => $zone['priority'], 'behavior' => json_encode($zone['behavior'], JSON_THROW_ON_ERROR), 'session' => $session['id'], 'id' => $zoneId]);
        if ($update->rowCount() !== 1) throw new RuntimeException('Zone not found.');
        self::captureInitialState($database, (string) $session['id']);
        return ['zone_id' => $zoneId, 'name' => $zone['name'], 'updated' => true];
    }

    public static function delete(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertConfigurable($session, $member);
        $id = (string) ($payload['zone_id'] ?? '');
        $delete = $database->prepare('DELETE FROM session_zones WHERE session_id = :session AND id = :id');
        $delete->execute(['session' => $session['id'], 'id' => $id]);
        if ($delete->rowCount() !== 1) throw new RuntimeException('Zone not found.');
        self::captureInitialState($database, (string) $session['id']);
        return ['zone_id' => $id, 'deleted' => true];
    }

    /** @return array{name: string, geometry: array{x: float, y: float, width: float, height: float}, priority: int, behavior: array<string, int|float|string>} */
    private static function normalize(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || strlen($name) > 160) throw new RuntimeException('Zone name is invalid.');
        $rawGeometry = $payload['geometry'] ?? null;
        if (!is_array($rawGeometry)) throw new RuntimeException('Zone geometry is invalid.');
        $geometry = [
            'x' => (float) ($rawGeometry['x'] ?? NAN), 'y' => (float) ($rawGeometry['y'] ?? NAN),
            'width' => (float) ($rawGeometry['width'] ?? NAN), 'height' => (float) ($rawGeometry['height'] ?? NAN),
        ];
        foreach (['x', 'y'] as $axis) if (!is_finite($geometry[$axis]) || abs($geometry[$axis]) > 1000000) throw new RuntimeException('Zone geometry is invalid.');
        foreach (['width', 'height'] as $dimension) if (!is_finite($geometry[$dimension]) || $geometry[$dimension] <= 0 || $geometry[$dimension] > 1000000) throw new RuntimeException('Zone geometry is invalid.');
        $priority = filter_var($payload['priority'] ?? 0, FILTER_VALIDATE_INT);
        if ($priority === false || $priority < -1000000 || $priority > 1000000) throw new RuntimeException('Zone priority is invalid.');
        $rawBehavior = $payload['behavior'] ?? [];
        if (!is_array($rawBehavior)) throw new RuntimeException('Zone behavior is invalid.');
        $effect = (string) ($rawBehavior['effect'] ?? 'none');
        if (!in_array($effect, ['none', 'face_up', 'face_down', 'stack', 'align', 'fan', 'owner_private'], true)) throw new RuntimeException('Zone effect is invalid.');
        $behavior = ['effect' => $effect];
        if ($effect === 'fan' && isset($rawBehavior['degrees'])) {
            $degrees = (float) $rawBehavior['degrees'];
            if (!is_finite($degrees) || $degrees < 1 || $degrees > 180) throw new RuntimeException('Zone fan degrees are invalid.');
            $behavior['degrees'] = $degrees;
        }
        return ['name' => $name, 'geometry' => $geometry, 'priority' => $priority, 'behavior' => $behavior];
    }

    private static function assertConfigurable(array $session, array $member): void
    {
        self::assertHost($member);
        if (($session['status'] ?? '') !== 'lobby') throw new RuntimeException('Zones can only be changed while the table is in the lobby.');
    }

    private static function assertHost(array $member): void
    {
        if (($member['role'] ?? null) !== 'host' || !SessionService::hasCapability($member, 'zone.manage')) throw new RuntimeException('Host zone-management permission required.');
    }

    private static function captureInitialState(PDO $database, string $sessionId): void
    {
        $settings = $database->prepare('SELECT access_settings FROM sessions WHERE id = :id');
        $settings->execute(['id' => $sessionId]);
        ActionService::captureInitialState($database, $sessionId, json_decode((string) $settings->fetchColumn(), true, 512, JSON_THROW_ON_ERROR));
    }
}
