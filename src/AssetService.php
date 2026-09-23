<?php

declare(strict_types=1);

namespace Decks;

use PDO;
use RuntimeException;

final class AssetService
{
    public static function storeUpload(PDO $database, array $user, array $upload): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)) {
            throw new RuntimeException('The image upload failed.');
        }
        $size = (int) ($upload['size'] ?? 0);
        if ($size < 1 || $size > 10 * 1024 * 1024) throw new RuntimeException('The image is too large.');
        $info = @getimagesize($upload['tmp_name']);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!is_array($info) || !isset($allowed[$mime]) || (int) $info[0] < 1 || (int) $info[1] < 1 || (int) $info[0] * (int) $info[1] > 25_000_000) {
            throw new RuntimeException('Only bounded JPEG, PNG or WebP images are accepted.');
        }
        $hash = hash_file('sha256', $upload['tmp_name']);
        if (!is_string($hash)) throw new RuntimeException('The image could not be read.');
        $root = getenv('DECKS_ASSET_PATH') ?: dirname(__DIR__) . '/storage/assets';
        if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) throw new RuntimeException('Asset storage is unavailable.');
        $storageKey = $hash . '.' . $allowed[$mime];
        $destination = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storageKey;
        $createdFile = false;
        $partialFile = false;
        $database->beginTransaction();
        try {
            $database->prepare('SELECT pg_advisory_xact_lock(hashtext(:content_hash))')->execute(['content_hash' => $hash]);
            $existing = $database->prepare('SELECT id, storage_key FROM assets WHERE content_hash = :hash FOR UPDATE');
            $existing->execute(['hash' => $hash]);
            $row = $existing->fetch();
            if (is_array($row)) {
                $storageKey = (string) $row['storage_key'];
                $destination = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($storageKey);
                if (!is_file($destination)) {
                    $partialFile = true;
                    if (!copy($upload['tmp_name'], $destination)) throw new RuntimeException('The image could not be stored.');
                    $partialFile = false;
                } elseif (!hash_equals($hash, (string) hash_file('sha256', $destination))) {
                    throw new RuntimeException('Stored image bytes failed their content check.');
                }
                $database->prepare('INSERT INTO asset_owners(asset_id, user_id) VALUES (:asset, :owner) ON CONFLICT DO NOTHING')
                    ->execute(['asset' => $row['id'], 'owner' => $user['id']]);
                $database->commit();
                return ['id' => (string) $row['id'], 'storage_key' => $storageKey];
            }
            if (is_file($destination)) {
                if (!hash_equals($hash, (string) hash_file('sha256', $destination))) throw new RuntimeException('Stored image bytes failed their content check.');
            } else {
                $createdFile = true;
                if (!copy($upload['tmp_name'], $destination)) throw new RuntimeException('The image could not be stored.');
            }
            $insert = $database->prepare(
                'INSERT INTO assets(owner_user_id, content_hash, storage_key, mime_type, byte_size, width, height)
                 VALUES (:owner, :hash, :storage_key, :mime, :size, :width, :height) RETURNING id',
            );
            $insert->execute(['owner' => $user['id'], 'hash' => $hash, 'storage_key' => $storageKey, 'mime' => $mime, 'size' => $size, 'width' => (int) $info[0], 'height' => (int) $info[1]]);
            $assetId = (string) $insert->fetchColumn();
            $database->prepare('INSERT INTO asset_owners(asset_id, user_id) VALUES (:asset, :owner)')
                ->execute(['asset' => $assetId, 'owner' => $user['id']]);
            $database->commit();
            return ['id' => $assetId, 'storage_key' => $storageKey];
        } catch (\Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            if (($createdFile || $partialFile) && is_file($destination)) @unlink($destination);
            throw $error;
        }
    }

    /** List only assets explicitly available to this account, never internal storage keys. */
    public static function listOwned(PDO $database, array $user): array
    {
        $statement = $database->prepare(
            'SELECT a.id, a.mime_type, a.byte_size, a.width, a.height, a.created_at
             FROM assets a JOIN asset_owners o ON o.asset_id = a.id
             WHERE o.user_id = :owner ORDER BY a.created_at DESC, a.id',
        );
        $statement->execute(['owner' => $user['id']]);
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'], 'mime_type' => (string) $row['mime_type'], 'byte_size' => (int) $row['byte_size'],
            'width' => (int) $row['width'], 'height' => (int) $row['height'], 'created_at' => (string) $row['created_at'],
        ], $statement->fetchAll());
    }

    public static function owns(PDO $database, string $userId, string $assetId): bool
    {
        $statement = $database->prepare('SELECT 1 FROM asset_owners WHERE asset_id = :asset AND user_id = :owner');
        $statement->execute(['asset' => $assetId, 'owner' => $userId]);
        return $statement->fetchColumn() !== false;
    }

    public static function pathForOwner(PDO $database, array $user, string $assetId): array
    {
        $statement = $database->prepare('SELECT a.* FROM assets a JOIN asset_owners o ON o.asset_id = a.id WHERE a.id = :id AND o.user_id = :owner');
        $statement->execute(['id' => $assetId, 'owner' => $user['id']]);
        $asset = $statement->fetch();
        if (!is_array($asset)) throw new RuntimeException('Asset not found.');
        $root = getenv('DECKS_ASSET_PATH') ?: dirname(__DIR__) . '/storage/assets';
        $path = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename((string) $asset['storage_key']);
        if (!is_file($path)) throw new RuntimeException('Asset bytes are unavailable.');
        return ['path' => $path, 'storage_key' => (string) $asset['storage_key'], 'mime_type' => (string) $asset['mime_type']];
    }

    public static function pathForCardFront(PDO $database, array $user, string $sessionId, string $cardId): array
    {
        $statement = $database->prepare(
            "SELECT a.storage_key, a.mime_type
             FROM assets a
             JOIN card_definitions d ON d.front_asset_id = a.id
             JOIN session_cards c ON c.card_definition_id = d.id
             JOIN session_participants p ON p.session_id = c.session_id
             WHERE c.session_id = :session AND c.id = :card AND p.user_id = :user AND p.removed_at IS NULL
               AND a.mime_type IN ('image/jpeg', 'image/png', 'image/webp')
               AND (
                   (c.location_type IN ('table', 'pile') AND c.face_state = 'up')
                   OR (c.location_type = 'hand' AND c.hand_participant_id = p.id)
                   OR (c.location_type = 'table' AND c.face_state = 'private' AND c.owner_user_id = p.user_id)
               )
             LIMIT 1",
        );
        $statement->execute(['session' => $sessionId, 'card' => $cardId, 'user' => $user['id']]);
        $asset = $statement->fetch();
        if (!is_array($asset)) throw new RuntimeException('Asset not found.');
        $root = getenv('DECKS_ASSET_PATH') ?: dirname(__DIR__) . '/storage/assets';
        $path = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename((string) $asset['storage_key']);
        if (!is_file($path)) throw new RuntimeException('Asset bytes are unavailable.');
        return ['path' => $path, 'storage_key' => (string) $asset['storage_key'], 'mime_type' => (string) $asset['mime_type']];
    }

    public static function pathForCardBack(PDO $database, array $user, string $sessionId, string $cardId): array
    {
        $statement = $database->prepare(
            "SELECT a.storage_key, a.mime_type
             FROM session_cards c
             JOIN card_definitions d ON d.id = c.card_definition_id
             JOIN deck_template_versions v ON v.id = d.template_version_id
             JOIN assets a ON a.id = COALESCE(d.back_asset_id, v.default_back_asset_id)
             JOIN session_participants p ON p.session_id = c.session_id
             WHERE c.session_id = :session AND c.id = :card AND p.user_id = :user AND p.removed_at IS NULL
               AND c.location_type = 'table' AND c.face_state IN ('down', 'private')
               AND a.mime_type IN ('image/jpeg', 'image/png', 'image/webp')
             LIMIT 1",
        );
        $statement->execute(['session' => $sessionId, 'card' => $cardId, 'user' => $user['id']]);
        $asset = $statement->fetch();
        if (!is_array($asset)) throw new RuntimeException('Asset not found.');
        $root = getenv('DECKS_ASSET_PATH') ?: dirname(__DIR__) . '/storage/assets';
        $path = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename((string) $asset['storage_key']);
        if (!is_file($path)) throw new RuntimeException('Asset bytes are unavailable.');
        return ['path' => $path, 'storage_key' => (string) $asset['storage_key'], 'mime_type' => (string) $asset['mime_type']];
    }
}
