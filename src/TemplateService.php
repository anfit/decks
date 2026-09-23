<?php

declare(strict_types=1);

namespace Decks;

use PDO;
use RuntimeException;

final class TemplateService
{
    public static function create(PDO $database, array $user, string $name, array $definitions = [], ?string $defaultBackAssetId = null): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) throw new RuntimeException('Template name is invalid.');
        $assetIds = self::validateDefinitions($definitions, $defaultBackAssetId);
        $database->beginTransaction();
        try {
            self::assertAssetsOwned($database, (string) $user['id'], $assetIds);
            $template = $database->prepare('INSERT INTO deck_templates(owner_user_id, name) VALUES (:owner, :name) RETURNING id');
            $template->execute(['owner' => $user['id'], 'name' => $name]);
            $templateId = (string) $template->fetchColumn();
            $version = self::insertVersion($database, $templateId, 1, $definitions, $defaultBackAssetId);
            Security::audit($database, (string) $user['id'], 'template.created', 'deck_template', $templateId);
            $database->commit();
            return ['id' => $templateId, 'version_id' => $version['version_id'], 'version' => 1, 'definition_count' => $version['definition_count'], 'name' => $name];
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function createVersion(PDO $database, array $user, string $templateId, array $definitions, ?string $defaultBackAssetId = null): array
    {
        $assetIds = self::validateDefinitions($definitions, $defaultBackAssetId);
        $database->beginTransaction();
        try {
            $template = $database->prepare('SELECT * FROM deck_templates WHERE id = :id AND owner_user_id = :owner FOR UPDATE');
            $template->execute(['id' => $templateId, 'owner' => $user['id']]);
            if (!$template->fetch()) throw new RuntimeException('Template not found.');
            self::assertAssetsOwned($database, (string) $user['id'], $assetIds);
            $next = $database->prepare('SELECT coalesce(max(version), 0) + 1 FROM deck_template_versions WHERE template_id = :template');
            $next->execute(['template' => $templateId]);
            $version = (int) $next->fetchColumn();
            $created = self::insertVersion($database, $templateId, $version, $definitions, $defaultBackAssetId);
            Security::audit($database, (string) $user['id'], 'template.version_created', 'deck_template', $templateId, ['version' => $version]);
            $database->commit();
            return ['template_id' => $templateId, 'version_id' => $created['version_id'], 'version' => $version, 'definition_count' => $created['definition_count']];
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    /** @return list<string> */
    private static function validateDefinitions(array $definitions, ?string $defaultBackAssetId): array
    {
        if (count($definitions) < 1 || count($definitions) > 1000) throw new RuntimeException('A template version needs between 1 and 1000 card definitions.');
        $ids = [];
        if ($defaultBackAssetId !== null) {
            if (!self::isUuid($defaultBackAssetId)) throw new RuntimeException('Default card back is invalid.');
            $ids[] = $defaultBackAssetId;
        }
        foreach (array_values($definitions) as $definition) {
            if (!is_array($definition)) throw new RuntimeException('Card definition is invalid.');
            $quantity = filter_var($definition['quantity'] ?? 1, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 1 || $quantity > 10000) throw new RuntimeException('Card quantity must be between 1 and 10000.');
            if (isset($definition['display_name']) && (!is_string($definition['display_name']) || mb_strlen(trim($definition['display_name'])) > 160 || preg_match('/[\x00-\x1F\x7F]/', $definition['display_name']) === 1)) throw new RuntimeException('Card label is invalid.');
            $front = $definition['front_asset_id'] ?? null;
            if (!is_string($front) || !self::isUuid($front)) throw new RuntimeException('A card front image is required.');
            $ids[] = $front;
            $back = $definition['back_asset_id'] ?? null;
            if ($back !== null && $back !== '') {
                if (!is_string($back) || !self::isUuid($back)) throw new RuntimeException('Card back image is invalid.');
                $ids[] = $back;
            }
        }
        return array_values(array_unique($ids));
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value) === 1;
    }

    /** @param list<string> $assetIds */
    private static function assertAssetsOwned(PDO $database, string $userId, array $assetIds): void
    {
        if ($assetIds === []) return;
        $placeholders = [];
        $parameters = ['owner' => $userId];
        foreach ($assetIds as $index => $assetId) {
            $key = 'asset_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $assetId;
        }
        $statement = $database->prepare('SELECT count(DISTINCT asset_id) FROM asset_owners WHERE user_id = :owner AND asset_id IN (' . implode(', ', $placeholders) . ')');
        $statement->execute($parameters);
        if ((int) $statement->fetchColumn() !== count($assetIds)) throw new RuntimeException('An image is not available to this account.');
    }

    /** @return array{version_id:string,definition_count:int} */
    private static function insertVersion(PDO $database, string $templateId, int $version, array $definitions, ?string $defaultBackAssetId): array
    {
        $insertVersion = $database->prepare('INSERT INTO deck_template_versions(template_id, version, default_back_asset_id) VALUES (:template, :version, :default_back) RETURNING id');
        $insertVersion->execute(['template' => $templateId, 'version' => $version, 'default_back' => $defaultBackAssetId]);
        $versionId = (string) $insertVersion->fetchColumn();
        $insert = $database->prepare(
            'INSERT INTO card_definitions(template_version_id, ordinal, display_name, front_asset_id, back_asset_id, quantity, metadata)
             VALUES (:version, :ordinal, :name, :front, :back, :quantity, CAST(:metadata AS jsonb))',
        );
        foreach (array_values($definitions) as $ordinal => $definition) {
            $displayName = isset($definition['display_name']) ? trim((string) $definition['display_name']) : '';
            $insert->execute([
                'version' => $versionId, 'ordinal' => $ordinal,
                'name' => $displayName !== '' ? $displayName : null,
                'front' => (string) $definition['front_asset_id'],
                'back' => isset($definition['back_asset_id']) && $definition['back_asset_id'] !== '' ? (string) $definition['back_asset_id'] : null,
                'quantity' => (int) ($definition['quantity'] ?? 1),
                'metadata' => json_encode(is_array($definition['metadata'] ?? null) ? $definition['metadata'] : [], JSON_THROW_ON_ERROR),
            ]);
        }
        return ['version_id' => $versionId, 'definition_count' => count($definitions)];
    }

    /** Return only the caller's immutable templates and versions for session setup. */
    public static function listOwned(PDO $database, array $user): array
    {
        $templates = $database->prepare('SELECT id, name, created_at FROM deck_templates WHERE owner_user_id = :owner ORDER BY created_at DESC, id');
        $templates->execute(['owner' => $user['id']]);
        $versions = $database->prepare(
            'SELECT v.id, v.template_id, v.version, v.created_at, count(d.id) AS definition_count
             FROM deck_template_versions v
             JOIN deck_templates t ON t.id = v.template_id AND t.owner_user_id = :owner
             LEFT JOIN card_definitions d ON d.template_version_id = v.id
             GROUP BY v.id, v.template_id, v.version, v.created_at
             ORDER BY v.template_id, v.version DESC, v.id',
        );
        $versions->execute(['owner' => $user['id']]);
        $byTemplate = [];
        foreach ($versions as $version) {
            $templateId = (string) $version['template_id'];
            $byTemplate[$templateId][] = [
                'id' => (string) $version['id'],
                'version' => (int) $version['version'],
                'definition_count' => (int) $version['definition_count'],
                'created_at' => (string) $version['created_at'],
            ];
        }
        return ['templates' => array_map(static function (array $template) use ($byTemplate): array {
            $id = (string) $template['id'];
            return ['id' => $id, 'name' => (string) $template['name'], 'created_at' => (string) $template['created_at'], 'versions' => $byTemplate[$id] ?? []];
        }, $templates->fetchAll())];
    }
}
