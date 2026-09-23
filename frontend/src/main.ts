import "./styles.css";

type User = { id: string; email: string; role: string };
type Session = { id: string; title: string | null; status: string; revision: number; host_user_id: string };
type Preset = { id: string; name: string; template_version_id: string; mat_version_id: string | null; configuration: Record<string, unknown> };
type TemplateVersion = { id: string; version: number; definition_count: number };
type Template = { id: string; name: string; versions: TemplateVersion[] };
type Deck = { id: string; label: string | null; card_count: number; version: number };
type Pile = { id: string; card_count: number; label: string | null; x: number; y: number; rotation: number; z_index: number; locked: boolean; version: number };
type Card = { id: string; location_type: string; deck_id: string | null; pile_id: string | null; hand_participant_id: string | null; hand_order?: number; card_definition_id?: string; card_label?: string; face_state: string; x: number | null; y: number | null; rotation: number; z_index: number; version: number };
type ActionEvent = { revision: number; action_type: string; actor: "you" | "participant"; created_at: string };
type Capability = "session.manage" | "participant.manage" | "zone.manage" | "deck.manage" | "card.manage" | "pile.manage" | "lock.manage" | "card.undo";
type Participant = { id: string; role: string; is_current: boolean; hand_count: number; capabilities?: Partial<Record<Capability, boolean>> };
type RemovedParticipant = { id: string; role: string };
type Zone = { id: string; name: string; geometry: { x: number; y: number; width: number; height: number }; priority: number; behavior: { effect?: string; degrees?: number } };
type State = { revision: number; session: Session; configuration: { mat?: { label?: string; color?: string }; preset_id?: string | null }; participants: Participant[]; containers: { decks: Deck[]; piles: Pile[] }; zones: Zone[]; cards: Card[]; removed_cards?: Array<{ id: string; version: number }>; removed_participants?: RemovedParticipant[] };
const CAPABILITIES: Array<{ key: Capability; label: string }> = [
  { key: "session.manage", label: "Session administration" }, { key: "participant.manage", label: "Participant administration" },
  { key: "zone.manage", label: "Zone administration" }, { key: "deck.manage", label: "Deck actions" },
  { key: "card.manage", label: "Card actions" }, { key: "pile.manage", label: "Pile actions" },
  { key: "lock.manage", label: "Lock actions" }, { key: "card.undo", label: "Undo" },
];
const ACTION_DESCRIPTIONS: Record<string, string> = {
  start_session: "started the session", end_session: "ended the session", leave_session: "left the session",
  transfer_host: "transferred the host role", remove_participant: "removed a participant", restore_participant: "restored a participant",
  set_participant_capabilities: "changed participant permissions", instantiate_deck: "added a deck", configure_table: "configured the table",
  draw_top: "drew a card from a deck", draw_bottom: "drew a card from a deck", draw_n: "drew cards from a deck", deal: "dealt cards",
  return_top: "returned cards to a deck", return_bottom: "returned cards to a deck", return_to_source_decks: "returned cards to their decks", shuffle_deck: "shuffled a deck", cut_deck: "cut a deck",
  insert_cards: "inserted cards into a deck", split_deck: "split a deck", move_card: "moved a card", move_cards: "moved cards",
  rotate_card: "rotated a card", rotate_cards: "rotated cards", flip_card: "flipped a card", turn_face_up: "turned a card face up",
  turn_face_down: "turned a card face down", set_cards_face: "changed card faces", reorder_cards: "changed card order",
  move_to_hand: "moved a card to a hand", play_from_hand: "played a card from a hand", reorder_hand: "reordered a hand",
  give_cards: "transferred cards between hands", peek_card: "peeked at a card", remove_card: "removed a card from play",
  restore_card: "restored a card to a deck", lock_card: "locked a card", unlock_card: "unlocked a card", create_pile: "created a pile",
  move_to_pile: "moved cards to a pile", draw_pile_top: "drew from a pile", draw_pile_bottom: "drew from a pile",
  split_pile: "split a pile", merge_piles: "merged piles", collect_spread: "collected cards into a pile", move_pile: "moved a pile",
  rotate_pile: "rotated a pile", label_pile: "changed a pile label", lock_pile: "locked a pile", unlock_pile: "unlocked a pile",
  shuffle_pile: "shuffled a pile", reverse_pile: "reversed a pile", flip_pile: "flipped a pile", spread_pile: "spread a pile",
  merge_pile_top: "returned a pile to a deck", merge_pile_bottom: "returned a pile to a deck", merge_pile_shuffle: "shuffled a pile into a deck", collect_all: "collected all cards",
  reset_session: "reset the table", create_zone: "created a zone", delete_zone: "removed a zone", undo_action: "undid a spatial action",
};

