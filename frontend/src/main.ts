import "./styles.css";

const status = document.querySelector<HTMLElement>("#connection-status");

async function checkHealth(): Promise<void> {
  if (!status) return;
  try {
    const response = await fetch("/health", { headers: { Accept: "application/json" } });
    status.textContent = response.ok ? "Ready" : "Service health check failed";
    status.dataset.state = response.ok ? "ok" : "error";
  } catch {
    status.textContent = "Offline — reconnecting will be automatic";
    status.dataset.state = "error";
  }
}

void checkHealth();
