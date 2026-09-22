import "./styles.css";

type User = { id: string; email: string; role: string };
type Session = { id: string; title: string | null; status: string; revision: number; host_user_id: string };
type Preset = { id: string; name: string; template_version_id: string; mat_version_id: string | null; configuration: Record<string, unknown> };
type TemplateVersion = { id: string; version: number; definition_count: number };
type Template = { id: string; name: string; versions: TemplateVersion[] };
type Pile = { id: string; card_count: number; label: string | null; x: number; y: number; rotation: number; z_index: number; locked: boolean; version: number };
type Card = { id: string; location_type: string; deck_id: string | null; pile_id: string | null; hand_participant_id: string | null; card_definition_id?: string; card_label?: string; face_state: string; x: number | null; y: number | null; rotation: number; z_index: number; version: number };
type Capability = "session.manage" | "participant.manage" | "zone.manage" | "deck.manage" | "card.manage" | "pile.manage" | "lock.manage" | "card.undo";
type Participant = { id: string; role: string; is_current: boolean; hand_count: number; capabilities?: Partial<Record<Capability, boolean>> };
type State = { revision: number; session: Session; configuration: { mat?: { label?: string; color?: string }; preset_id?: string | null }; participants: Participant[]; containers: { decks: Array<{ id: string; card_count: number }>; piles: Pile[] }; zones: Array<{ id: string; name: string; geometry: { x: number; y: number; width: number; height: number }; priority: number; behavior: Record<string, unknown> }>; cards: Card[] };
const CAPABILITIES: Array<{ key: Capability; label: string }> = [
  { key: "session.manage", label: "Session administration" }, { key: "participant.manage", label: "Participant administration" },
  { key: "zone.manage", label: "Zone administration" }, { key: "deck.manage", label: "Deck actions" },
  { key: "card.manage", label: "Card actions" }, { key: "pile.manage", label: "Pile actions" },
  { key: "lock.manage", label: "Lock actions" }, { key: "card.undo", label: "Undo" },
];

const workspace = document.querySelector<HTMLElement>("#workspace");
const status = document.querySelector<HTMLElement>("#connection-status");
let csrf = "";
let currentState: State | null = null;
let socket: WebSocket | null = null;
let realtimeRetry = 0;
let selectionMode = false;
const selectedCardIds = new Set<string>();
let selectionCountLabel: HTMLElement | null = null;
let selectionActionButtons: HTMLButtonElement[] = [];

function setStatus(message: string, state: "ok" | "error" | "pending" = "pending"): void {
  if (!status) return;
  status.textContent = message;
  status.dataset.state = state;
}

async function api(path: string, init: RequestInit = {}): Promise<any> {
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  if (init.body && !headers.has("Content-Type")) headers.set("Content-Type", "application/json");
  if (init.method && init.method !== "GET") headers.set("X-CSRF-Token", csrf);
  const response = await fetch(path, { ...init, headers });
  const body: any = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(typeof body.message === "string" ? body.message : (body.error ?? "Request failed"));
  return body;
}

function button(label: string, onClick: () => void, secondary = false): HTMLButtonElement {
  const item = document.createElement("button");
  item.type = "button"; item.textContent = label; if (secondary) item.className = "secondary";
  item.addEventListener("click", onClick); return item;
}

function labelled(labelText: string, control: HTMLElement): HTMLLabelElement {
  const label = document.createElement("label"); label.textContent = labelText; label.append(control); return label;
}