const workspace = document.querySelector<HTMLElement>("#workspace");
const status = document.querySelector<HTMLElement>("#connection-status");
let csrf = "";
let currentState: State | null = null;
let socket: WebSocket | null = null;
let realtimeRetry = 0;
let realtimeSessionId: string | null = null;
let realtimeGeneration = 0;
let realtimeRetryTimer: number | null = null;
let selectionMode = false;
const selectedCardIds = new Set<string>();
let selectionCountLabel: HTMLElement | null = null;
let selectionActionButtons: HTMLButtonElement[] = [];
let alignmentActionButtons: HTMLButtonElement[] = [];
let singleSelectionActionButtons: HTMLButtonElement[] = [];
const selectedHandCardIds = new Set<string>();
let handSelectionCountLabel: HTMLElement | null = null;
let handSelectionButtons: HTMLButtonElement[] = [];

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
  const welcome = workspace.closest(".welcome");
  welcome?.classList.remove("table-active"); welcome?.setAttribute("aria-labelledby", "welcome-title");
  currentState = null; disconnectRealtime();
  workspace.replaceChildren();
  const greeting = document.createElement("p"); greeting.textContent = `Signed in as ${user.email}`; workspace.append(greeting);
  const create = document.createElement("section"); create.className = "panel";
  const createHeading = document.createElement("h2"); createHeading.textContent = "Create a table"; create.append(createHeading);
  const title = document.createElement("input"); title.placeholder = "Table name (optional)"; title.autocomplete = "off";
  const maxParticipants = document.createElement("input"); maxParticipants.type = "number"; maxParticipants.min = "1"; maxParticipants.max = "100"; maxParticipants.value = "12";
  const preset = document.createElement("select"); preset.name = "preset"; preset.innerHTML = '<option value="">No preset</option>';
  const deckTemplateVersions: Array<{ id: string; label: string }> = [];
  const deckChoices = document.createElement("div"); deckChoices.className = "deck-selections";
  const fillDeckSelector = (select: HTMLSelectElement): void => {
    select.replaceChildren();
    const empty = document.createElement("option"); empty.value = ""; empty.textContent = "No deck selected"; select.append(empty);
    for (const version of deckTemplateVersions) {
      const option = document.createElement("option"); option.value = version.id; option.textContent = version.label; select.append(option);
    }
  };
  const relabelDeckChoices = (): void => {
    Array.from(deckChoices.querySelectorAll<HTMLElement>(".deck-selection")).forEach((row, index) => {
      const labelText = row.querySelector("label span");
      if (labelText) labelText.textContent = `Deck ${index + 1} template version`;
      const select = row.querySelector("select");
      select?.setAttribute("aria-label", `Deck ${index + 1} template version`);
      const remove = row.querySelector("button");
      if (remove) remove.textContent = `Remove deck ${index + 1}`;
    });
  };
  const addDeckChoice = (): void => {
    const row = document.createElement("div"); row.className = "deck-selection";
    const select = document.createElement("select"); select.name = "template"; fillDeckSelector(select);
    const label = document.createElement("label");
    const labelText = document.createElement("span"); label.append(labelText, select);
    row.append(label, button("Remove deck", () => { row.remove(); relabelDeckChoices(); }, true));
    deckChoices.append(row); relabelDeckChoices();
  };
  const deckFieldset = document.createElement("fieldset");
  const deckLegend = document.createElement("legend"); deckLegend.textContent = "Decks";
  deckFieldset.append(deckLegend, deckChoices, button("Add another deck", addDeckChoice, true));
  addDeckChoice();
  let createdSession: { id: string; join_token: string } | null = null;
  const createButton = button("Create table", async () => {
    createButton.disabled = true;
    createdSession = null;
    try {
      const result = await api("/api/sessions", { method: "POST", body: JSON.stringify({ title: title.value.trim() || null, max_participants: Number(maxParticipants.value) || 12 }) });
      createdSession = result.session as { id: string; join_token: string };
      let revision = Number(result.session.revision ?? 0);
      const selectedPreset = preset.value ? (preset.selectedOptions[0]?.dataset.templateVersion ? { id: preset.value, template_version_id: preset.selectedOptions[0].dataset.templateVersion } : null) : null;
      const selectedTemplateVersions = Array.from(deckChoices.querySelectorAll<HTMLSelectElement>('select[name="template"]')).map((select) => select.value).filter((versionId) => versionId !== "");
      if (selectedPreset) {
        const configured = await sessionAction(result.session.id, revision, "configure_table", { preset_id: selectedPreset.id }); revision = Number(configured.revision);
        const instantiated = await sessionAction(result.session.id, revision, "instantiate_deck", { template_version_id: selectedPreset.template_version_id, label: "Preset deck" }); revision = Number(instantiated.revision);
      }
      for (const [index, templateVersionId] of selectedTemplateVersions.entries()) {
        const instantiated = await sessionAction(result.session.id, revision, "instantiate_deck", { template_version_id: templateVersionId, label: `Deck ${index + 1}` });
        revision = Number(instantiated.revision);
      }
      showJoinResult(result.session);
    }
    catch (error) {
      if (createdSession) {
        showJoinResult(createdSession);
        setStatus(`Table created, but deck setup stopped partway: ${(error as Error).message}. Some selected decks may be missing.`, "error");
      } else setStatus((error as Error).message, "error");
    }
    finally { createButton.disabled = false; }
  });
  create.append(labelled("Table name", title), labelled("Maximum participants", maxParticipants), labelled("Preset", preset), deckFieldset, createButton); workspace.append(create);
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
  void loadHomeData(preset, deckChoices, deckTemplateVersions, fillDeckSelector);
  setStatus("Ready", "ok");
}

async function sessionAction(sessionId: string, revision: number, type: string, payload: Record<string, unknown>): Promise<any> {
  return api(`/api/sessions/${sessionId}/actions`, { method: "POST", body: JSON.stringify({ action_id: crypto.randomUUID(), type, payload, expected_session_revision: revision }) });
}

