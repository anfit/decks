<?php

declare(strict_types=1);

namespace Decks;

use PDO;
use RuntimeException;

final class DeckService
{
    public static function instantiate(PDO $database, array $session, array $user, string $versionId, ?string $label = null): array
    {
        $version = $database->prepare(
            'SELECT v.id, v.template_id, t.owner_user_id FROM deck_template_versions v JOIN deck_templates t ON t.id = v.template_id WHERE v.id = :id',
        );
        $version->execute(['id' => $versionId]);
        $template = $version->fetch();
        if (!is_array($template) || (string) $template['owner_user_id'] !== (string) $user['id']) throw new RuntimeException('Deck template version is not available.');
        $definitions = $database->prepare('SELECT id, quantity FROM card_definitions WHERE template_version_id = :version ORDER BY ordinal, id');
        $definitions->execute(['version' => $versionId]);
        $rows = $definitions->fetchAll();
        if (count($rows) === 0) throw new RuntimeException('A deck template version has no cards.');
        $insertDeck = $database->prepare('INSERT INTO session_decks(session_id, template_version_id, label) VALUES (:session, :version, :label) RETURNING id');
        $insertDeck->execute(['session' => $session['id'], 'version' => $versionId, 'label' => $label]);
        $deckId = (string) $insertDeck->fetchColumn();
        $insertCard = $database->prepare(
            "INSERT INTO session_cards(session_id, card_definition_id, source_deck_id, location_type, deck_id, order_key, face_state)
             VALUES (:session, :definition, :source, 'deck', :deck, :order_key, 'down')",
        );
        $order = 1000;
        $count = 0;
        foreach ($rows as $definition) {
            for ($copy = 0; $copy < (int) $definition['quantity']; $copy++) {
                $insertCard->execute(['session' => $session['id'], 'definition' => $definition['id'], 'source' => $deckId, 'deck' => $deckId, 'order_key' => $order]);
                $order += 1000;
                $count++;
            }
        }
        return ['deck_id' => $deckId, 'card_count' => $count, 'template_version_id' => $versionId];
    }
}