function renderHome(user: User): void {
  if (!workspace) return;
  currentState = null; socket?.close(); socket = null;
  workspace.replaceChildren();
  const greeting = document.createElement("p"); greeting.textContent = `Signed in as ${user.email}`; workspace.append(greeting);
  const create = document.createElement("section"); create.className = "panel";
  const createHeading = document.createElement("h2"); createHeading.textContent = "Create a table"; create.append(createHeading);
  const title = document.createElement("input"); title.placeholder = "Table name (optional)"; title.autocomplete = "off";
  const maxParticipants = document.createElement("input"); maxParticipants.type = "number"; maxParticipants.min = "1"; maxParticipants.max = "100"; maxParticipants.value = "12";
  const preset = document.createElement("select"); preset.name = "preset"; preset.innerHTML = '<option value="">No preset</option>';
  const template = document.createElement("select"); template.name = "template"; template.innerHTML = '<option value="">No standalone deck</option>';
  const createButton = button("Create table", async () => {
    try {
      const result = await api("/api/sessions", { method: "POST", body: JSON.stringify({ title: title.value.trim() || null, max_participants: Number(maxParticipants.value) || 12 }) });
      let revision = Number(result.session.revision ?? 0);
      const selectedPreset = preset.value ? (preset.selectedOptions[0]?.dataset.templateVersion ? { id: preset.value, template_version_id: preset.selectedOptions[0].dataset.templateVersion } : null) : null;
      const selectedTemplateVersion = template.value || null;
      if (selectedPreset) {
        const configured = await sessionAction(result.session.id, revision, "configure_table", { preset_id: selectedPreset.id }); revision = Number(configured.revision);
        const instantiated = await sessionAction(result.session.id, revision, "instantiate_deck", { template_version_id: selectedPreset.template_version_id, label: "Preset deck" }); revision = Number(instantiated.revision);
      } else if (selectedTemplateVersion) {
        await sessionAction(result.session.id, revision, "instantiate_deck", { template_version_id: selectedTemplateVersion, label: "Deck" });
      }
      showJoinResult(result.session);
    }
    catch (error) { setStatus((error as Error).message, "error"); }
  });
  create.append(labelled("Table name", title), labelled("Maximum participants", maxParticipants), labelled("Preset", preset), labelled("Deck template version", template), createButton); workspace.append(create);
  const join = document.createElement("section"); join.className = "panel";
  const joinHeading = document.createElement("h2"); joinHeading.textContent = "Join a table"; join.append(joinHeading);
  const token = document.createElement("input"); token.placeholder = "Paste table join token"; token.autocomplete = "off";
  const role = document.createElement("select"); role.name = "role"; role.innerHTML = '<option value="player">Player</option><option value="spectator">Spectator</option>';
  const joinButton = button("Join table", async () => {
    try {
      const value = token.value.trim(); if (!value) throw new Error("Paste the complete table token.");
      const result = await api("/api/sessions/join", { method: "POST", body: JSON.stringify({ token: value, role: role.value }) });
      const sessionId = result.membership?.session_id;
      if (typeof sessionId !== "string" || !sessionId) throw new Error("The table invitation did not return a session.");
      await openTable(sessionId);
    } catch (error) { setStatus((error as Error).message, "error"); }
  });
  join.append(labelled("Table token", token), labelled("Role", role), joinButton); workspace.append(join);
  const invite = document.createElement("a"); invite.href = "/account/invite"; invite.textContent = "Invite someone to Decks"; workspace.append(invite);
  void loadHomeData(preset, template);
  setStatus("Ready", "ok");
}

async function sessionAction(sessionId: string, revision: number, type: string, payload: Record<string, unknown>): Promise<any> {
  return api(`/api/sessions/${sessionId}/actions`, { method: "POST", body: JSON.stringify({ action_id: crypto.randomUUID(), type, payload, expected_session_revision: revision }) });
}

async function loadHomeData(preset: HTMLSelectElement, template: HTMLSelectElement): Promise<void> {
  try {
    const [sessions, presets, templates] = await Promise.all([api("/api/sessions"), api("/api/mats-and-presets"), api("/api/templates")]);
    if (currentState !== null || !workspace) return;
    for (const item of (presets.presets as Preset[] ?? [])) {
      const option = document.createElement("option"); option.value = item.id; option.textContent = item.name; option.dataset.templateVersion = item.template_version_id; preset.append(option);
    }
    for (const item of (templates.templates as Template[] ?? [])) {
      for (const version of item.versions ?? []) {
        if (version.definition_count < 1) continue;
        const option = document.createElement("option"); option.value = version.id; option.textContent = `${item.name} · v${version.version} · ${version.definition_count} definitions`; template.append(option);
      }
    }
    const section = document.createElement("section"); section.className = "panel";
    const heading = document.createElement("h2"); heading.textContent = "Your tables"; section.append(heading);
    const rows = sessions.sessions as Array<{ id: string; title: string | null; status: string; revision: number; role: string }> ?? [];
    if (rows.length === 0) { const empty = document.createElement("p"); empty.className = "muted"; empty.textContent = "No active tables yet."; section.append(empty); }
    for (const row of rows) {
      const item = document.createElement("div"); item.className = "session-row";
      const text = document.createElement("span"); text.textContent = `${row.title || "Untitled table"} · ${row.status} · revision ${row.revision} · ${row.role}`;
      item.append(text, button("Open", () => void openTable(row.id), true)); section.append(item);
    }
    workspace.append(section);
  } catch (error) {
    setStatus(`Setup unavailable: ${(error as Error).message}`, "error");
  }
}

