from __future__ import annotations

import unittest

from contract_test_support import read_text


class DecksContractTest(unittest.TestCase):
    def test_snapshot_uses_container_counts_and_filters_private_hand_rows(self) -> None:
        source = read_text("src/ActionService.php")
        self.assertIn("'containers' => $containerProjection", source)
        self.assertIn("$card['location_type'] === 'removed'", source)
        self.assertIn("$card['location_type'] === 'pile' && !$isPublicFaceUp", source)
        self.assertIn("'zones' => $zoneProjection", source)
        self.assertIn("'hand_participant_id' => $isOwnHand", source)

    def test_registry_contains_atomic_deck_pile_reset_and_zone_families(self) -> None:
        source = read_text("src/ActionService.php")
        for action in ("'deal'", "'cut_deck'", "'insert_cards'", "'split_deck'", "'reverse_pile'", "'flip_pile'", "'collect_all'", "'reset_session'", "'create_zone'", "'delete_zone'", "'configure_table'", "'remove_card'", "'restore_card'", "'lock_card'", "'move_cards'", "'reorder_hand'", "'give_cards'", "'peek_card'"):
            self.assertIn(action, source)

    def test_mats_presets_and_private_zone_effects_are_authorized(self) -> None:
        source = read_text("src/MatPresetService.php")
        self.assertIn("createPreset", source)
        self.assertIn("Preset template version is not available", source)
        self.assertIn("normalizeConfiguration", source)
        action_source = read_text("src/ActionService.php")
        self.assertIn("normalizedPreset", action_source)
        card_source = read_text("src/CardService.php")
        self.assertIn("owner_private", card_source)
        self.assertIn("isOwnPrivateTable", action_source)
        migration = read_text("migrations/004_mats_and_presets.sql")
        self.assertIn("CREATE TABLE table_presets", migration)

    def test_realtime_reauthenticates_and_checks_browser_origin(self) -> None:
        source = read_text("realtime/server.mjs")
        self.assertIn("allowedOrigin", source)
        self.assertIn("request.headers.origin", source)
        self.assertIn("authorizationCheck", source)
        self.assertIn("maintainListener", source)
        self.assertIn("Number(payload.exp) < Math.floor(Date.now() / 1000)", source)

    def test_changes_endpoint_never_replays_raw_action_payloads(self) -> None:
        source = read_text("src/ActionService.php")
        self.assertIn("'action_type' => (string) $row['action_type']", source)
        self.assertNotIn("'payload' => json_decode((string) $row['public_payload']", source)
        self.assertIn("sanitizeEvent($type, $result)", source)
        self.assertIn("'card_definition_id', 'cards'", source)
        card_source = read_text("src/CardService.php")
        self.assertIn("applyZoneEffect", card_source)
        self.assertIn("Overlapping zones have conflicting effects", card_source)
        zone_source = read_text("src/ZoneService.php")
        self.assertIn("['none', 'face_up', 'face_down', 'stack', 'align', 'fan', 'owner_private']", zone_source)

    def test_frontend_draws_from_authorized_container_projection(self) -> None:
        source = read_text("frontend/src/main.ts")
        self.assertIn("containers: { decks", source)
        self.assertIn("const deck = state.containers.decks[0]", source)
        self.assertIn("pointerdown", source)
        self.assertIn("expected_card_version", source)

    def test_first_admin_bootstrap_supports_one_time_hash_migration(self) -> None:
        source = read_text("scripts/bootstrap-admin.php")
        self.assertIn("DECKS_ADMIN_PASSWORD_HASH", source)
        self.assertIn("password_get_info", source)
        self.assertIn("$storedPasswordHash", source)

    def test_database_contract_keeps_session_scoped_card_foreign_keys(self) -> None:
        source = read_text("migrations/001_initial_schema.sql")
        self.assertIn("FOREIGN KEY (session_id, source_deck_id)", source)
        self.assertIn("FOREIGN KEY (session_id, deck_id)", source)
        self.assertIn("FOREIGN KEY (session_id, pile_id)", source)
        self.assertIn("card_location_shape", source)


if __name__ == "__main__":
    unittest.main()
