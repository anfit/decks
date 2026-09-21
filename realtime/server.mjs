import http from "node:http";
import crypto from "node:crypto";
import { Client } from "pg";
import { WebSocketServer } from "ws";

const bind = process.env.DECKS_REALTIME_BIND ?? "127.0.0.1:5242";
const match = /^127\.0\.0\.1:(\d{2,5})$/.exec(bind);
if (!match) throw new Error("DECKS_REALTIME_BIND must be a loopback IPv4 address and port");
const port = Number(match[1]);
const signingKey = process.env.DECKS_REALTIME_SIGNING_KEY ?? "";
if (signingKey.length < 32) throw new Error("DECKS_REALTIME_SIGNING_KEY must contain at least 32 bytes");
const dbConfig = {
  host: process.env.DECKS_REALTIME_DB_HOST ?? process.env.DECKS_DB_HOST ?? "127.0.0.1",
  port: Number(process.env.DECKS_REALTIME_DB_PORT ?? process.env.DECKS_DB_PORT ?? 5432),
  database: process.env.DECKS_REALTIME_DB_NAME ?? process.env.DECKS_DB_NAME,
  user: process.env.DECKS_REALTIME_DB_USER ?? process.env.DECKS_DB_USER,
  password: process.env.DECKS_REALTIME_DB_PASSWORD ?? process.env.DECKS_DB_PASSWORD,
};
const clients = new Set();
let listener = null;
let listenerReady = false;

function decode(value) {
  return Buffer.from(value.replaceAll("-", "+").replaceAll("_", "/") + "=".repeat((4 - value.length % 4) % 4), "base64").toString("utf8");
}

function verifyTicket(raw) {
  const [body, signature] = String(raw ?? "").split(".", 2);
  if (!body || !signature) return null;
  const expected = crypto.createHmac("sha256", signingKey).update(body).digest();
  const actual = Buffer.from(signature.replaceAll("-", "+").replaceAll("_", "/") + "=".repeat((4 - signature.length % 4) % 4), "base64");
  if (actual.length !== expected.length || !crypto.timingSafeEqual(actual, expected)) return null;
  let payload;
  try { payload = JSON.parse(decode(body)); } catch { return null; }
  if (!payload || typeof payload !== "object" || typeof payload.session_id !== "string" || typeof payload.user_id !== "string" || Number(payload.exp) < Math.floor(Date.now() / 1000)) return null;
  return payload;
}

async function authorized(payload) {
  if (!dbConfig.database || !dbConfig.user || dbConfig.password === undefined) return false;
  const db = new Client(dbConfig);
  try {
    await db.connect();
    const result = await db.query(
      `SELECT 1 FROM session_participants p JOIN app_user u ON u.id = p.user_id
        WHERE p.session_id = $1 AND p.user_id = $2 AND p.removed_at IS NULL
          AND u.enabled = true AND u.security_version = $3`,
      [payload.session_id, payload.user_id, Number(payload.security_version ?? 0)],
    );
    return result.rowCount === 1;
  } finally { await db.end().catch(() => {}); }
}

async function startListener() {
  if (!dbConfig.database || !dbConfig.user || dbConfig.password === undefined) return;
  listener = new Client(dbConfig);
  listener.on("notification", (message) => {
    if (message.channel !== "decks_session_changed") return;
    let payload;
    try { payload = JSON.parse(message.payload ?? "{}"); } catch { return; }
    if (typeof payload.session_id !== "string") return;
    const text = JSON.stringify({ type: "session_changed", session_id: payload.session_id, revision: Number(payload.revision ?? 0) });
    for (const socket of clients) if (socket.sessionId === payload.session_id && socket.readyState === 1) socket.send(text);
  });
  listener.on("error", (error) => { listenerReady = false; console.error(`decks-realtime listener error: ${error.message}`); });
  await listener.connect();
  await listener.query("LISTEN decks_session_changed");
  listenerReady = true;
}

const server = http.createServer((request, response) => {
  if (request.url === "/health") {
    response.writeHead(200, { "content-type": "application/json", "cache-control": "no-store" });
    response.end(JSON.stringify({ status: "ok", service: "decks-realtime", listener: listenerReady }));
    return;
  }
  response.writeHead(404, { "content-type": "application/json" });
  response.end(JSON.stringify({ error: "not_found" }));
});

const wss = new WebSocketServer({ noServer: true, maxPayload: 4096 });
wss.on("connection", (socket, payload) => {
  socket.sessionId = payload.session_id;
  socket.userId = payload.user_id;
  socket.messages = [];
  clients.add(socket);
  socket.send(JSON.stringify({ type: "ready", session_id: payload.session_id }));
  const heartbeat = setInterval(() => { if (socket.readyState === 1) socket.ping(); }, 30000);
  socket.on("message", (data) => {
    const now = Date.now();
    socket.messages = socket.messages.filter((at) => now - at < 1000);
    if (socket.messages.length >= 20) { socket.close(1008, "rate_limit"); return; }
    socket.messages.push(now);
    try {
      const message = JSON.parse(data.toString());
      if (message?.type === "ping") socket.send(JSON.stringify({ type: "pong" }));
    } catch { socket.close(1003, "invalid_message"); }
  });
  socket.on("close", () => { clients.delete(socket); clearInterval(heartbeat); });
});

server.on("upgrade", async (request, socket, head) => {
  try {
    const url = new URL(request.url ?? "/", "http://127.0.0.1");
    if (url.pathname !== "/ws") throw new Error("not_found");
    const payload = verifyTicket(url.searchParams.get("ticket"));
    if (!payload || !(await authorized(payload))) throw new Error("unauthorized");
    wss.handleUpgrade(request, socket, head, (webSocket) => wss.emit("connection", webSocket, payload));
  } catch {
    socket.write("HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n");
    socket.destroy();
  }
});

server.listen(port, "127.0.0.1", async () => {
  console.log(`decks-realtime listening on ${bind}`);
  try { await startListener(); } catch (error) { console.error(`decks-realtime database listener unavailable: ${error.message}`); }
});

async function shutdown() {
  for (const socket of clients) socket.close(1001, "server_shutdown");
  if (listener) await listener.end().catch(() => {});
  server.close(() => process.exit(0));
}
process.once("SIGTERM", shutdown);
process.once("SIGINT", shutdown);