function showJoinResult(session: { id: string; join_token: string }): void {
  if (!workspace) return;
  const panel = document.createElement("section"); panel.className = "panel result";
  panel.innerHTML = `<strong>Table created</strong><p>Share this token with authenticated players:</p><textarea readonly rows="3"></textarea>`;
  const area = panel.querySelector("textarea"); if (area) area.value = session.join_token;
  panel.append(button("Open table", () => void openTable(session.id))); workspace.append(panel);
  setStatus("Table is ready", "ok");
}

function effectiveCapability(participant: Participant, capability: Capability): boolean {
  if (typeof participant.capabilities?.[capability] === "boolean") return participant.capabilities[capability] as boolean;
  if (participant.role === "host") return true;
  if (participant.role === "spectator") return false;
  return ["deck.manage", "card.manage", "pile.manage", "lock.manage", "card.undo"].includes(capability);
}

function currentCan(capability: Capability): boolean {
  const participant = currentState?.participants.find((item) => item.is_current);
  return participant ? effectiveCapability(participant, capability) : false;
}

function renderPileControls(state: State, pile: Pile): HTMLElement {
  const controls = document.createElement("div"); controls.className = "pile-controls";
  const title = document.createElement("span"); title.textContent = `${pile.label || "Pile"} · ${pile.card_count} cards${pile.locked ? " · locked" : ""}`; controls.append(title);
  const canManage = !pile.locked && currentCan("pile.manage");
  if (canManage) {
    controls.append(button("Draw pile top", () => void action(state.session.id, "draw_pile_top", { pile_id: pile.id, expected_pile_version: pile.version }), true));
    controls.append(button("Draw pile bottom", () => void action(state.session.id, "draw_pile_bottom", { pile_id: pile.id, expected_pile_version: pile.version }), true));
    controls.append(button("Shuffle pile", () => void action(state.session.id, "shuffle_pile", { pile_id: pile.id, expected_pile_version: pile.version }), true));
  }
  if (!pile.locked && currentCan("lock.manage")) controls.append(button("Lock pile", () => void action(state.session.id, "lock_pile", { pile_id: pile.id, expected_pile_version: pile.version }), true));
  if (pile.locked && currentCan("lock.manage")) controls.append(button("Unlock pile", () => void action(state.session.id, "unlock_pile", { pile_id: pile.id, expected_pile_version: pile.version }), true));

  const advanced = document.createElement("details"); advanced.className = "pile-advanced";
  const summary = document.createElement("summary"); summary.textContent = "More pile actions"; advanced.append(summary);
  const actions = document.createElement("div"); actions.className = "pile-advanced-actions"; advanced.append(actions);
  if (canManage) {
    const labelInput = document.createElement("input"); labelInput.type = "text"; labelInput.maxLength = 160; labelInput.value = pile.label ?? "";
    actions.append(labelled("Pile label", labelInput));
    actions.append(button("Save pile label", () => void action(state.session.id, "label_pile", { pile_id: pile.id, label: labelInput.value, expected_pile_version: pile.version }), true));

    const splitCount = document.createElement("input"); splitCount.type = "number"; splitCount.min = "1"; splitCount.max = "100"; splitCount.value = "1"; splitCount.disabled = pile.card_count < 2;
    actions.append(labelled("Cards to split from top", splitCount));
    const splitLabel = document.createElement("input"); splitLabel.type = "text"; splitLabel.maxLength = 160; splitLabel.placeholder = "New pile label (optional)";
    actions.append(labelled("New pile label", splitLabel));
    const splitButton = button("Split top into new pile", () => {
      const count = Number(splitCount.value);
      if (!Number.isInteger(count) || count < 1 || count >= pile.card_count) return;
      void action(state.session.id, "split_pile", { pile_id: pile.id, count, label: splitLabel.value.trim() || null, x: pile.x + 24, y: pile.y + 24, rotation: pile.rotation, z_index: pile.z_index, expected_pile_version: pile.version });
    }, true);
    splitButton.disabled = pile.card_count < 2;
    splitCount.addEventListener("input", () => { const count = Number(splitCount.value); splitButton.disabled = pile.card_count < 2 || !Number.isInteger(count) || count < 1 || count >= pile.card_count; });
    actions.append(splitButton);

    if (currentCan("card.manage")) {
      const collect = button("Collect selected cards into this pile", () => applySelectedAction("collect_spread", { pile_id: pile.id, expected_pile_version: pile.version }), true);
      collect.disabled = selectedCardIds.size === 0;
      selectionActionButtons.push(collect);
      actions.append(collect);
    }

    const targets = state.containers.piles.filter((candidate) => candidate.id !== pile.id && !candidate.locked);
    if (targets.length > 0) {
      const target = document.createElement("select"); target.setAttribute("aria-label", "Merge into pile");
      for (const candidate of targets) {
        const option = document.createElement("option"); option.value = candidate.id; option.textContent = `${candidate.label || "Pile"} · ${candidate.card_count} cards`; target.append(option);
      }
      actions.append(labelled("Merge into", target));
      const mergeAt = (position: "top" | "bottom"): void => {
        const destination = targets.find((candidate) => candidate.id === target.value);
        if (!destination) return;
        void action(state.session.id, "merge_piles", { source_pile_id: pile.id, target_pile_id: destination.id, position, expected_source_version: pile.version, expected_target_version: destination.version });
      };
      actions.append(button("Merge at top", () => mergeAt("top"), true), button("Merge at bottom", () => mergeAt("bottom"), true));
    } else {
      const hint = document.createElement("p"); hint.className = "muted";
      hint.textContent = state.containers.piles.some((candidate) => candidate.id !== pile.id) ? "Unlock another pile to use it as a merge target." : "Create another pile to merge this one.";
      actions.append(hint);
    }
  } else {
    const hint = document.createElement("p"); hint.className = "muted"; hint.textContent = pile.locked ? "Unlock this pile before changing its contents." : "Pile actions are not available for your current permissions."; actions.append(hint);
  }
  controls.append(advanced);
  return controls;
}

