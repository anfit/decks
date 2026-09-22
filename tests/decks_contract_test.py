from __future__ import annotations

import unittest

from contract_test_support import read_text


class DecksContractTest(unittest.TestCase):
    def test_snapshot_uses_container_counts_and_filters_private_hand_rows(self) -> None:
        source = read_text("src/ActionService.php")
        self.assertIn("'containers' => $containerProjection", source)
        self.assertIn("FROM session_decks d", source)
        self.assertIn("'label' => $deck['label']", source)
        self.assertIn("$card['location_type'] === 'removed'", source)
        self.assertIn("$card['location_type'] === 'pile' && !$isPublicFaceUp", source)
        self.assertIn("'zones' => $zoneProjection", source)
        self.assertIn("'hand_participant_id' => $isOwnHand", source)
        self.assertIn("c.face_state, c.owner_user_id, c.version", source)
        self.assertIn("SELECT id, geometry, priority, behavior FROM session_zones", read_text("src/CardService.php"))

    def test_registry_contains_atomic_deck_pile_reset_and_zone_families(self) -> None:
        source = read_text("src/ActionService.php")
        for action in ("'deal'", "'cut_deck'", "'insert_cards'", "'split_deck'", "'reverse_pile'", "'flip_pile'", "'spread_pile'", "'draw_pile_top'", "'draw_pile_bottom'", "'split_pile'", "'merge_piles'", "'collect_spread'", "'move_pile'", "'rotate_pile'", "'label_pile'", "'lock_pile'", "'rotate_card'", "'collect_all'", "'reset_session'", "'create_zone'", "'delete_zone'", "'configure_table'", "'remove_card'", "'restore_card'", "'lock_card'", "'move_cards'", "'reorder_hand'", "'give_cards'", "'peek_card'", "'transfer_host'", "'remove_participant'", "'restore_participant'"):
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
        self.assertIn("'undo_action'", action_source)
        self.assertIn("The card changed; undo is no longer safe.", action_source)
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
        self.assertIn("'actor' => $row['actor_user_id'] !== null", source)
        self.assertIn("? 'you' : 'participant'", source)
        self.assertNotIn("'payload' => json_decode((string) $row['public_payload']", source)
        self.assertIn("sanitizeEvent($type, $result)", source)
        self.assertIn("'card_definition_id', 'cards'", source)
        self.assertIn("'owner_user_id', 'undo'", source)
        card_source = read_text("src/CardService.php")
        self.assertIn("applyZoneEffect", card_source)
        self.assertIn("Overlapping zones have conflicting effects", card_source)
        zone_source = read_text("src/ZoneService.php")
        self.assertIn("['none', 'face_up', 'face_down', 'stack', 'align', 'fan', 'owner_private']", zone_source)

    def test_frontend_action_history_uses_safe_descriptions_and_bounded_changes(self) -> None:
        frontend = read_text("frontend/src/main.ts")
        self.assertIn('heading.textContent = "Recent actions"', frontend)
        self.assertIn("revision - 50", frontend)
        self.assertIn("slice(-20).reverse()", frontend)
        self.assertIn('ACTION_DESCRIPTIONS[event.action_type] ?? "recorded a table action"', frontend)
        self.assertIn('event.actor === "you" ? "You" : "A participant"', frontend)
        self.assertIn("Recent actions are unavailable right now.", frontend)
        self.assertNotIn("event.payload", frontend)

    def test_frontend_draws_from_authorized_container_projection(self) -> None:
        source = read_text("frontend/src/main.ts")
        self.assertIn("containers: { decks", source)
        self.assertIn("for (const deck of state.containers.decks)", source)
        self.assertIn("function renderDeckControls(state: State, deck: Deck)", source)
        self.assertIn('button("Draw top"', source)
        self.assertIn('button("Draw bottom"', source)
        self.assertIn('button("Draw N from top"', source)
        self.assertIn('button("Draw N from bottom"', source)
        self.assertIn("pointerdown", source)
        self.assertIn("expected_card_version", source)

    def test_frontend_joins_opaque_tokens_without_browser_decoding(self) -> None:
        source = read_text("frontend/src/main.ts")
        self.assertIn('api("/api/sessions/join"', source)
        self.assertIn("result.membership?.session_id", source)
        self.assertNotIn("atob(", source)
        routes = read_text("public/index.php")
        self.assertIn("$path === '/api/sessions/join'", routes)
        self.assertIn("The authenticated join surface accepts the opaque", read_text("spec/04-actions.md"))

    def test_session_setup_lists_owned_tables_and_catalogs(self) -> None:
        routes = read_text("public/index.php")
        self.assertIn("$path === '/api/sessions' && $method === 'GET'", routes)
        self.assertIn("$path === '/api/templates' && $method === 'GET'", routes)
        self.assertIn("SessionService::listOwned", routes)
        self.assertIn("TemplateService::listOwned", routes)
        frontend = read_text("frontend/src/main.ts")
        for endpoint in ("api(\"/api/sessions\")", "api(\"/api/mats-and-presets\")", "api(\"/api/templates\")"):
            self.assertIn(endpoint, frontend)
        self.assertIn("sessionAction(result.session.id", frontend)
        self.assertIn('button("Add another deck"', frontend)
        self.assertIn('select[name="template"]', frontend)
        self.assertIn("selectedTemplateVersions.entries()", frontend)
        self.assertIn("Some selected decks may be missing.", frontend)
        self.assertIn("Spectator", frontend)
        self.assertIn("Your tables", frontend)

    def test_host_lifecycle_and_recovery_controls_are_role_and_capability_scoped(self) -> None:
        frontend = read_text("frontend/src/main.ts")
        self.assertIn('currentParticipant?.role === "host" && currentCan("session.manage")', frontend)
        self.assertIn('button("Start session"', frontend)
        self.assertIn('button("End session"', frontend)
        self.assertIn('button("Collect all"', frontend)
        self.assertIn('button("Reset table"', frontend)
        self.assertIn("End this session for everyone?", frontend)
        self.assertIn("Reset the table to the lobby and collect every card?", frontend)

    def test_deal_modes_have_distinct_round_robin_and_participant_major_order(self) -> None:
        source = read_text("src/CardService.php")
        self.assertIn("if ($mode === 'per_participant')", source)
        self.assertIn("foreach ($unique as $participant) for ($round = 0; $round < $count; $round++) $deliver($participant);", source)
        self.assertIn("for ($round = 0; $round < $count; $round++) foreach ($unique as $participant) $deliver($participant);", source)

    def test_pile_projection_and_operations_preserve_safe_metadata(self) -> None:
        action = read_text("src/ActionService.php")
        self.assertIn("'label' => $pile['label']", action)
        self.assertIn("'locked' => $pile['locked_by'] !== null", action)
        pile = read_text("src/PileService.php")
        for method in ("public static function draw", "public static function split", "public static function merge", "public static function collectSpread", "public static function updateGeometry", "public static function label", "public static function lock"):
            self.assertIn(method, pile)
        frontend = read_text("frontend/src/main.ts")
        for label in ("More pile actions", "Split top into new pile", "Merge at top", "Merge at bottom", "Collect selected cards into this pile", "Save pile label"):
            self.assertIn(label, frontend)
        self.assertIn("expected_target_version: destination.version", frontend)
        self.assertIn("candidate.id !== pile.id && !candidate.locked", frontend)
        self.assertIn("function renderPile(state: State, pile: Pile, interactive: boolean)", frontend)
        for label in ("Move pile left", "Move pile right", "Move pile up", "Move pile down", "Rotate pile 15°", "Bring pile to front", "Send pile to back"):
            self.assertIn(label, frontend)
        self.assertIn('"move_pile"', frontend)
        self.assertIn('"rotate_pile"', frontend)

    def test_restore_and_collect_modes_are_explicit_and_server_authoritative(self) -> None:
        card = read_text("src/CardService.php")
        for mode in ("top", "bottom", "shuffle"):
            self.assertIn(f"'{mode}'", card)
        action = read_text("src/ActionService.php")
        self.assertIn("['original', 'shuffle', 'preserve']", action)
        self.assertIn("CardService::shuffle($database, $session, $member", action)

    def test_visible_card_labels_and_accessible_controls_do_not_expand_hidden_projection(self) -> None:
        action = read_text("src/ActionService.php")
        self.assertIn("d.display_name", action)
        self.assertIn("'card_label'", action)
        frontend = read_text("frontend/src/main.ts")
        self.assertIn('item.tabIndex = 0', frontend)
        self.assertIn('event.key === "Enter"', frontend)
        self.assertIn('button("Play face up"', frontend)
        styles = read_text("frontend/src/styles.css")
        self.assertIn(":focus-visible", styles)
        self.assertIn("@media (max-width: 600px)", styles)

    def test_frontend_refresh_preserves_current_realtime_socket(self) -> None:
        frontend = read_text("frontend/src/main.ts")
        render_table = frontend.split("function renderTable(state: State): void {", 1)[1].split("\nfunction renderBoard", 1)[0]
        self.assertNotIn("connectRealtime(", render_table)
        open_table = frontend.split("async function openTable(sessionId: string): Promise<void> {", 1)[1].split("\n}\n", 1)[0]
        self.assertIn("connectRealtime(sessionId)", open_table)
        realtime = frontend.split("function disconnectRealtime(): void {", 1)[1].split("\nasync function start", 1)[0]
        self.assertIn("realtimeGeneration", realtime)
        self.assertIn("socket !== activeSocket", realtime)
        self.assertIn("window.clearTimeout", realtime)
        self.assertIn('activeSocket.addEventListener("open"', realtime)
        self.assertIn('activeSocket.addEventListener("message"', realtime)
        self.assertIn('activeSocket.addEventListener("close"', realtime)

    def test_table_capabilities_are_allowlisted_role_defaults_and_action_scoped(self) -> None:
        session = read_text("src/SessionService.php")
        action = read_text("src/ActionService.php")
        spec = read_text("spec/19-capability-policy.md")
        for capability in ("session.manage", "participant.manage", "zone.manage", "deck.manage", "card.manage", "pile.manage", "lock.manage", "card.undo"):
            self.assertIn(capability, session)
        self.assertIn("public static function hasCapability", session)
        self.assertIn("public static function setCapabilities", session)
        self.assertIn("'set_participant_capabilities'", action)
        self.assertIn("capabilityForAction", action)
        self.assertIn("SessionService::hasCapability", action)
        self.assertIn("'capabilities'", action)
        self.assertIn("capabilities'], true", action)
        self.assertIn("unauthorized actions to fail atomically", spec)
        self.assertIn("cannot change their own capabilities", session)

    def test_capability_controls_are_host_scoped_and_mutation_affordances_are_capability_aware(self) -> None:
        action = read_text("src/ActionService.php")
        frontend = read_text("frontend/src/main.ts")
        styles = read_text("frontend/src/styles.css")
        self.assertIn("$isCurrent || ($member['role'] ?? '') === 'host'", action)
        self.assertIn("Participant capabilities", frontend)
        self.assertIn("set_participant_capabilities", frontend)
        self.assertIn("currentCan(\"deck.manage\")", frontend)
        self.assertIn("currentCan(\"pile.manage\")", frontend)
        self.assertIn("currentCan(\"card.manage\")", frontend)
        self.assertIn("capability-row", styles)

    def test_group_card_actions_validate_selection_privacy_and_expose_accessible_selection_tools(self) -> None:
        card = read_text("src/CardService.php")
        pile = read_text("src/PileService.php")
        action = read_text("src/ActionService.php")
        frontend = read_text("frontend/src/main.ts")
        spec = read_text("spec/20-multi-card-and-z-order.md")
        for method in ("public static function rotateCards", "public static function setCardsFace", "public static function reorderCards", "private static function tableSelection"):
            self.assertIn(method, card)
        self.assertIn("That table card is private.", card)
        self.assertIn("That table card is private.", pile)
        self.assertIn("Expected card versions are required.", card)
        self.assertIn("'rotate_cards' => CardService::rotateCards", action)
        self.assertIn("'set_cards_face' => CardService::setCardsFace", action)
        self.assertIn("'reorder_cards' => CardService::reorderCards", action)
        for label in ("Select cards", "Turn selected face up", "Bring selection to front", "Send selection to back"):
            self.assertIn(label, frontend)
        self.assertIn("expected_card_versions", frontend)
        self.assertIn("atomic stale-selection rejection", spec)

    def test_group_reorder_and_bulk_mutations_honor_locks_and_composite_capabilities(self) -> None:
        card = read_text("src/CardService.php")
        pile = read_text("src/PileService.php")
        action = read_text("src/ActionService.php")
        capabilities = read_text("spec/19-capability-policy.md")
        self.assertIn("$card['locked_by'] !== null", card)
        self.assertIn("$requiresCompaction", card)
        self.assertIn("A table card is locked by another participant.", card)
        self.assertIn("foreach ($rows as $card) self::assertCanControl($card, $member);", pile)
        self.assertIn("count(array_unique(array_map('strval', $ids))) !== count($ids)", pile)
        self.assertIn("public static function collectSpread", pile)
        self.assertIn("$requiredCapabilities[] = 'pile.manage'", action)
        self.assertIn("both `deck.manage` and `pile.manage`", capabilities)

    def test_production_shell_contains_frontend_mount_points(self) -> None:
        source = read_text("public/index.php")
        self.assertIn('id="workspace"', source)
        self.assertIn('id="connection-status"', source)
        self.assertIn('aria-labelledby="welcome-title"', source)

    def test_first_admin_bootstrap_supports_one_time_hash_migration(self) -> None:
        source = read_text("scripts/bootstrap-admin.php")
        self.assertIn("DECKS_ADMIN_PASSWORD_HASH", source)
        self.assertIn("password_get_info", source)
        self.assertIn("$storedPasswordHash", source)

    def test_account_administration_matches_reference_surface(self) -> None:
        security = read_text("src/Security.php")
        invitations = read_text("src/InvitationService.php")
        routes = read_text("public/index.php")
        self.assertIn("changePassword", security)
        self.assertIn("setRole", security)
        self.assertIn("setEnabled", security)
        self.assertIn("protectFinalAdmin", security)
        self.assertIn("signOutEverywhere", security)
        for method in ("listIssued", "listAll", "resend", "rescind", "restoreCredit"):
            self.assertIn(method, invitations)
        for route in ("/account", "/logout-everywhere", "/admin/users", "/admin/invitations"):
            self.assertIn(route, routes)

    def test_account_security_has_rate_limits_and_outbox_linkage(self) -> None:
        self.assertIn("request_rate_limits", read_text("migrations/005_account_administration.sql"))
        self.assertIn("user_invitation_id", read_text("migrations/005_account_administration.sql"))
        self.assertIn("class RateLimiter", read_text("src/RateLimiter.php"))
        self.assertIn("RateLimiter::consume", read_text("public/index.php"))
        self.assertIn("delivery_state = 'failed'", read_text("scripts/send-mail-outbox.php"))

    def test_retention_entrypoint_is_read_only_by_default_and_confirmation_gated(self) -> None:
        source = read_text("scripts/retention.php")
        self.assertIn("DECKS_RETENTION_CONFIRM=apply", source)
        self.assertIn("$apply = in_array('--apply', $argv, true)", source)
        self.assertIn("DECKS_RETENTION_ALLOW_SESSION_DELETE", source)
        self.assertIn("body = '[redacted]'", source)
        self.assertIn("scripts/retention.php", read_text(".deployer/retention.sh"))

    def test_database_contract_keeps_session_scoped_card_foreign_keys(self) -> None:
        source = read_text("migrations/001_initial_schema.sql")
        self.assertIn("FOREIGN KEY (session_id, source_deck_id)", source)
        self.assertIn("FOREIGN KEY (session_id, deck_id)", source)
        self.assertIn("FOREIGN KEY (session_id, pile_id)", source)
        self.assertIn("card_location_shape", source)


if __name__ == "__main__":
    unittest.main()