async function loadHomeData(preset: HTMLSelectElement, deckChoices: HTMLElement, deckTemplateVersions: Array<{ id: string; label: string }>, fillDeckSelector: (select: HTMLSelectElement) => void): Promise<void> {
  try {
    const [sessions, presets, templates] = await Promise.all([api("/api/sessions"), api("/api/mats-and-presets"), api("/api/templates")]);
    if (currentState !== null || !workspace) return;
    for (const item of (presets.presets as Preset[] ?? [])) {
      const option = document.createElement("option"); option.value = item.id; option.textContent = item.name; option.dataset.templateVersion = item.template_version_id; preset.append(option);
    }
    for (const item of (templates.templates as Template[] ?? [])) {
      for (const version of item.versions ?? []) {
        if (version.definition_count < 1) continue;
        deckTemplateVersions.push({ id: version.id, label: `${item.name} · v${version.version} · ${version.definition_count} definitions` });
      }
    }
    deckChoices.querySelectorAll<HTMLSelectElement>('select[name="template"]').forEach(fillDeckSelector);
    const section = document.createElement("section"); section.className = "panel";
    const heading = document.createElement("h2"); heading.textContent = "Your tables"; section.append(heading);
    const rows = sessions.sessions as Array<{ id: string; title: string | null; status: string; revision: number; role: string }> ?? [];
    const search = document.createElement("input"); search.type = "search"; search.autocomplete = "off"; search.placeholder = "Search title, status, or role"; search.setAttribute("aria-label", "Search your tables");
    section.append(labelled("Find a table", search));
    const list = document.createElement("div"); list.className = "table-list"; section.append(list);
    const renderRows = (): void => {
      list.replaceChildren();
      if (rows.length === 0) { const empty = document.createElement("p"); empty.className = "muted"; empty.textContent = "No active tables yet."; list.append(empty); return; }
      const query = search.value.trim().toLocaleLowerCase();
      const matching = rows.filter((row) => `${row.title || "Untitled table"} ${row.status} ${row.role}`.toLocaleLowerCase().includes(query));
      if (matching.length === 0) { const empty = document.createElement("p"); empty.className = "muted"; empty.setAttribute("role", "status"); empty.textContent = "No tables match that search."; list.append(empty); return; }
      for (const row of matching) {
        const item = document.createElement("div"); item.className = "session-row";
        const text = document.createElement("span"); text.textContent = `${row.title || "Untitled table"} · ${row.status} · revision ${row.revision} · ${row.role}`;
        const open = button("Open", () => void openTable(row.id), true); open.setAttribute("aria-label", `Open ${row.title || "untitled table"}`);
        item.append(text, open); list.append(item);
      }
    };
    search.addEventListener("input", renderRows); renderRows();
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
  const canChangePileCards = canManage && currentCan("card.manage");
  if (canManage) {
    controls.append(button("Shuffle pile", () => void action(state.session.id, "shuffle_pile", { pile_id: pile.id, expected_pile_version: pile.version }), true));
  }
  if (canChangePileCards) {
    controls.append(button("Draw pile top", () => void action(state.session.id, "draw_pile_top", { pile_id: pile.id, expected_pile_version: pile.version }), true));
    controls.append(button("Draw pile bottom", () => void action(state.session.id, "draw_pile_bottom", { pile_id: pile.id, expected_pile_version: pile.version }), true));
  }
  if (!pile.locked && currentCan("lock.manage") && currentCan("pile.manage")) controls.append(button("Lock pile", () => void action(state.session.id, "lock_pile", { pile_id: pile.id, expected_pile_version: pile.version }), true));
  if (pile.locked && currentCan("lock.manage") && currentCan("pile.manage")) controls.append(button("Unlock pile", () => void action(state.session.id, "unlock_pile", { pile_id: pile.id, expected_pile_version: pile.version }), true));

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

    const reverse = button("Reverse pile order", () => void action(state.session.id, "reverse_pile", { pile_id: pile.id, expected_pile_version: pile.version }), true);
    const flip = button("Flip pile (reverse and turn cards)", () => void action(state.session.id, "flip_pile", { pile_id: pile.id, expected_pile_version: pile.version }), true);
    reverse.disabled = flip.disabled = pile.card_count === 0;
    actions.append(reverse, flip);

    if (canChangePileCards) {
      const spread = (axis: "x" | "y"): void => void action(state.session.id, "spread_pile", { pile_id: pile.id, axis, expected_pile_version: pile.version });
      const horizontal = button("Spread pile horizontally", () => spread("x"), true);
      const vertical = button("Spread pile vertically", () => spread("y"), true);
      horizontal.disabled = vertical.disabled = pile.card_count === 0;
      actions.append(horizontal, vertical);
    } else {
      const hint = document.createElement("p"); hint.className = "muted"; hint.textContent = "Spreading also requires card-management permission."; actions.append(hint);
    }

    if (canChangePileCards) {
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
    if (currentCan("deck.manage")) {
      if (state.containers.decks.length > 0) {
        const deckTarget = document.createElement("select"); deckTarget.setAttribute("aria-label", `Deck destination for ${pile.label || "pile"}`);
        for (const deck of state.containers.decks) {
          const option = document.createElement("option"); option.value = deck.id; option.textContent = `${deck.label || "Deck"} · ${deck.card_count} cards`; deckTarget.append(option);
        }
        actions.append(labelled("Merge pile into deck", deckTarget));
        const mergeIntoDeck = (position: "top" | "bottom" | "shuffle"): void => {
          const deck = state.containers.decks.find((candidate) => candidate.id === deckTarget.value);
          if (!deck || pile.card_count === 0) return;
          const type = position === "shuffle" ? "merge_pile_shuffle" : `merge_pile_${position}`;
          void action(state.session.id, type, { pile_id: pile.id, deck_id: deck.id, expected_pile_version: pile.version, expected_deck_version: deck.version });
        };
        const toTop = button("Merge pile onto deck top", () => mergeIntoDeck("top"), true);
        const toBottom = button("Merge pile onto deck bottom", () => mergeIntoDeck("bottom"), true);
        const shuffleIn = button("Shuffle pile into deck", () => mergeIntoDeck("shuffle"), true);
        toTop.disabled = toBottom.disabled = shuffleIn.disabled = pile.card_count === 0;
        actions.append(toTop, toBottom, shuffleIn);
      } else {
        const hint = document.createElement("p"); hint.className = "muted"; hint.textContent = "Add a deck before returning this pile to a deck."; actions.append(hint);
      }
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
  for (const item of alignmentActionButtons) item.disabled = selectedCardIds.size < 2;
  for (const item of singleSelectionActionButtons) item.disabled = selectedCardIds.size !== 1;
  workspace?.querySelectorAll<HTMLElement>(".table-surface .card[data-card-id]").forEach((item) => {
    const selected = selectedCardIds.has(item.dataset.cardId ?? "");
    item.classList.toggle("selected", selected);
    item.setAttribute("aria-pressed", String(selected));
  });
}

function updateHandSelectionUi(): void {
  if (handSelectionCountLabel) handSelectionCountLabel.textContent = `${selectedHandCardIds.size} selected in your hand`;
  for (const item of handSelectionButtons) item.disabled = selectedHandCardIds.size === 0;
  workspace?.querySelectorAll<HTMLElement>(".hand-card-slot[data-card-id]").forEach((item) => {
    const selected = selectedHandCardIds.has(item.dataset.cardId ?? "");
    item.classList.toggle("selected", selected);
    const checkbox = item.querySelector<HTMLInputElement>('input[type="checkbox"]');
    if (checkbox) checkbox.checked = selected;
  });
}

function selectionPayload(): { card_ids: string[]; expected_card_versions: Record<string, number> } {
  const cards: Card[] = [];
  for (const cardId of selectedCardIds) {
    const card = currentState?.cards.find((item) => item.id === cardId && item.location_type === "table");
    if (card) cards.push(card);
  }
  return { card_ids: cards.map((card) => card.id), expected_card_versions: Object.fromEntries(cards.map((card) => [card.id, card.version])) };
}

function applySelectedAction(type: string, values: Record<string, unknown>): void {
  if (!currentState || selectedCardIds.size === 0) return;
  const payload = { ...selectionPayload(), ...values };
  const sessionId = currentState.session.id;
  selectedCardIds.clear(); selectionMode = false; updateSelectionUi();
  void action(sessionId, type, payload);
}

function removeSelectedCard(): void {
  if (!currentState || currentState.session.status === "ended") return;
  const cards = selectedTableCards();
  if (cards.length !== 1 || selectedCardIds.size !== 1) return;
  if (!window.confirm("Remove the selected card from play? The host can restore it later.")) return;
  const card = cards[0]!;
  const sessionId = currentState.session.id;
  selectedCardIds.clear(); selectionMode = false; updateSelectionUi();
  void action(sessionId, "remove_card", { card_id: card.id, expected_card_version: card.version });
}

function selectedTableCards(): Card[] {
  if (!currentState) return [];
  return Array.from(selectedCardIds, (cardId) => currentState?.cards.find((card) => card.id === cardId && card.location_type === "table"))
    .filter((card): card is Card => card !== undefined);
}

function submitSelectedPositions(cards: Card[], positions: Array<{ x: number; y: number }>): void {
  if (!currentState || cards.length === 0 || cards.length !== selectedCardIds.size || positions.length !== cards.length) return;
  const sessionId = currentState.session.id;
  const items = cards.map((card, index) => {
    const position = positions[index]!;
    return {
      card_id: card.id,
      expected_card_version: card.version,
      x: position.x,
      y: position.y,
      rotation: card.rotation,
      z_index: card.z_index,
    };
  });
  selectedCardIds.clear(); selectionMode = false; updateSelectionUi();
  void action(sessionId, "move_cards", { cards: items });
}

function moveSelectedCards(deltaX: number, deltaY: number): void {
  const cards = selectedTableCards();
  if (!currentState || cards.length === 0 || cards.length !== selectedCardIds.size) return;
  const positions = cards.map((card) => ({ x: card.x ?? 24, y: card.y ?? 24 }));
  const safeDeltaX = Math.max(deltaX, -Math.min(...positions.map((position) => position.x)));
  const safeDeltaY = Math.max(deltaY, -Math.min(...positions.map((position) => position.y)));
  submitSelectedPositions(cards, positions.map((position) => ({ x: position.x + safeDeltaX, y: position.y + safeDeltaY })));
}

function alignSelectedCards(axis: "x" | "y"): void {
  const cards = selectedTableCards();
  if (!currentState || cards.length < 2 || cards.length !== selectedCardIds.size) return;
  const positions = cards.map((card) => ({ x: card.x ?? 24, y: card.y ?? 24 }));
  const aligned = Math.min(...positions.map((position) => position[axis]));
  submitSelectedPositions(cards, positions.map((position) => axis === "x" ? { ...position, x: aligned } : { ...position, y: aligned }));
}

function renderParticipantControls(state: State): HTMLElement | null {
  const current = state.participants.find((participant) => participant.is_current);
  if (!current || current.role !== "host" || state.session.status === "ended" || !currentCan("participant.manage")) return null;
  const panel = document.createElement("section"); panel.className = "panel capability-panel";
  const heading = document.createElement("h3"); heading.textContent = "Participant administration"; panel.append(heading);
  const hint = document.createElement("p"); hint.className = "muted"; hint.textContent = "Participant labels are generic. Explicit permission choices override role defaults."; panel.append(hint);
  const active = state.participants.filter((participant) => !participant.is_current);
  for (const [index, participant] of active.entries()) {
    const row = document.createElement("fieldset"); row.className = "capability-row";
    const legend = document.createElement("legend"); legend.textContent = `Participant ${index + 1} · ${participant.role}`; row.append(legend);
    for (const capability of CAPABILITIES) {
      const checkbox = document.createElement("input"); checkbox.type = "checkbox"; checkbox.checked = effectiveCapability(participant, capability.key);
      checkbox.addEventListener("change", () => {
        checkbox.disabled = true;
        const values = Object.fromEntries(CAPABILITIES.map((item) => [item.key, item.key === capability.key ? checkbox.checked : effectiveCapability(participant, item.key)]));
        void action(state.session.id, "set_participant_capabilities", { participant_id: participant.id, capabilities: values });
      });
      row.append(labelled(capability.label, checkbox));
    }
    if (participant.role !== "host") {
      row.append(button("Transfer host role", () => {
        if (window.confirm(`Transfer the host role to Participant ${index + 1}? You will become a player.`)) void action(state.session.id, "transfer_host", { participant_id: participant.id });
      }, true));
      row.append(button("Remove participant", () => {
        if (window.confirm(`Remove Participant ${index + 1} from this table? They will lose access until restored.`)) void action(state.session.id, "remove_participant", { participant_id: participant.id });
      }, true));
    }
    panel.append(row);
  }
  if (active.length === 0) { const empty = document.createElement("p"); empty.className = "muted"; empty.textContent = "No other active participants."; panel.append(empty); }

  const removed = state.removed_participants ?? [];
  if (removed.length > 0) {
    const subheading = document.createElement("h4"); subheading.textContent = `Removed participants (${removed.length})`; panel.append(subheading);
    const restoreRole = document.createElement("select"); restoreRole.setAttribute("aria-label", "Role for restored participants");
    for (const [value, text] of [["player", "Player"], ["spectator", "Spectator"]] as const) {
      const option = document.createElement("option"); option.value = value; option.textContent = text; restoreRole.append(option);
    }
    panel.append(labelled("Restore as", restoreRole));
    const list = document.createElement("ul"); list.className = "removed-card-list";
    for (const [index, participant] of removed.entries()) {
      const row = document.createElement("li"); row.append(document.createTextNode(`Removed participant ${index + 1} · formerly ${participant.role}`));
      row.append(button("Restore participant", () => void action(state.session.id, "restore_participant", { participant_id: participant.id, role: restoreRole.value }), true));
      list.append(row);
    }
    panel.append(list);
  }
  return panel;
}

function renderRemovedCardControls(state: State): HTMLElement | null {
  const current = state.participants.find((participant) => participant.is_current);
  const removedCards = state.removed_cards ?? [];
  if (state.session.status === "ended" || current?.role !== "host" || !currentCan("card.manage") || removedCards.length === 0) return null;
  const panel = document.createElement("section"); panel.className = "panel removed-card-controls";
  const heading = document.createElement("h3"); heading.textContent = `Removed cards (${removedCards.length})`; panel.append(heading);
  const hint = document.createElement("p"); hint.className = "muted"; hint.textContent = "Cards are unnamed here to protect hidden information. Restoration returns each card to its source deck."; panel.append(hint);
  const position = document.createElement("select"); position.setAttribute("aria-label", "Restore position");
  const positions: Array<[string, string]> = [["top", "Top of source deck"], ["bottom", "Bottom of source deck"], ["shuffle", "Shuffle into source deck"]];
  for (const [value, text] of positions) {
    const option = document.createElement("option"); option.value = value; option.textContent = text; position.append(option);
  }
  panel.append(labelled("Restore position", position));
  const list = document.createElement("ul"); list.className = "removed-card-list";
  for (const removed of removedCards) {
    const row = document.createElement("li"); row.append(document.createTextNode("Removed card"));
    row.append(button("Restore", () => void action(state.session.id, "restore_card", { card_id: removed.id, expected_card_version: removed.version, position: position.value }), true));
    list.append(row);
  }
  panel.append(list);
  return panel;
}

function renderZoneEditor(state: State): HTMLElement | null {
  const current = state.participants.find((participant) => participant.is_current);
  if (!current || current.role !== "host" || state.session.status !== "lobby" || !currentCan("zone.manage")) return null;
  const panel = document.createElement("section"); panel.className = "panel zone-editor";
  const heading = document.createElement("h3"); heading.textContent = "Table zones"; panel.append(heading);
  const hint = document.createElement("p"); hint.className = "muted"; hint.textContent = "Zones affect future card placements. Starting the session freezes zone settings."; panel.append(hint);
  const effects = ["none", "face_up", "face_down", "stack", "align", "fan", "owner_private"];
  const makeFields = (zone?: Zone) => {
    const fields = document.createElement("div"); fields.className = "zone-fields";
    const name = document.createElement("input"); name.type = "text"; name.maxLength = 160; name.value = zone?.name ?? "";
    const x = document.createElement("input"); x.type = "number"; x.min = "-1000000"; x.max = "1000000"; x.step = "any"; x.value = String(zone?.geometry.x ?? 80);
    const y = document.createElement("input"); y.type = "number"; y.min = "-1000000"; y.max = "1000000"; y.step = "any"; y.value = String(zone?.geometry.y ?? 80);
    const width = document.createElement("input"); width.type = "number"; width.min = "0.01"; width.max = "1000000"; width.step = "any"; width.value = String(zone?.geometry.width ?? 220);
    const height = document.createElement("input"); height.type = "number"; height.min = "0.01"; height.max = "1000000"; height.step = "any"; height.value = String(zone?.geometry.height ?? 120);
    const priority = document.createElement("input"); priority.type = "number"; priority.min = "-1000000"; priority.max = "1000000"; priority.step = "1"; priority.value = String(zone?.priority ?? 0);
    const effect = document.createElement("select");
    for (const value of effects) { const option = document.createElement("option"); option.value = value; option.textContent = value.replaceAll("_", " "); effect.append(option); }
    effect.value = effects.includes(zone?.behavior.effect ?? "none") ? zone?.behavior.effect ?? "none" : "none";
    const degrees = document.createElement("input"); degrees.type = "number"; degrees.min = "1"; degrees.max = "180"; degrees.step = "1"; degrees.value = String(zone?.behavior.degrees ?? 45);
    const degreeLabel = labelled("Fan degrees", degrees); degreeLabel.hidden = effect.value !== "fan";
    effect.addEventListener("change", () => { degreeLabel.hidden = effect.value !== "fan"; });
    fields.append(labelled("Zone name", name), labelled("X", x), labelled("Y", y), labelled("Width", width), labelled("Height", height), labelled("Priority", priority), labelled("Drop effect", effect), degreeLabel);
    return { fields, name, x, y, width, height, priority, effect, degrees };
  };
  const payload = (fields: ReturnType<typeof makeFields>): Record<string, unknown> | null => {
    const geometry = { x: fields.x.valueAsNumber, y: fields.y.valueAsNumber, width: fields.width.valueAsNumber, height: fields.height.valueAsNumber };
    const priority = fields.priority.valueAsNumber;
    if (!fields.name.value.trim() || !Object.values(geometry).every(Number.isFinite) || geometry.width <= 0 || geometry.height <= 0 || !Number.isInteger(priority) || priority < -1000000 || priority > 1000000) {
      setStatus("Enter a name, positive rectangle size, and valid zone coordinates and priority.", "error"); return null;
    }
    if (Object.values(geometry).some((value) => Math.abs(value) > 1000000)) { setStatus("Zone coordinates and dimensions must be within 1,000,000.", "error"); return null; }
    const behavior: Record<string, unknown> = { effect: fields.effect.value };
    if (fields.effect.value === "fan") {
      const degrees = fields.degrees.valueAsNumber;
      if (!Number.isInteger(degrees) || degrees < 1 || degrees > 180) { setStatus("Fan degrees must be between 1 and 180.", "error"); return null; }
      behavior.degrees = degrees;
    }
    return { name: fields.name.value.trim(), geometry, priority, behavior };
  };

  const createHeading = document.createElement("h4"); createHeading.textContent = "Add a zone"; panel.append(createHeading);
  const newFields = makeFields(); panel.append(newFields.fields);
  panel.append(button("Add zone", () => { const values = payload(newFields); if (values) void action(state.session.id, "create_zone", values); }));
  if (state.zones.length > 0) {
    const listHeading = document.createElement("h4"); listHeading.textContent = "Edit existing zones"; panel.append(listHeading);
    for (const zone of state.zones) {
      const row = document.createElement("fieldset"); row.className = "zone-row";
      const legend = document.createElement("legend"); legend.textContent = zone.name; row.append(legend);
      const fields = makeFields(zone); row.append(fields.fields);
      row.append(button("Save zone", () => { const values = payload(fields); if (values) void action(state.session.id, "update_zone", { zone_id: zone.id, ...values }); }, true));
      row.append(button("Delete zone", () => { if (window.confirm(`Delete the “${zone.name}” zone?`)) void action(state.session.id, "delete_zone", { zone_id: zone.id }); }, true));
      panel.append(row);
    }
  }
  return panel;
}

function renderDeckControls(state: State, deck: Deck): HTMLElement {
  const panel = document.createElement("section"); panel.className = "panel deck-controls";
  const heading = document.createElement("h3"); heading.textContent = `${deck.label || "Deck"} · ${deck.card_count} cards`; panel.append(heading);
  if (!currentCan("deck.manage")) return panel;
  const count = document.createElement("input"); count.type = "number"; count.min = "1"; count.max = "100"; count.value = "2";
  panel.append(labelled("Cards to draw", count));
  const target = document.createElement("select"); target.setAttribute("aria-label", `Draw destination for ${deck.label || "deck"}`);
  const destinations: Array<[string, string]> = [["table", "Public table"], ["hand", "Your hand"]];
  for (const [value, text] of destinations) {
    const option = document.createElement("option"); option.value = value; option.textContent = text; target.append(option);
  }
  if (currentCan("pile.manage")) {
    for (const pile of state.containers.piles.filter((item) => !item.locked)) {
      const option = document.createElement("option"); option.value = `pile:${pile.id}`; option.textContent = `Pile: ${pile.label || "Pile"} · ${pile.card_count} cards`; target.append(option);
    }
  }
  panel.append(labelled("Draw destination", target));
  const draw = (direction: "top" | "bottom", requestedCount: number): void => {
    if (!Number.isInteger(requestedCount) || requestedCount < 1 || requestedCount > 100) return;
    const targetValue = target.value;
    const payload: Record<string, unknown> = { deck_id: deck.id, count: requestedCount, direction, target: targetValue.startsWith("pile:") ? "pile" : targetValue };
    if (targetValue.startsWith("pile:")) {
      const pile = state.containers.piles.find((item) => item.id === targetValue.slice(5));
      if (!pile || pile.locked) { setStatus("That pile is no longer available. Refresh the table and try again.", "error"); return; }
      payload.pile_id = pile.id; payload.expected_pile_version = pile.version;
    }
    const type = requestedCount === 1 ? (direction === "top" ? "draw_top" : "draw_bottom") : "draw_n";
    void action(state.session.id, type, payload);
  };
  const drawTop = button("Draw top", () => draw("top", 1));
  const drawBottom = button("Draw bottom", () => draw("bottom", 1), true);
  const drawNTop = button("Draw N from top", () => draw("top", Number(count.value)), true);
  const drawNBottom = button("Draw N from bottom", () => draw("bottom", Number(count.value)), true);
  const shuffle = button("Shuffle deck", () => void action(state.session.id, "shuffle_deck", { deck_id: deck.id }), true);
  const cut = button("Cut deck", () => void action(state.session.id, "cut_deck", { deck_id: deck.id }), true);
  const updateCount = (): void => {
    const valid = Number.isInteger(Number(count.value)) && Number(count.value) >= 1 && Number(count.value) <= 100;
    drawNTop.disabled = !valid; drawNBottom.disabled = !valid;
  };
  count.addEventListener("input", updateCount); updateCount();
  panel.append(drawTop, drawBottom, drawNTop, drawNBottom, shuffle, cut);
  return panel;
}

function renderTable(state: State): void {
  if (!workspace) return;
  const welcome = workspace.closest(".welcome");
  welcome?.classList.add("table-active"); welcome?.setAttribute("aria-labelledby", "table-title");
  selectedCardIds.clear(); selectionMode = false;
  selectionCountLabel = null; selectionActionButtons = []; alignmentActionButtons = []; singleSelectionActionButtons = [];
  selectedHandCardIds.clear(); handSelectionCountLabel = null; handSelectionButtons = [];
  currentState = state; workspace.replaceChildren();
  if (state.configuration.mat?.color) workspace.style.setProperty("--table-color", state.configuration.mat.color);
  const heading = document.createElement("h2"); heading.id = "table-title"; heading.textContent = state.session.title || "Untitled table"; workspace.append(heading);
  const tableCardCount = state.cards.filter((card) => card.location_type === "table").length;
  const handCardCount = state.participants.reduce((sum, participant) => sum + participant.hand_count, 0);
  const totalCardCount = state.containers.decks.reduce((sum, deck) => sum + deck.card_count, 0) + state.containers.piles.reduce((sum, pile) => sum + pile.card_count, 0) + tableCardCount + handCardCount;
  const meta = document.createElement("p"); meta.className = "muted"; meta.textContent = `${state.session.status} · revision ${state.revision} · ${totalCardCount} cards`; workspace.append(meta);
  const currentParticipant = state.participants.find((participant) => participant.is_current);
  if (state.zones.length) { const zones = document.createElement("p"); zones.className = "muted"; zones.textContent = `Zones: ${state.zones.map((zone) => zone.name).join(", ")}`; workspace.append(zones); }
  const players = document.createElement("ul"); players.className = "players";
  for (const participant of state.participants) { const row = document.createElement("li"); row.textContent = `${participant.is_current ? "You" : "Player"} · ${participant.role} · ${participant.hand_count} in hand`; players.append(row); }
  workspace.append(players);
  const capabilityPanel = renderParticipantControls(state); if (capabilityPanel) workspace.append(capabilityPanel);
  const removedCardPanel = renderRemovedCardControls(state); if (removedCardPanel) workspace.append(removedCardPanel);
  const zoneEditor = renderZoneEditor(state); if (zoneEditor) workspace.append(zoneEditor);
  const controls = document.createElement("div"); controls.className = "actions";
  controls.append(button("Refresh", () => void refreshTable(state.session.id), true));
  if (currentParticipant?.role === "host" && currentCan("session.manage")) {
    if (state.session.status === "lobby") controls.append(button("Start session", () => void action(state.session.id, "start_session", {})));
    if (state.session.status === "active") controls.append(button("End session", () => {
      if (window.confirm("End this session for everyone? Participants will no longer be able to change the table.")) void action(state.session.id, "end_session", {});
    }, true));
  }
  if (currentCan("card.manage") && state.session.status !== "ended") {
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
    const moveAction = (label: string, deltaX: number, deltaY: number): void => {
      const item = button(label, () => moveSelectedCards(deltaX, deltaY), true); item.disabled = true; selectionActionButtons.push(item); selectionTools.append(item);
    };
    const alignAction = (label: string, axis: "x" | "y"): void => {
      const item = button(label, () => alignSelectedCards(axis), true); item.disabled = true; alignmentActionButtons.push(item); selectionTools.append(item);
    };
    const removeSelected = button("Remove selected card from play", removeSelectedCard, true); removeSelected.disabled = true; singleSelectionActionButtons.push(removeSelected); selectionTools.append(removeSelected);
    moveAction("Move selection 24 px left", -24, 0);
    moveAction("Move selection 24 px right", 24, 0);
    moveAction("Move selection 24 px up", 0, -24);
    moveAction("Move selection 24 px down", 0, 24);
    alignAction("Align selected left edges", "x");
    alignAction("Align selected top edges", "y");
    groupAction("Rotate selection 15°", "rotate_cards", { rotation_delta: 15 });
    groupAction("Turn selected face up", "set_cards_face", { face_state: "up" });
    groupAction("Turn selected face down", "set_cards_face", { face_state: "down" });
    groupAction("Bring selection to front", "reorder_cards", { direction: "front" });
    groupAction("Send selection to back", "reorder_cards", { direction: "back" });
    if (currentCan("deck.manage")) {
      groupAction("Return selection to source decks top", "return_to_source_decks", { position: "top" });
      groupAction("Return selection to source decks bottom", "return_to_source_decks", { position: "bottom" });
    }
    controls.append(selectionTools);
  }
  if (state.containers.decks.length > 0 && currentCan("deck.manage")) {
    const recipients = state.participants.filter((participant) => participant.role === "host" || participant.role === "player").map((participant) => participant.id);
    for (const deck of state.containers.decks) {
      if (recipients.length > 1) controls.append(button(`Deal one each from ${deck.label || "deck"}`, () => void action(state.session.id, "deal", { deck_id: deck.id, participant_ids: recipients, count: 1, mode: "per_participant" })));
    }
  }
  if (currentParticipant?.role === "host" && currentCan("session.manage")) {
    const collectMode = document.createElement("select"); collectMode.name = "collect-mode"; collectMode.innerHTML = '<option value="original">Collect original</option><option value="shuffle">Collect and shuffle</option><option value="preserve">Collect preserve</option>';
    controls.append(labelled("Collect mode", collectMode));
    controls.append(button("Collect all", () => void action(state.session.id, "collect_all", { mode: collectMode.value }), true));
    controls.append(button("Reset table", () => { if (window.confirm("Reset the table to the lobby and collect every card?")) void action(state.session.id, "reset_session", { shuffle: true }); }, true));
  }
  if (currentCan("pile.manage")) controls.append(button("Create pile", () => void action(state.session.id, "create_pile", { label: "New pile", x: 24, y: 24 }), true));
  for (const pile of state.containers.piles) controls.append(renderPileControls(state, pile));
  workspace.append(controls);
  for (const deck of state.containers.decks) workspace.append(renderDeckControls(state, deck));
  workspace.append(renderRecentActions(state));
  renderBoard(state);
  const back = document.createElement("a"); back.href = "/"; back.textContent = "Back to tables"; workspace.append(back);
}

function renderZoneOverlay(zone: Zone): HTMLElement {
  const overlay = document.createElement("div"); overlay.className = "table-zone";
  overlay.setAttribute("role", "img"); overlay.setAttribute("aria-label", `${zone.name} zone, ${zone.behavior.effect ?? "none"} effect`);
  overlay.style.left = `${zone.geometry.x}px`; overlay.style.top = `${zone.geometry.y}px`;
  overlay.style.width = `${zone.geometry.width}px`; overlay.style.height = `${zone.geometry.height}px`;
  overlay.style.zIndex = "0";
  const label = document.createElement("span"); label.textContent = `${zone.name} · ${zone.behavior.effect ?? "none"}`; overlay.append(label);
  return overlay;
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
  state.zones.forEach((zone) => surface.append(renderZoneOverlay(zone)));
  const canManageCards = currentCan("card.manage");
  const canManagePiles = currentCan("pile.manage");
  state.containers.piles.forEach((pile) => surface.append(renderPile(state, pile, canManagePiles)));
  cards.forEach((card, index) => surface.append(renderCard(card, index, false, canManageCards)));
  board.append(surface);

  const currentParticipant = state.participants.find((participant) => participant.is_current);
  const handCards = currentParticipant ? state.cards.filter((card) => card.location_type === "hand" && card.hand_participant_id === currentParticipant.id).sort((left, right) => (left.hand_order ?? 0) - (right.hand_order ?? 0) || left.id.localeCompare(right.id)) : [];
  const hand = document.createElement("div"); hand.className = "hand-tray";
  const handHeading = document.createElement("h3"); handHeading.textContent = `Your hand (${handCards.length})`; hand.append(handHeading);
  const handRow = document.createElement("div"); handRow.className = "hand-cards";
  const recipients = state.participants.filter((participant) => !participant.is_current && (participant.role === "host" || participant.role === "player"));
  let playerNumber = 0;
  const recipientLabels = new Map(recipients.map((participant) => [participant.id, participant.role === "host" ? "Host" : `Player ${++playerNumber}`]));
  if (handCards.length === 0) {
    const empty = document.createElement("p"); empty.className = "table-empty"; empty.textContent = "Your hand is empty."; handRow.append(empty);
  }
  handCards.forEach((card, index) => {
    const item = renderCard(card, index, true, canManageCards);
    const slot = document.createElement("div"); slot.className = "hand-card-slot"; slot.dataset.cardId = card.id;
    slot.append(item);
    if (canManageCards) {
      const tools = document.createElement("div"); tools.className = "hand-card-tools";
      const select = document.createElement("input"); select.type = "checkbox";
      const selectLabel = document.createElement("label"); selectLabel.append(select, document.createTextNode(`Select hand card ${index + 1}`));
      select.setAttribute("aria-label", `Select hand card ${index + 1}${card.card_label ? `, ${card.card_label}` : ""}`);
      select.checked = selectedHandCardIds.has(card.id);
      select.addEventListener("change", () => {
        if (select.checked) selectedHandCardIds.add(card.id); else selectedHandCardIds.delete(card.id);
        updateHandSelectionUi();
      });
      tools.append(selectLabel);
      const reorder = (toIndex: number): void => {
        if (toIndex < 0 || toIndex >= handCards.length) return;
        const next = [...handCards];
        const selected = next[index]; const adjacent = next[toIndex];
        if (!selected || !adjacent) return;
        next[index] = adjacent; next[toIndex] = selected;
        void action(state.session.id, "reorder_hand", { card_ids: next.map((handCard) => handCard.id) });
      };
      const earlier = button(`Move hand card ${index + 1} earlier`, () => reorder(index - 1), true); earlier.disabled = index === 0;
      const later = button(`Move hand card ${index + 1} later`, () => reorder(index + 1), true); later.disabled = index === handCards.length - 1;
      tools.append(earlier, later);
      const playDown = button("Play face down", () => void action(state.session.id, "play_from_hand", { card_id: card.id, face_state: "down", x: 24 + index * 28, y: 24, expected_card_version: card.version }), true);
      const playUp = button("Play face up", () => void action(state.session.id, "play_from_hand", { card_id: card.id, face_state: "up", x: 24 + index * 28, y: 24, expected_card_version: card.version }), true);
      item.append(playDown, playUp);
    }
    handRow.append(slot);
  });
  hand.append(handRow);
  if (canManageCards) {
    const handActions = document.createElement("div"); handActions.className = "hand-selection-tools";
    handSelectionCountLabel = document.createElement("span"); handSelectionCountLabel.textContent = "0 selected in your hand"; handSelectionCountLabel.setAttribute("aria-live", "polite"); handActions.append(handSelectionCountLabel);
    const selectedHandCards = (): Card[] => handCards.filter((card) => selectedHandCardIds.has(card.id));
    const versionPayload = (): { card_ids: string[]; expected_card_versions: Record<string, number> } => {
      const cards = selectedHandCards();
      return { card_ids: cards.map((card) => card.id), expected_card_versions: Object.fromEntries(cards.map((card) => [card.id, card.version])) };
    };
    if (recipients.length > 0) {
      const recipient = document.createElement("select"); recipient.setAttribute("aria-label", "Give selected cards to");
      for (const participant of recipients) {
        const option = document.createElement("option"); option.value = participant.id; option.textContent = `${recipientLabels.get(participant.id)} · ${participant.hand_count} in hand`; recipient.append(option);
      }
      handActions.append(labelled("Give selected cards to", recipient));
      const give = button("Give selected cards", () => {
        const selected = versionPayload();
        if (selected.card_ids.length === 0) return;
        selectedHandCardIds.clear(); updateHandSelectionUi();
        void action(state.session.id, "give_cards", { recipient_participant_id: recipient.value, ...selected });
      }, true);
      give.disabled = selectedHandCardIds.size === 0; handSelectionButtons.push(give); handActions.append(give);
    }
    if (currentCan("deck.manage")) {
      for (const [label, position] of [["Return selected hand cards to source decks top", "top"], ["Return selected hand cards to source decks bottom", "bottom"]] as const) {
        const returnCards = button(label, () => {
          const selected = versionPayload();
          if (selected.card_ids.length === 0) return;
          selectedHandCardIds.clear(); updateHandSelectionUi();
          void action(state.session.id, "return_to_source_decks", { position, ...selected });
        }, true);
        returnCards.disabled = selectedHandCardIds.size === 0; handSelectionButtons.push(returnCards); handActions.append(returnCards);
      }
    }
    if (currentCan("pile.manage")) {
      const unlockedPiles = state.containers.piles.filter((pile) => !pile.locked);
      if (unlockedPiles.length > 0) {
        const target = document.createElement("select"); target.setAttribute("aria-label", "Destination pile for selected hand cards");
        for (const pile of unlockedPiles) {
          const option = document.createElement("option"); option.value = pile.id; option.textContent = `${pile.label || "Pile"} · ${pile.card_count} cards`; target.append(option);
        }
        handActions.append(labelled("Move selected hand cards into pile", target));
        const moveToPile = button("Move selected hand cards into pile", () => {
          const selected = versionPayload();
          if (selected.card_ids.length === 0) return;
          const pile = unlockedPiles.find((candidate) => candidate.id === target.value);
          if (!pile) return;
          selectedHandCardIds.clear(); updateHandSelectionUi();
          void action(state.session.id, "move_to_pile", { pile_id: pile.id, expected_pile_version: pile.version, ...selected });
        }, true);
        moveToPile.disabled = selectedHandCardIds.size === 0;
        handSelectionButtons.push(moveToPile); handActions.append(moveToPile);
      } else {
        const hint = document.createElement("p"); hint.className = "muted"; hint.textContent = "Create or unlock a pile before moving hand cards into it."; handActions.append(hint);
      }
    }
    hand.append(handActions);
  }
  board.append(hand);
  workspace.append(board);
}

function renderRecentActions(state: State): HTMLElement {
  const section = document.createElement("section"); section.className = "panel action-history";
  const heading = document.createElement("h3"); heading.textContent = "Recent actions"; section.append(heading);
  const statusMessage = document.createElement("p"); statusMessage.className = "muted"; statusMessage.setAttribute("aria-live", "polite"); statusMessage.textContent = "Loading recent actions…"; section.append(statusMessage);
  void loadRecentActions(state.session.id, state.revision, section);
  return section;
}

async function loadRecentActions(sessionId: string, revision: number, section: HTMLElement): Promise<void> {
  try {
    const after = Math.max(0, revision - 50);
    const result = await api(`/api/sessions/${sessionId}/changes?after=${after}`);
    if (!section.isConnected || currentState?.session.id !== sessionId) return;
    const events = (result.changes?.events as ActionEvent[] ?? []).slice(-20).reverse();
    section.replaceChildren();
    const heading = document.createElement("h3"); heading.textContent = "Recent actions"; section.append(heading);
    if (events.length === 0) {
      const empty = document.createElement("p"); empty.className = "muted"; empty.textContent = "No actions recorded yet."; section.append(empty); return;
    }
    const list = document.createElement("ol");
    for (const event of events) {
      const item = document.createElement("li");
      const actor = event.actor === "you" ? "You" : "A participant";
      const description = ACTION_DESCRIPTIONS[event.action_type] ?? "recorded a table action";
      const date = new Date(event.created_at);
      const time = Number.isNaN(date.getTime()) ? "" : ` · ${date.toLocaleString()}`;
      item.textContent = `${actor} ${description} · revision ${event.revision}${time}`;
      list.append(item);
    }
    section.append(list);
  } catch {
    if (!section.isConnected || currentState?.session.id !== sessionId) return;
    section.replaceChildren();
    const heading = document.createElement("h3"); heading.textContent = "Recent actions"; section.append(heading);
    const unavailable = document.createElement("p"); unavailable.className = "muted"; unavailable.textContent = "Recent actions are unavailable right now."; section.append(unavailable);
  }
}

function renderPile(state: State, pile: Pile, interactive: boolean): HTMLElement {
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
    const moveLayer = (direction: "front" | "back"): void => {
      const otherLayers = [
        ...state.containers.piles.filter((candidate) => candidate.id !== pile.id).map((candidate) => candidate.z_index),
        ...state.cards.filter((card) => card.location_type === "table").map((card) => card.z_index),
      ];
      const currentEdge = otherLayers.reduce((edge, z) => direction === "front" ? Math.max(edge, z) : Math.min(edge, z), pile.z_index);
      const zIndex = currentEdge + (direction === "front" ? 1 : -1);
      if (zIndex < -1000000 || zIndex > 1000000) { setStatus("The pile cannot move farther in that layer direction.", "error"); return; }
      void action(statefulSessionId(), "move_pile", { pile_id: pile.id, x: pile.x, y: pile.y, rotation: pile.rotation, z_index: zIndex, expected_pile_version: pile.version });
    };
    controls.append(
      button("Move pile left", () => moveBy(-20, 0), true),
      button("Move pile right", () => moveBy(20, 0), true),
      button("Move pile up", () => moveBy(0, -20), true),
      button("Move pile down", () => moveBy(0, 20), true),
      button("Rotate pile 15°", () => void action(statefulSessionId(), "rotate_pile", { pile_id: pile.id, x: pile.x, y: pile.y, rotation: pile.rotation + 15, z_index: pile.z_index, expected_pile_version: pile.version }), true),
      button("Bring pile to front", () => moveLayer("front"), true),
      button("Send pile to back", () => moveLayer("back"), true),
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
  if (interactive && !inHand) { item.tabIndex = 0; item.setAttribute("role", "button"); item.setAttribute("aria-pressed", "false"); }
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

function disconnectRealtime(): void {
  realtimeGeneration++;
  realtimeSessionId = null;
  if (realtimeRetryTimer !== null) { window.clearTimeout(realtimeRetryTimer); realtimeRetryTimer = null; }
  const activeSocket = socket;
  socket = null;
  activeSocket?.close();
}

function scheduleRealtimeReconnect(sessionId: string, generation: number): void {
  if (generation !== realtimeGeneration || realtimeSessionId !== sessionId || realtimeRetryTimer !== null) return;
  const delay = Math.min(30000, 1000 * 2 ** realtimeRetry++);
  realtimeRetryTimer = window.setTimeout(() => {
    realtimeRetryTimer = null;
    if (generation === realtimeGeneration && realtimeSessionId === sessionId && socket === null) connectRealtime(sessionId);
  }, delay);
}

function connectRealtime(sessionId: string): void {
  if (realtimeSessionId !== null && realtimeSessionId !== sessionId) disconnectRealtime();
  if (realtimeSessionId === sessionId && socket !== null && (socket.readyState === WebSocket.CONNECTING || socket.readyState === WebSocket.OPEN)) return;
  if (realtimeRetryTimer !== null) { window.clearTimeout(realtimeRetryTimer); realtimeRetryTimer = null; }
  realtimeSessionId = sessionId;
  const generation = ++realtimeGeneration;
  setStatus("Connecting to table…", "pending");
  void api(`/api/sessions/${sessionId}/realtime-ticket`, { method: "POST" }).then((result) => {
    if (generation !== realtimeGeneration || realtimeSessionId !== sessionId) return;
    const protocol = location.protocol === "https:" ? "wss:" : "ws:";
    const activeSocket = new WebSocket(`${protocol}//${location.host}/ws?ticket=${encodeURIComponent(result.ticket as string)}`);
    socket = activeSocket;
    activeSocket.addEventListener("open", () => {
      if (generation !== realtimeGeneration || socket !== activeSocket) return;
      realtimeRetry = 0;
      setStatus("Connected", "ok");
    });
    activeSocket.addEventListener("message", (event) => {
      if (generation !== realtimeGeneration || socket !== activeSocket) return;
      const message = JSON.parse(event.data as string) as { type?: string };
      if (message.type === "session_changed") void refreshTable(sessionId).catch((error: unknown) => setStatus(`Table refresh failed: ${(error as Error).message}`, "error"));
    });
    activeSocket.addEventListener("close", () => {
      if (generation !== realtimeGeneration || socket !== activeSocket) return;
      socket = null;
      setStatus("Realtime connection closed; retrying", "error");
      scheduleRealtimeReconnect(sessionId, generation);
    });
  }).catch((error: unknown) => {
    if (generation !== realtimeGeneration || realtimeSessionId !== sessionId) return;
    setStatus(`Realtime unavailable: ${(error as Error).message}`, "error");
    scheduleRealtimeReconnect(sessionId, generation);
  });
}

async function openTable(sessionId: string): Promise<void> {
  if (realtimeSessionId !== null && realtimeSessionId !== sessionId) disconnectRealtime();
  try { await refreshTable(sessionId); connectRealtime(sessionId); }
  catch (error) { setStatus((error as Error).message, "error"); }
}

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
