import "./styles.css";

type User = { id: string; email: string; role: string };
type Session = { id: string; title: string | null; status: string; revision: number; host_user_id: string };
type Card = { id: string; location_type: string; deck_id: string | null; pile_id: string | null; hand_participant_id: string | null; card_definition_id?: string; face_state: string; x: number | null; y: number | null; rotation: number; z_index: number; version: number };
type State = { revision: number; session: Session; participants: Array<{ id: string; role: string; is_current: boolean; hand_count: number }>; containers: { decks: Array<{ id: string; card_count: number }>; piles: Array<{ id: string; card_count: number }> }; zones: Array<{ id: string; name: string; geometry: { x: number; y: number; width: number; height: number }; priority: number; behavior: Record<string, unknown> }>; cards: Card[] };

const workspace = document.querySelector<HTMLElement>("#workspace");
const status = document.querySelector<HTMLElement>("#connection-status");
let csrf = "";
let currentState: State | null = null;
let socket: WebSocket | null = null;
let realtimeRetry = 0;

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

function renderHome(user: User): void {
  if (!workspace) return;
  workspace.replaceChildren();
  const greeting = document.createElement("p"); greeting.textContent = `Signed in as ${user.email}`; workspace.append(greeting);
  const create = document.createElement("section"); create.className = "panel";
  const title = document.createElement("input"); title.placeholder = "Table name (optional)";
  const createButton = button("Create table", async () => {
    try { const result = await api("/api/sessions", { method: "POST", body: JSON.stringify({ title: title.value || null }) }); showJoinResult(result.session); }
    catch (error) { setStatus((error as Error).message, "error"); }
  });
  create.append(title, createButton); workspace.append(create);
  const join = document.createElement("section"); join.className = "panel";
  const token = document.createElement("input"); token.placeholder = "Paste table join token"; token.autocomplete = "off";
  const joinButton = button("Join table", async () => {
    try {
      const parts = token.value.split("."); if (parts.length !== 2 || !parts[0]) throw new Error("Paste the complete table token.");
      const payload = JSON.parse(atob(parts[0].replaceAll("-", "+").replaceAll("_", "/") + "=="));
      await api(`/api/sessions/${payload.session_id}/join`, { method: "POST", body: JSON.stringify({ token: token.value, role: "player" }) });
      await openTable(payload.session_id);
    } catch (error) { setStatus((error as Error).message, "error"); }
  });
  join.append(token, joinButton); workspace.append(join);
  const invite = document.createElement("a"); invite.href = "/account/invite"; invite.textContent = "Invite someone to Decks"; workspace.append(invite);
  setStatus("Ready", "ok");
}

function showJoinResult(session: { id: string; join_token: string }): void {
  if (!workspace) return;
  const panel = document.createElement("section"); panel.className = "panel result";
  panel.innerHTML = `<strong>Table created</strong><p>Share this token with authenticated players:</p><textarea readonly rows="3"></textarea>`;
  const area = panel.querySelector("textarea"); if (area) area.value = session.join_token;
  panel.append(button("Open table", () => void openTable(session.id))); workspace.append(panel);
  setStatus("Table is ready", "ok");
}

function renderTable(state: State): void {
  if (!workspace) return;
  currentState = state; workspace.replaceChildren();
  const heading = document.createElement("h2"); heading.textContent = state.session.title || "Untitled table"; workspace.append(heading);
  const tableCardCount = state.cards.filter((card) => card.location_type === "table").length;
  const handCardCount = state.participants.reduce((sum, participant) => sum + participant.hand_count, 0);
  const totalCardCount = state.containers.decks.reduce((sum, deck) => sum + deck.card_count, 0) + state.containers.piles.reduce((sum, pile) => sum + pile.card_count, 0) + tableCardCount + handCardCount;
  const meta = document.createElement("p"); meta.className = "muted"; meta.textContent = `${state.session.status} · revision ${state.revision} · ${totalCardCount} cards`; workspace.append(meta);
  if (state.zones.length) { const zones = document.createElement("p"); zones.className = "muted"; zones.textContent = `Zones: ${state.zones.map((zone) => zone.name).join(", ")}`; workspace.append(zones); }
  const players = document.createElement("ul"); players.className = "players";
  for (const participant of state.participants) { const row = document.createElement("li"); row.textContent = `${participant.is_current ? "You" : "Player"} · ${participant.role} · ${participant.hand_count} in hand`; players.append(row); }
  workspace.append(players);
  const controls = document.createElement("div"); controls.className = "actions";
  controls.append(button("Refresh", () => void refreshTable(state.session.id), true));
  const deck = state.containers.decks[0];
  if (deck?.id) {
    controls.append(button("Draw top", () => void action(state.session.id, "draw_top", { deck_id: deck.id })));
    controls.append(button("Shuffle", () => void action(state.session.id, "shuffle_deck", { deck_id: deck.id }), true));
    controls.append(button("Cut", () => void action(state.session.id, "cut_deck", { deck_id: deck.id }), true));
    const recipients = state.participants.filter((participant) => participant.role === "host" || participant.role === "player").map((participant) => participant.id);
    if (recipients.length > 1) controls.append(button("Deal one each", () => void action(state.session.id, "deal", { deck_id: deck.id, participant_ids: recipients, count: 1, mode: "per_participant" })));
    controls.append(button("Collect all", () => void action(state.session.id, "collect_all", {}), true));
    controls.append(button("Reset table", () => { if (window.confirm("Reset the table and collect every card?")) void action(state.session.id, "reset_session", { shuffle: true }); }, true));
  }
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
  if (cards.length === 0) {
    const empty = document.createElement("p"); empty.className = "table-empty"; empty.textContent = "Draw or play a card to place it here."; surface.append(empty);
  }
  cards.forEach((card, index) => surface.append(renderCard(card, index)));
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
    const item = renderCard(card, index, true);
    const play = button("Play face down", () => void action(state.session.id, "play_from_hand", { card_id: card.id, face_state: "down", x: 24 + index * 28, y: 24, expected_card_version: card.version }), true);
    item.append(play);
    handRow.append(item);
  });
  hand.append(handRow); board.append(hand);
  workspace.append(board);
}

function renderCard(card: Card, index: number, inHand = false): HTMLElement {
  const item = document.createElement("article");
  item.className = `card ${card.face_state === "up" || inHand ? "face-up" : "face-down"}`;
  item.dataset.cardId = card.id;
  item.style.zIndex = String(card.z_index || index + 1);
  const x = card.x ?? 24 + (index % 8) * 74;
  const y = card.y ?? 24 + Math.floor(index / 8) * 28;
  if (!inHand) {
    item.style.left = `${x}px`; item.style.top = `${y}px`; item.style.transform = `rotate(${card.rotation || 0}deg)`;
    item.title = "Drag to move. Double click to turn the card.";
    let drag: { pointerX: number; pointerY: number; startX: number; startY: number; moved: boolean } | null = null;
    item.addEventListener("pointerdown", (event) => {
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
    item.addEventListener("dblclick", () => void action(statefulSessionId(), "flip_card", { card_id: card.id, expected_card_version: card.version }));
  }
  const label = document.createElement("strong");
  label.textContent = card.card_definition_id ? `Card ${card.card_definition_id.slice(0, 8)}` : (card.face_state === "private" ? "Private card" : "Face down");
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
