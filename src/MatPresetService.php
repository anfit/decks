<?php

declare(strict_types=1);

namespace Decks;

use PDO;
use RuntimeException;

final class MatPresetService
{
    public static function createMat(PDO $database, array $user, string $name, ?string $assetId, ?int $width, ?int $height, array $metadata = []): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) throw new RuntimeException('Mat name is invalid.');
        if (($width === null) !== ($height === null) || ($width !== null && ($width < 1 || $height < 1 || $width > 10000 || $height > 10000))) throw new RuntimeException('Mat dimensions are invalid.');
        if ($assetId !== null) {
            $asset = $database->prepare('SELECT id FROM assets WHERE id = :id AND owner_user_id = :owner');
            $asset->execute(['id' => $assetId, 'owner' => $user['id']]);
            if (!$asset->fetch()) throw new RuntimeException('Mat asset is not available.');
        }
        $statement = $database->prepare('INSERT INTO mat_versions(owner_user_id, name, background_asset_id, width, height, metadata) VALUES (:owner, :name, :asset, :width, :height, CAST(:metadata AS jsonb)) RETURNING id, created_at');
        $statement->execute(['owner' => $user['id'], 'name' => $name, 'asset' => $assetId, 'width' => $width, 'height' => $height, 'metadata' => json_encode(self::cleanMetadata($metadata), JSON_THROW_ON_ERROR)]);
        $row = $statement->fetch();
        Security::audit($database, (string) $user['id'], 'mat.created', 'mat_version', (string) $row['id']);
        return ['id' => (string) $row['id'], 'name' => $name, 'background_asset_id' => $assetId, 'width' => $width, 'height' => $height, 'metadata' => self::cleanMetadata($metadata)];
    }

    public static function createPreset(PDO $database, array $user, string $name, string $templateVersionId, ?string $matVersionId, array $configuration = []): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) throw new RuntimeException('Preset name is invalid.');
        $template = $database->prepare('SELECT v.id FROM deck_template_versions v JOIN deck_templates t ON t.id = v.template_id WHERE v.id = :version AND t.owner_user_id = :owner');
        $template->execute(['version' => $templateVersionId, 'owner' => $user['id']]);
        if (!$template->fetch()) throw new RuntimeException('Preset template version is not available.');
        if ($matVersionId !== null) {
            $mat = $database->prepare('SELECT id FROM mat_versions WHERE id = :id AND owner_user_id = :owner');
            $mat->execute(['id' => $matVersionId, 'owner' => $user['id']]);
            if (!$mat->fetch()) throw new RuntimeException('Preset mat version is not available.');
        }
        $normalized = self::normalizeConfiguration($configuration);
        $statement = $database->prepare('INSERT INTO table_presets(owner_user_id, name, template_version_id, mat_version_id, configuration) VALUES (:owner, :name, :template, :mat, CAST(:configuration AS jsonb)) RETURNING id');
        $statement->execute(['owner' => $user['id'], 'name' => $name, 'template' => $templateVersionId, 'mat' => $matVersionId, 'configuration' => json_encode($normalized, JSON_THROW_ON_ERROR)]);
        $id = (string) $statement->fetchColumn();
        Security::audit($database, (string) $user['id'], 'preset.created', 'table_preset', $id);
        return ['id' => $id, 'name' => $name, 'template_version_id' => $templateVersionId, 'mat_version_id' => $matVersionId, 'configuration' => $normalized];
    }

    public static function listOwned(PDO $database, array $user): array
    {
        $mats = $database->prepare('SELECT id, name, background_asset_id, width, height, metadata, created_at FROM mat_versions WHERE owner_user_id = :owner ORDER BY created_at DESC, id');
        $mats->execute(['owner' => $user['id']]);
        $presets = $database->prepare('SELECT id, name, template_version_id, mat_version_id, configuration, created_at FROM table_presets WHERE owner_user_id = :owner ORDER BY created_at DESC, id');
        $presets->execute(['owner' => $user['id']]);
        return ['mats' => array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'background_asset_id' => $row['background_asset_id'] ? (string) $row['background_asset_id'] : null, 'width' => $row['width'] !== null ? (int) $row['width'] : null, 'height' => $row['height'] !== null ? (int) $row['height'] : null, 'metadata' => json_decode((string) $row['metadata'], true, 512, JSON_THROW_ON_ERROR), 'created_at' => (string) $row['created_at']], $mats->fetchAll()), 'presets' => array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'template_version_id' => (string) $row['template_version_id'], 'mat_version_id' => $row['mat_version_id'] ? (string) $row['mat_version_id'] : null, 'configuration' => json_decode((string) $row['configuration'], true, 512, JSON_THROW_ON_ERROR), 'created_at' => (string) $row['created_at']], $presets->fetchAll())];
    }

    public static function normalizedPreset(PDO $database, array $user, string $presetId): array
    {
        $statement = $database->prepare('SELECT p.id, p.name, p.template_version_id, p.mat_version_id, p.configuration, m.name AS mat_name, m.background_asset_id, m.width, m.height FROM table_presets p LEFT JOIN mat_versions m ON m.id = p.mat_version_id WHERE p.id = :id AND p.owner_user_id = :owner');
        $statement->execute(['id' => $presetId, 'owner' => $user['id']]);
        $row = $statement->fetch();
        if (!is_array($row)) throw new RuntimeException('Preset is not available.');
        $configuration = json_decode((string) $row['configuration'], true, 512, JSON_THROW_ON_ERROR);
        return ['preset_id' => (string) $row['id'], 'name' => (string) $row['name'], 'template_version_id' => (string) $row['template_version_id'], 'mat' => $row['mat_version_id'] ? ['id' => (string) $row['mat_version_id'], 'label' => (string) $row['mat_name'], 'asset_id' => $row['background_asset_id'] ? (string) $row['background_asset_id'] : null, 'width' => $row['width'] !== null ? (int) $row['width'] : null, 'height' => $row['height'] !== null ? (int) $row['height'] : null] : [], 'configuration' => $configuration];
    }

    public static function normalizeConfiguration(array $configuration): array
    {
        $zones = $configuration['zones'] ?? [];
        if (!is_array($zones) || count($zones) > 100) throw new RuntimeException('Preset zones are invalid.');
        $normalizedZones = [];
        foreach (array_values($zones) as $zone) {
            if (!is_array($zone)) throw new RuntimeException('Preset zone is invalid.');
            $geometry = $zone['geometry'] ?? [];
            if (!is_array($geometry)) throw new RuntimeException('Preset zone geometry is invalid.');
            foreach (['x', 'y', 'width', 'height'] as $key) if (!isset($geometry[$key]) || !is_finite((float) $geometry[$key])) throw new RuntimeException('Preset zone geometry is invalid.');
            if ((float) $geometry['width'] <= 0 || (float) $geometry['height'] <= 0) throw new RuntimeException('Preset zone geometry is invalid.');
            $effect = (string) (($zone['behavior']['effect'] ?? 'none'));
            if (!in_array($effect, ['none', 'face_up', 'face_down', 'stack', 'align', 'fan', 'owner_private'], true)) throw new RuntimeException('Preset zone effect is invalid.');
            $behavior = ['effect' => $effect];
            if ($effect === 'fan' && isset($zone['behavior']['degrees'])) {
                $degrees = (float) $zone['behavior']['degrees'];
                if (!is_finite($degrees) || $degrees < 1 || $degrees > 180) throw new RuntimeException('Preset zone fan degrees are invalid.');
                $behavior['degrees'] = $degrees;
            }
            $normalizedZones[] = ['name' => trim((string) ($zone['name'] ?? 'Zone')), 'geometry' => ['x' => (float) $geometry['x'], 'y' => (float) $geometry['y'], 'width' => (float) $geometry['width'], 'height' => (float) $geometry['height']], 'priority' => (int) ($zone['priority'] ?? 0), 'behavior' => $behavior];
        }
        return ['zones' => $normalizedZones, 'options' => is_array($configuration['options'] ?? null) ? self::cleanMetadata($configuration['options']) : []];
    }

    private static function cleanMetadata(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) if (is_string($key) && strlen($key) <= 80 && !in_array(strtolower($key), ['token', 'secret', 'password', 'body'], true) && (is_scalar($value) || is_array($value))) $clean[$key] = $value;
        return $clean;
    }
}