function updateSelectionUi(): void {
  if (selectionCountLabel) selectionCountLabel.textContent = `${selectedCardIds.size} selected`;
  for (const item of selectionActionButtons) item.disabled = selectedCardIds.size === 0;
  workspace?.querySelectorAll<HTMLElement>(".table-surface .card[data-card-id]").forEach((item) => {
    const selected = selectedCardIds.has(item.dataset.cardId ?? "");
    item.classList.toggle("selected", selected);
    item.setAttribute("aria-pressed", String(selected));
  });
}

function selectionPayload(): { card_ids: string[]; expected_card_versions: Record<string, number> } {
  const cards = currentState?.cards.filter((card) => selectedCardIds.has(card.id) && card.location_type === "table") ?? [];
  return { card_ids: cards.map((card) => card.id), expected_card_versions: Object.fromEntries(cards.map((card) => [card.id, card.version])) };
}

function applySelectedAction(type: string, values: Record<string, unknown>): void {
  if (!currentState || selectedCardIds.size === 0) return;
  const payload = { ...selectionPayload(), ...values };
  const sessionId = currentState.session.id;
  selectedCardIds.clear(); selectionMode = false; updateSelectionUi();
  void action(sessionId, type, payload);
}

function renderCapabilityControls(state: State): HTMLElement | null {
  const current = state.participants.find((participant) => participant.is_current);
  if (!current || current.role !== "host" || !currentCan("participant.manage")) return null;
  const panel = document.createElement("section"); panel.className = "panel capability-panel";
  const heading = document.createElement("h3"); heading.textContent = "Participant capabilities"; panel.append(heading);
  const hint = document.createElement("p"); hint.className = "muted"; hint.textContent = "Explicit choices override the role defaults."; panel.append(hint);
  for (const participant of state.participants) {
    if (participant.is_current) continue;
    const row = document.createElement("fieldset"); row.className = "capability-row";
    const legend = document.createElement("legend"); legend.textContent = `${participant.is_current ? "You" : "Participant"} · ${participant.role}`; row.append(legend);
    for (const capability of CAPABILITIES) {
      const checkbox = document.createElement("input"); checkbox.type = "checkbox"; checkbox.checked = effectiveCapability(participant, capability.key);
      checkbox.addEventListener("change", () => {
        checkbox.disabled = true;
        const values = Object.fromEntries(CAPABILITIES.map((item) => [item.key, item.key === capability.key ? checkbox.checked : effectiveCapability(participant, item.key)]));
        void action(state.session.id, "set_participant_capabilities", { participant_id: participant.id, capabilities: values });
      });
      row.append(labelled(capability.label, checkbox));
    }
    panel.append(row);
  }
  return panel;
}

