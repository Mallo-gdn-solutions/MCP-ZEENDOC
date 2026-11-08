// Proxy API Zeendoc pour le Manage GDN (armoire de test gdn_solutions).
// Base : POST /login (login, password) -> cookie de session (~38880 s), puis Cookie sur chaque appel.
// Doc : Zeendoc REST API Guide 2.1 (Swagger OAS 3.0).

let cached = { cookie: null, exp: 0 };

function base() {
  const b = process.env.ZEENDOC_API_BASE || "https://armoires.zeendoc.com/gdn_solutions/ws/rest";
  return b.replace(/\/+$/, "");
}

async function login() {
  if (cached.cookie && Date.now() < cached.exp) return cached.cookie;
  const loginVal = process.env.ZEENDOC_LOGIN;
  const passVal = process.env.ZEENDOC_PASSWORD;
  if (!loginVal || !passVal) throw new Error("ZEENDOC_LOGIN / ZEENDOC_PASSWORD non configures dans les variables d'environnement Vercel");
  const r = await fetch(base() + "/login", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ login: loginVal, password: passVal })
  });
  if (!r.ok) throw new Error("Login refuse (HTTP " + r.status + ")");
  let cookie = r.headers.get("set-cookie") || r.headers.get("Set-Cookie") || "";
  // On garde uniquement paires nom=valeur (le set-cookie peut contenir plusieurs cookies separes par des virgules)
  cookie = cookie.split(/,(?=[^;]+=)/).map(function (c) { return c.split(";")[0]; }).join("; ");
  if (!cookie) throw new Error("Login OK mais aucun cookie recu");
  const m = /ZeenDoc=/.test(cookie) ? cookie : null;
  cached.cookie = m || cookie;
  cached.exp = Date.now() + 10 * 3600 * 1000; // ~10 h, conservateur vs 38880 s
  return cached.cookie;
}

async function apiGet(path) {
  const cookie = await login();
  const r = await fetch(base() + path, { headers: { Cookie: cookie } });
  const text = await r.text();
  let body = text;
  try { body = JSON.parse(text); } catch (e) { /* texte brut */ }
  return { ok: r.ok, status: r.status, body: body };
}

export default async function handler(req, res) {
  try {
    const action = (req.query && req.query.action) || "test";
    if (action === "test") {
      const cookie = await login();
      return res.status(200).json({ ok: true, message: "Connexion reussie, session ouverte (~10 h)", cookiePreview: cookie.slice(0, 16) + "..." });
    }
    if (action === "context") {
      const out = await apiGet("/context?getConfigSets=true");
      return res.status(out.ok ? 200 : 502).json(out);
    }
    return res.status(400).json({ ok: false, message: "action inconnue: " + action });
  } catch (e) {
    return res.status(500).json({ ok: false, message: String(e.message || e) });
  }
}
