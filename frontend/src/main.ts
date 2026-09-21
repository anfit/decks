import "./styles.css";

type User = { id: string; email: string; role: string };
type Session = { id: string; title: string | null; status: string; revision: number; host_user_id: string };
type State = { revision: number; session: Session; participants: Array<{ id: string; role: string; is_current: boolean; hand_count: number }>; cards: Array<{ id: string; location_type: string; deck_id: string | null; pile_id: string | null; hand_participant_id: string | null; card_definition_id?: string; face_state: string; x: number | null; y: number | null }> };

const workspace = document.querySelector<HTMLElement>("#workspace");
const status = document.querySelector<HTMLElement>("#connection-status");
let csrf = "";
let currentState: State | null = null;
let socket: WebSocket | null = null;

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
  const meta = document.createElement("p"); meta.className = "muted"; meta.textContent = `${state.session.status} · revision ${state.revision} · ${state.cards.length} cards`; workspace.append(meta);
  const players = document.createElement("ul"); players.className = "players";
  for (const participant of state.participants) { const row = document.createElement("li"); row.textContent = `${participant.is_current ? "You" : "Player"} · ${participant.role} · ${participant.hand_count} in hand`; players.append(row); }
  workspace.append(players);
  const controls = document.createElement("div"); controls.className = "actions";
  controls.append(button("Refresh", () => void refreshTable(state.session.id), true));
  const deck = state.cards.find((card) => card.deck_id !== null);
  if (deck?.deck_id) {
    controls.append(button("Draw top", () => void action(state.session.id, "draw_top", { deck_id: deck.deck_id })));
    controls.append(button("Shuffle", () => void action(state.session.id, "shuffle_deck", { deck_id: deck.deck_id }), true));
  }
  workspace.append(controls);
  const back = document.createElement("a"); back.href = "/"; back.textContent = "Back to tables"; workspace.append(back);
  connectRealtime(state.session.id);
  setStatus("Connected", "ok");
}

async function action(sessionId: string, type: string, payload: Record<string, unknown>): Promise<void> {
  try { await api(`/api/sessions/${sessionId}/actions`, { method: "POST", body: JSON.stringify({ action_id: crypto.randomUUID(), type, payload, expected_session_revision: currentState?.revision ?? null }) }); await refreshTable(sessionId); }
  catch (error) { setStatus((error as Error).message, "error"); await refreshTable(sessionId); }
}

async function refreshTable(sessionId: string): Promise<void> { const result = await api(`/api/sessions/${sessionId}/state`); renderTable(result.state as State); }

function connectRealtime(sessionId: string): void {
  void api(`/api/sessions/${sessionId}/realtime-ticket`, { method: "POST" }).then((result) => {
    socket?.close(); const protocol = location.protocol === "https:" ? "wss:" : "ws:";
    socket = new WebSocket(`${protocol}//${location.host}/ws?ticket=${encodeURIComponent(result.ticket as string)}`);
    socket.addEventListener("message", (event) => { const message = JSON.parse(event.data as string) as { type?: string }; if (message.type === "session_changed") void refreshTable(sessionId); });
    socket.addEventListener("close", () => setStatus("Realtime connection closed; refresh to reconnect", "error"));
  }).catch((error: unknown) => setStatus(`Realtime unavailable: ${(error as Error).message}`, "error"));
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