function renderTable(state: State): void {
  if (!workspace) return;
  selectedCardIds.clear(); selectionMode = false;
  selectionCountLabel = null; selectionActionButtons = [];
  currentState = state; workspace.replaceChildren();
  if (state.configuration.mat?.color) workspace.style.setProperty("--table-color", state.configuration.mat.color);
  const heading = document.createElement("h2"); heading.textContent = state.session.title || "Untitled table"; workspace.append(heading);
  const tableCardCount = state.cards.filter((card) => card.location_type === "table").length;
  const handCardCount = state.participants.reduce((sum, participant) => sum + participant.hand_count, 0);
  const totalCardCount = state.containers.decks.reduce((sum, deck) => sum + deck.card_count, 0) + state.containers.piles.reduce((sum, pile) => sum + pile.card_count, 0) + tableCardCount + handCardCount;
  const meta = document.createElement("p"); meta.className = "muted"; meta.textContent = `${state.session.status} · revision ${state.revision} · ${totalCardCount} cards`; workspace.append(meta);
  if (state.zones.length) { const zones = document.createElement("p"); zones.className = "muted"; zones.textContent = `Zones: ${state.zones.map((zone) => zone.name).join(", ")}`; workspace.append(zones); }
  const players = document.createElement("ul"); players.className = "players";
  for (const participant of state.participants) { const row = document.createElement("li"); row.textContent = `${participant.is_current ? "You" : "Player"} · ${participant.role} · ${participant.hand_count} in hand`; players.append(row); }
  workspace.append(players);
  const capabilityPanel = renderCapabilityControls(state); if (capabilityPanel) workspace.append(capabilityPanel);
  const controls = document.createElement("div"); controls.className = "actions";
  controls.append(button("Refresh", () => void refreshTable(state.session.id), true));
  if (currentCan("card.manage")) {
    const selectCards = button("Select cards", () => {
      selectionMode = !selectionMode;
      selectedCardIds.clear();
      selectCards.textContent = selectionMode ? "Finish selection" : "Select cards";
      selectCards.setAttribute("aria-pressed", String(selectionMode));
      surfaceSelectionMode(selectionMode);
      updateSelectionUi();
    }, true);
    selectCards.setAttribute("aria-pressed", "false");
    controls.append(selectCards);
    const selectionTools = document.createElement("div"); selectionTools.className = "selection-tools";
    selectionCountLabel = document.createElement("span"); selectionCountLabel.textContent = "0 selected"; selectionCountLabel.setAttribute("aria-live", "polite"); selectionTools.append(selectionCountLabel);
    const groupAction = (label: string, type: string, values: Record<string, unknown>): void => {
      const item = button(label, () => applySelectedAction(type, values), true); item.disabled = true; selectionActionButtons.push(item); selectionTools.append(item);
    };
    groupAction("Rotate selection 15°", "rotate_cards", { rotation_delta: 15 });
    groupAction("Turn selected face up", "set_cards_face", { face_state: "up" });
    groupAction("Turn selected face down", "set_cards_face", { face_state: "down" });
    groupAction("Bring selection to front", "reorder_cards", { direction: "front" });
    groupAction("Send selection to back", "reorder_cards", { direction: "back" });
    controls.append(selectionTools);
  }
  const deck = state.containers.decks[0];
  if (deck?.id && currentCan("deck.manage")) {
    controls.append(button("Draw top", () => void action(state.session.id, "draw_top", { deck_id: deck.id })));
    controls.append(button("Shuffle", () => void action(state.session.id, "shuffle_deck", { deck_id: deck.id }), true));
    controls.append(button("Cut", () => void action(state.session.id, "cut_deck", { deck_id: deck.id }), true));
    const recipients = state.participants.filter((participant) => participant.role === "host" || participant.role === "player").map((participant) => participant.id);
    if (recipients.length > 1) controls.append(button("Deal one each", () => void action(state.session.id, "deal", { deck_id: deck.id, participant_ids: recipients, count: 1, mode: "per_participant" })));
    if (currentCan("session.manage")) {
      const collectMode = document.createElement("select"); collectMode.name = "collect-mode"; collectMode.innerHTML = '<option value="original">Collect original</option><option value="shuffle">Collect and shuffle</option><option value="preserve">Collect preserve</option>';
      controls.append(labelled("Collect mode", collectMode));
      controls.append(button("Collect all", () => void action(state.session.id, "collect_all", { mode: collectMode.value }), true));
      controls.append(button("Reset table", () => { if (window.confirm("Reset the table and collect every card?")) void action(state.session.id, "reset_session", { shuffle: true }); }, true));
    }
  }
  if (currentCan("pile.manage")) controls.append(button("Create pile", () => void action(state.session.id, "create_pile", { label: "New pile", x: 24, y: 24 }), true));
  for (const pile of state.containers.piles) controls.append(renderPileControls(state, pile));
  workspace.append(controls);
  renderBoard(state);
  const back = document.createElement("a"); back.href = "/"; back.textContent = "Back to tables"; workspace.append(back);
  connectRealtime(state.session.id);
  setStatus("Connected", "ok");
}

