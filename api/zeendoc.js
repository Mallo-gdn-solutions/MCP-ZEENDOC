// Proxy API Zeendoc pour le Manage GDN (bac a sable).
// Toutes les valeurs sensibles sont dans les variables d'environnement Vercel.
// Endpoints exacts a confirmer avec la documentation technique Zeendoc v4 (02/11/2026).

let cached = { token: null, exp: 0 };

async function getToken() {
  if (cached.token && Date.now() < cached.exp - 60000) return cached.token;
  const url = process.env.ZEENDOC_TOKEN_URL;
  if (!url) throw new Error("ZEENDOC_TOKEN_URL non configuree dans les variables d'environnement");
  const body = new URLSearchParams({
    grant_type: "client_credentials",
    client_id: process.env.ZEENDOC_CLIENT_ID || "",
    client_secret: process.env.ZEENDOC_CLIENT_SECRET || ""
  });
  if (process.env.ZEENDOC_N_CLIENT) body.set("N_Client", process.env.ZEENDOC_N_CLIENT);
  const r = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: body.toString()
  });
  if (!r.ok) throw new Error("Demande de jeton refusée (HTTP " + r.status + ")");
  const j = await r.json();
  if (!j.access_token) throw new Error("Reponse sans access_token");
  cached.token = j.access_token;
  cached.exp = Date.now() + (j.expires_in || 3600) * 1000;
  return cached.token;
}

async function apiGet(path) {
  const base = process.env.ZEENDOC_API_BASE;
  if (!base) throw new Error("ZEENDOC_API_BASE non configuree");
  const token = await getToken();
  const r = await fetch(base + path, { headers: { Authorization: "Bearer " + token } });
  const text = await r.text();
  return { ok: r.ok, status: r.status, body: text.slice(0, 6000) };
}

export default async function handler(req, res) {
  try {
    const action = (req.query && req.query.action) || "test";
    if (action === "test") {
      const token = await getToken();
      return res.status(200).json({ ok: true, message: "Jeton obtenu (expiration ~1h)", tokenPreview: token.slice(0, 6) + "..." });
    }
    if (action === "context") {
      const out = await apiGet("/context");
      return res.status(out.ok ? 200 : 502).json(out);
    }
    return res.status(400).json({ ok: false, message: "action inconnue: " + action });
  } catch (e) {
    return res.status(500).json({ ok: false, message: String(e.message || e) });
  }
}
