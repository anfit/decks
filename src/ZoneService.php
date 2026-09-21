<?php

declare(strict_types=1);

namespace Decks;

use PDO;
use RuntimeException;

final class ZoneService
{
    public static function create(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertHost($member);
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || strlen($name) > 160) throw new RuntimeException('Zone name is invalid.');
        $geometry = $payload['geometry'] ?? null;
        if (!is_array($geometry) || !is_finite((float) ($geometry['x'] ?? NAN)) || !is_finite((float) ($geometry['y'] ?? NAN)) || !is_finite((float) ($geometry['width'] ?? NAN)) || !is_finite((float) ($geometry['height'] ?? NAN)) || (float) $geometry['width'] <= 0 || (float) $geometry['height'] <= 0) throw new RuntimeException('Zone geometry is invalid.');
        $behavior = $payload['behavior'] ?? [];
        if (!is_array($behavior)) throw new RuntimeException('Zone behavior is invalid.');
        $effect = (string) ($behavior['effect'] ?? 'none');
        if (!in_array($effect, ['none', 'face_up', 'face_down', 'stack', 'align'], true)) throw new RuntimeException('Zone effect is invalid.');
        $behavior = ['effect' => $effect];
        $insert = $database->prepare('INSERT INTO session_zones(session_id, name, geometry, priority, behavior, owner_user_id) VALUES (:session, :name, CAST(:geometry AS jsonb), :priority, CAST(:behavior AS jsonb), :owner) RETURNING id');
        $insert->execute(['session' => $session['id'], 'name' => $name, 'geometry' => json_encode($geometry, JSON_THROW_ON_ERROR), 'priority' => (int) ($payload['priority'] ?? 0), 'behavior' => json_encode($behavior, JSON_THROW_ON_ERROR), 'owner' => $member['user_id']]);
        return ['zone_id' => (string) $insert->fetchColumn(), 'name' => $name];
    }

    public static function delete(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertHost($member);
        $id = (string) ($payload['zone_id'] ?? '');
        $delete = $database->prepare('DELETE FROM session_zones WHERE session_id = :session AND id = :id');
        $delete->execute(['session' => $session['id'], 'id' => $id]);
        if ($delete->rowCount() !== 1) throw new RuntimeException('Zone not found.');
        return ['zone_id' => $id, 'deleted' => true];
    }

    private static function assertHost(array $member): void
    {
        if (($member['role'] ?? null) !== 'host') throw new RuntimeException('Host permission required.');
    }
}