function renderBoard(state: State): void {
  if (!workspace) return;
  const board = document.createElement("section");
  board.className = "table-view";
  board.setAttribute("aria-label", "Table cards");
  const heading = document.createElement("h3"); heading.textContent = "Table"; board.append(heading);
  const surface = document.createElement("div"); surface.className = "table-surface";
  const cards = state.cards.filter((card) => card.location_type === "table");
  if (cards.length === 0 && state.containers.piles.length === 0) {
    const empty = document.createElement("p"); empty.className = "table-empty"; empty.textContent = "Draw or play a card to place it here."; surface.append(empty);
  }
  const canManageCards = currentCan("card.manage");
  const canManagePiles = currentCan("pile.manage");
  state.containers.piles.forEach((pile) => surface.append(renderPile(pile, canManagePiles)));
  cards.forEach((card, index) => surface.append(renderCard(card, index, false, canManageCards)));
  board.append(surface);

  const currentParticipant = state.participants.find((participant) => participant.is_current);
  const handCards = currentParticipant ? state.cards.filter((card) => card.location_type === "hand" && card.hand_participant_id === currentParticipant.id) : [];
  const hand = document.createElement("div"); hand.className = "hand-tray";
  const handHeading = document.createElement("h3"); handHeading.textContent = `Your hand (${handCards.length})`; hand.append(handHeading);
  const handRow = document.createElement("div"); handRow.className = "hand-cards";
  if (handCards.length === 0) {
    const empty = document.createElement("p"); empty.className = "table-empty"; empty.textContent = "Your hand is empty."; handRow.append(empty);
  }
  handCards.forEach((card, index) => {
    const item = renderCard(card, index, true, canManageCards);
    if (canManageCards) {
      const playDown = button("Play face down", () => void action(state.session.id, "play_from_hand", { card_id: card.id, face_state: "down", x: 24 + index * 28, y: 24, expected_card_version: card.version }), true);
      const playUp = button("Play face up", () => void action(state.session.id, "play_from_hand", { card_id: card.id, face_state: "up", x: 24 + index * 28, y: 24, expected_card_version: card.version }), true);
      item.append(playDown, playUp);
    }
    handRow.append(item);
  });
  hand.append(handRow); board.append(hand);
  workspace.append(board);
}

