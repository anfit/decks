import http from "node:http";

const bind = process.env.DECKS_REALTIME_BIND ?? "127.0.0.1:5242";
const match = /^127\.0\.0\.1:(\d{2,5})$/.exec(bind);
if (!match) throw new Error("DECKS_REALTIME_BIND must be a loopback IPv4 address and port");
const port = Number(match[1]);

const server = http.createServer((request, response) => {
  if (request.url === "/health") {
    response.writeHead(200, { "content-type": "application/json", "cache-control": "no-store" });
    response.end(JSON.stringify({ status: "ok", service: "decks-realtime" }));
    return;
  }
  response.writeHead(404, { "content-type": "application/json" });
  response.end(JSON.stringify({ error: "not_found" }));
});

server.listen(port, "127.0.0.1", () => {
  console.log(`decks-realtime listening on ${bind}`);
});
