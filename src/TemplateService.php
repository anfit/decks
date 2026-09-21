<?php

declare(strict_types=1);

namespace Decks;

use PDO;
use RuntimeException;

final class TemplateService
{
    public static function create(PDO $database, array $user, string $name): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) throw new RuntimeException('Template name is invalid.');
        $database->beginTransaction();
        try {
            $template = $database->prepare('INSERT INTO deck_templates(owner_user_id, name) VALUES (:owner, :name) RETURNING id');
            $template->execute(['owner' => $user['id'], 'name' => $name]);
            $row = $template->fetch();
            $version = $database->prepare('INSERT INTO deck_template_versions(template_id, version) VALUES (:template, 1) RETURNING id, version');
            $version->execute(['template' => $row['id']]);
            $versionRow = $version->fetch();
            Security::audit($database, (string) $user['id'], 'template.created', 'deck_template', (string) $row['id']);
            $database->commit();
            return ['id' => (string) $row['id'], 'version_id' => (string) $versionRow['id'], 'version' => 1, 'name' => $name];
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function createVersion(PDO $database, array $user, string $templateId, array $definitions): array
    {
        if (count($definitions) > 1000) throw new RuntimeException('Too many card definitions.');
        $database->beginTransaction();
        try {
            $template = $database->prepare('SELECT * FROM deck_templates WHERE id = :id AND owner_user_id = :owner FOR UPDATE');
            $template->execute(['id' => $templateId, 'owner' => $user['id']]);
            if (!$template->fetch()) throw new RuntimeException('Template not found.');
            $next = $database->prepare('SELECT coalesce(max(version), 0) + 1 FROM deck_template_versions WHERE template_id = :template');
            $next->execute(['template' => $templateId]);
            $version = (int) $next->fetchColumn();
            $insertVersion = $database->prepare('INSERT INTO deck_template_versions(template_id, version) VALUES (:template, :version) RETURNING id');
            $insertVersion->execute(['template' => $templateId, 'version' => $version]);
            $versionId = (string) $insertVersion->fetchColumn();
            $insert = $database->prepare(
                'INSERT INTO card_definitions(template_version_id, ordinal, display_name, front_asset_id, back_asset_id, quantity, metadata)
                 VALUES (:version, :ordinal, :name, :front, :back, :quantity, CAST(:metadata AS jsonb))',
            );
            foreach (array_values($definitions) as $ordinal => $definition) {
                $quantity = (int) ($definition['quantity'] ?? 1);
                if ($quantity < 1 || $quantity > 10000 || !isset($definition['front_asset_id'])) throw new RuntimeException('Card definition is invalid.');
                $insert->execute(['version' => $versionId, 'ordinal' => $ordinal, 'name' => isset($definition['display_name']) ? (string) $definition['display_name'] : null, 'front' => (string) $definition['front_asset_id'], 'back' => isset($definition['back_asset_id']) ? (string) $definition['back_asset_id'] : null, 'quantity' => $quantity, 'metadata' => json_encode($definition['metadata'] ?? [], JSON_THROW_ON_ERROR)]);
            }
            Security::audit($database, (string) $user['id'], 'template.version_created', 'deck_template', $templateId, ['version' => $version]);
            $database->commit();
            return ['template_id' => $templateId, 'version_id' => $versionId, 'version' => $version, 'definition_count' => count($definitions)];
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }
}