function renderPile(pile: Pile, interactive: boolean): HTMLElement {
  const item = document.createElement("article"); item.className = `table-pile${pile.locked ? " locked" : ""}`;
  item.setAttribute("role", "group");
  item.setAttribute("aria-label", `${pile.label || "Pile"}, ${pile.card_count} cards${pile.locked ? ", locked" : ""}`);
  item.style.left = `${Math.max(0, pile.x)}px`; item.style.top = `${Math.max(0, pile.y)}px`; item.style.zIndex = String(pile.z_index);
  const stack = document.createElement("div"); stack.className = "table-pile-stack"; stack.style.transform = `rotate(${pile.rotation || 0}deg)`;
  const label = document.createElement("strong"); label.textContent = pile.label || "Pile";
  const count = document.createElement("span"); count.textContent = `${pile.card_count} cards`;
  stack.append(label, count); item.append(stack);
  if (interactive && !pile.locked) {
    const controls = document.createElement("div"); controls.className = "table-pile-controls"; controls.setAttribute("role", "group"); controls.setAttribute("aria-label", `Move or rotate ${pile.label || "pile"}`);
    const moveBy = (dx: number, dy: number): void => void action(statefulSessionId(), "move_pile", { pile_id: pile.id, x: Math.max(0, pile.x + dx), y: Math.max(0, pile.y + dy), rotation: pile.rotation, z_index: pile.z_index, expected_pile_version: pile.version });
    controls.append(
      button("Move pile left", () => moveBy(-20, 0), true),
      button("Move pile right", () => moveBy(20, 0), true),
      button("Move pile up", () => moveBy(0, -20), true),
      button("Move pile down", () => moveBy(0, 20), true),
      button("Rotate pile 15°", () => void action(statefulSessionId(), "rotate_pile", { pile_id: pile.id, x: pile.x, y: pile.y, rotation: pile.rotation + 15, z_index: pile.z_index, expected_pile_version: pile.version }), true),
    );
    item.append(controls);
    item.title = "Drag to move, or use the labeled move and rotate buttons.";
    let drag: { pointerX: number; pointerY: number; startX: number; startY: number; moved: boolean } | null = null;
    item.addEventListener("pointerdown", (event) => {
      if (selectionMode || (event.target instanceof Element && event.target.closest("button"))) return;
      item.setPointerCapture(event.pointerId);
      drag = { pointerX: event.clientX, pointerY: event.clientY, startX: pile.x, startY: pile.y, moved: false };
      item.classList.add("dragging");
    });
    item.addEventListener("pointermove", (event) => {
      if (!drag) return;
      const nextX = Math.max(0, drag.startX + event.clientX - drag.pointerX);
      const nextY = Math.max(0, drag.startY + event.clientY - drag.pointerY);
      if (Math.abs(nextX - drag.startX) + Math.abs(nextY - drag.startY) > 4) drag.moved = true;
      item.style.left = `${nextX}px`; item.style.top = `${nextY}px`;
    });
    item.addEventListener("pointerup", (event) => {
      if (!drag) return;
      const nextX = Math.max(0, drag.startX + event.clientX - drag.pointerX);
      const nextY = Math.max(0, drag.startY + event.clientY - drag.pointerY);
      const moved = drag.moved; drag = null; item.classList.remove("dragging");
      if (moved) void action(statefulSessionId(), "move_pile", { pile_id: pile.id, x: nextX, y: nextY, rotation: pile.rotation, z_index: pile.z_index, expected_pile_version: pile.version });
      else { item.style.left = `${Math.max(0, pile.x)}px`; item.style.top = `${Math.max(0, pile.y)}px`; }
    });
    item.addEventListener("pointercancel", () => { drag = null; item.classList.remove("dragging"); item.style.left = `${Math.max(0, pile.x)}px`; item.style.top = `${Math.max(0, pile.y)}px`; });
  }
  return item;
}

function surfaceSelectionMode(enabled: boolean): void {
  workspace?.querySelector(".table-surface")?.classList.toggle("selection-mode", enabled);
}

