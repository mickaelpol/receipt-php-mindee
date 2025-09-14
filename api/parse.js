// api/parse.js — Vercel Serverless Function (Node 18+)
export default async function handler(req, res) {
  // CORS
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'Use POST' });

  try {
    const apiKey = process.env.MINDEE_API_KEY;
    if (!apiKey) return res.status(500).json({ error: 'MINDEE_API_KEY not set' });

    const { imageBase64 } = req.body || {};
    if (!imageBase64 || typeof imageBase64 !== 'string') {
      return res.status(400).json({ error: 'imageBase64 required (string, no data: prefix)' });
    }
    // Sanity check: taille raisonnable (évite images trop petites/vides)
    if (imageBase64.length < 1000) {
      return res.status(400).json({ error: 'imageBase64 too short (invalid image)' });
    }

    const payload = { document: { type: 'base64', value: imageBase64 } };

    const r = await fetch(
      'https://api.mindee.net/v1/products/mindee/expense_receipts/v5/predict',
      {
        method: 'POST',
        headers: {
          'Authorization': `Token ${apiKey}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
      }
    );

    const text = await r.text(); // lis brut, puis essaie JSON
    let data = null;
    try { data = JSON.parse(text); } catch { data = { raw: text }; }

    if (!r.ok) {
      // Mindee renvoie souvent un objet { api_request: { error: {...} } }
      const errMsg =
        data?.api_request?.error ??
        data?.error ??
        data?.raw ??
        `HTTP ${r.status}`;
      return res.status(502).json({ error: `Mindee error: ${toStringSafe(errMsg)}` });
    }

    const pred = data?.documents?.[0]?.inference?.prediction || {};
    const first = (v) =>
      Array.isArray(v)
        ? (v[0]?.content || v[0]?.text || v[0]?.value)
        : (v?.content || v?.text || v?.value || v);
    const toNum = (s) => {
      const n = parseFloat(String(s ?? '').replace(/\s/g, '').replace(',', '.'));
      return Number.isFinite(n) ? +n.toFixed(2) : null;
    };
    const toISO = (s) => {
      if (!s) return null;
      const a = String(s).trim();
      let m = a.match(/^20\d{2}-\d{1,2}-\d{1,2}$/);
      if (m) return a;
      m = a.match(/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/);
      if (!m) return null;
      let [_, d, mo, y] = m;
      y = +y < 100 ? +y + 2000 : +y;
      const pad = (x) => String(x).padStart(2, '0');
      return `${y}-${pad(mo)}-${pad(d)}`;
    };

    const supplier = first(pred.supplier_name) || first(pred.merchant_name) || '';
    const dateISO  = toISO(first(pred.date) || first(pred.purchase_date));
    const total    = toNum(first(pred.total_amount) || first(pred.amount_total));

    const items = (pred.line_items || []).map((li) => ({
      description: first(li.description) || '',
      quantity: toNum(first(li.quantity)),
      unitPrice: toNum(first(li.unit_price)),
      amount: toNum(first(li.total))
    }));

    return res.status(200).json({ supplier, dateISO, total, items });
  } catch (e) {
    return res.status(500).json({ error: toStringSafe(e) });
  }
}

// Vercel: augmente la taille max du body JSON
export const config = {
  api: { bodyParser: { sizeLimit: '10mb' } }
};

function toStringSafe(x) {
  if (x == null) return String(x);
  if (typeof x === 'string') return x;
  try { return JSON.stringify(x); } catch { return String(x); }
}