function renderCard(card: Card, index: number, inHand = false, interactive = true): HTMLElement {
  const item = document.createElement("article");
  item.className = `card ${card.face_state === "up" || inHand ? "face-up" : "face-down"}`;
  if (interactive) { item.tabIndex = 0; item.setAttribute("role", "button"); item.setAttribute("aria-pressed", "false"); }
  item.setAttribute("aria-label", card.card_label ? `${card.card_label} card` : (inHand ? "Private card in your hand" : "Face-down card"));
  item.dataset.cardId = card.id;
  item.style.zIndex = String(card.z_index || index + 1);
  const x = card.x ?? 24 + (index % 8) * 74;
  const y = card.y ?? 24 + Math.floor(index / 8) * 28;
  if (!inHand && interactive) {
    item.style.left = `${x}px`; item.style.top = `${y}px`; item.style.transform = `rotate(${card.rotation || 0}deg)`;
    item.title = "Drag to move. Double click to turn the card.";
    let drag: { pointerX: number; pointerY: number; startX: number; startY: number; moved: boolean } | null = null;
    item.addEventListener("pointerdown", (event) => {
      if (selectionMode) return;
      item.setPointerCapture(event.pointerId);
      drag = { pointerX: event.clientX, pointerY: event.clientY, startX: x, startY: y, moved: false };
      item.classList.add("dragging");
    });
    item.addEventListener("pointermove", (event) => {
      if (!drag) return;
      const nextX = drag.startX + event.clientX - drag.pointerX;
      const nextY = drag.startY + event.clientY - drag.pointerY;
      if (Math.abs(nextX - drag.startX) + Math.abs(nextY - drag.startY) > 4) drag.moved = true;
      item.style.left = `${Math.max(0, nextX)}px`; item.style.top = `${Math.max(0, nextY)}px`;
    });
    item.addEventListener("pointerup", (event) => {
      if (!drag) return;
      const nextX = Math.max(0, drag.startX + event.clientX - drag.pointerX);
      const nextY = Math.max(0, drag.startY + event.clientY - drag.pointerY);
      const moved = drag.moved; drag = null; item.classList.remove("dragging");
      if (moved) void action(statefulSessionId(), "move_card", { card_id: card.id, x: nextX, y: nextY, rotation: card.rotation, z_index: card.z_index, expected_card_version: card.version });
    });
    item.addEventListener("dblclick", () => { if (!selectionMode) void action(statefulSessionId(), "flip_card", { card_id: card.id, expected_card_version: card.version }); });
    item.addEventListener("click", (event) => {
      if (!selectionMode) return;
      event.preventDefault();
      if (selectedCardIds.has(card.id)) selectedCardIds.delete(card.id); else selectedCardIds.add(card.id);
      updateSelectionUi();
    });
    item.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        if (selectionMode) {
          if (selectedCardIds.has(card.id)) selectedCardIds.delete(card.id); else selectedCardIds.add(card.id);
          updateSelectionUi();
        } else void action(statefulSessionId(), "flip_card", { card_id: card.id, expected_card_version: card.version });
      }
    });
  }
  const label = document.createElement("strong");
  label.textContent = card.card_label || (card.face_state === "private" ? "Private card" : "Face down");
  item.append(label);
  return item;
}

function statefulSessionId(): string {
  if (!currentState) throw new Error("No table is open.");
  return currentState.session.id;
}

async function action(sessionId: string, type: string, payload: Record<string, unknown>): Promise<void> {
  try { await api(`/api/sessions/${sessionId}/actions`, { method: "POST", body: JSON.stringify({ action_id: crypto.randomUUID(), type, payload, expected_session_revision: currentState?.revision ?? null }) }); await refreshTable(sessionId); }
  catch (error) { setStatus((error as Error).message, "error"); await refreshTable(sessionId); }
}

async function refreshTable(sessionId: string): Promise<void> { const result = await api(`/api/sessions/${sessionId}/state`); renderTable(result.state as State); }

function connectRealtime(sessionId: string): void {
  void api(`/api/sessions/${sessionId}/realtime-ticket`, { method: "POST" }).then((result) => {
    realtimeRetry = 0;
    socket?.close(); const protocol = location.protocol === "https:" ? "wss:" : "ws:";
    socket = new WebSocket(`${protocol}//${location.host}/ws?ticket=${encodeURIComponent(result.ticket as string)}`);
    socket.addEventListener("message", (event) => { const message = JSON.parse(event.data as string) as { type?: string }; if (message.type === "session_changed") void refreshTable(sessionId); });
    socket.addEventListener("close", () => { setStatus("Realtime connection closed; retrying", "error"); const delay = Math.min(30000, 1000 * 2 ** realtimeRetry++); window.setTimeout(() => connectRealtime(sessionId), delay); });
  }).catch((error: unknown) => { setStatus(`Realtime unavailable: ${(error as Error).message}`, "error"); const delay = Math.min(30000, 1000 * 2 ** realtimeRetry++); window.setTimeout(() => connectRealtime(sessionId), delay); });
}

async function openTable(sessionId: string): Promise<void> { try { await refreshTable(sessionId); } catch (error) { setStatus((error as Error).message, "error"); } }

async function start(): Promise<void> {
  try {
    csrf = (await api("/api/csrf")).csrf_token as string;
    const result = await api("/api/me");
    const sessionId = new URLSearchParams(location.search).get("session");
    if (sessionId) await openTable(sessionId); else renderHome(result.user as User);
  } catch (error) {
    if ((error as Error).message === "authentication_required") {
      if (workspace) workspace.innerHTML = '<p><a href="/login">Sign in</a> to create or join a table.</p>';
      setStatus("Sign in required", "pending");
    } else setStatus((error as Error).message, "error");
  }
}

void start();
