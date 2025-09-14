// api/parse.js — Vercel Serverless Function (Node 18+)
// OCR via OCR.space (gratuit pour ton volume). Renvoie { supplier, dateISO, total }.
// Tu peux tester sans clé avec "helloworld", puis mettre ta clé ensuite.

export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'Use POST' });

  try {
    const key = process.env.OCRSPACE_API_KEY || 'helloworld';
    const { imageBase64 } = req.body || {};
    if (!imageBase64 || typeof imageBase64 !== 'string' || imageBase64.length < 1000) {
      return res.status(400).json({ error: 'imageBase64 required (string, reasonable size)' });
    }

    // Appel OCR.space
    const form = new URLSearchParams();
    form.set('base64Image', 'data:image/jpeg;base64,' + imageBase64);
    form.set('language', 'fre,eng,spa,cat');
    form.set('OCREngine', '2');
    form.set('isTable', 'false');
    form.set('scale', 'true');
    form.set('detectOrientation', 'true');

    const r = await fetch('https://api.ocr.space/parse/image', {
      method: 'POST',
      headers: { 'apikey': key, 'Content-Type': 'application/x-www-form-urlencoded' },
      body: form
    });

    const raw = await r.text();
    let data; try { data = JSON.parse(raw); } catch { return res.status(502).json({ error: 'OCR.space invalid JSON', raw }); }
    if (!data || data.IsErroredOnProcessing || !Array.isArray(data.ParsedResults)) {
      return res.status(502).json({ error: 'OCR.space error', detail: data });
    }

    const txt = (data.ParsedResults.map(p => p.ParsedText || '').join('\n') || '').replace(/\r/g,'');
    const parsed = parseReceiptText(txt);
    return res.status(200).json(parsed);
  } catch (e) {
    return res.status(500).json({ error: String(e) });
  }
}

export const config = { api: { bodyParser: { sizeLimit: '10mb' } } };

// -------- Parsing heuristique (supplier/date/total) ----------
function parseReceiptText(txt) {
  const lines = txt.split('\n').map(s => s.replace(/\s+/g,' ').trim()).filter(Boolean);

  // Supplier (enseigne) = premières lignes significatives
  const bad = /(SIRET|TVA|FACTURE|TICKET|NUM|CARTE|PAIEMENT|VENTE|CAISSE|CLIENT|TEL|WWW|HTTP|EMAIL|SITE|CIF|NRT|NIF|RCS|CONFIRMA|TPV|APLIC|VISA)/i;
  const looksAddr = /(RUE|AVENUE|AVDA|AV\.|BD|PLAZA|PLACE|CHEMIN|CARRER|CP|\b\d{5}\b|ANDORRA|FRANCE|ESPA(N|Ñ)A|PORTUGAL)/i;
  const known = /(ASF|VINCI|CARREFOUR|LECLERC|E\.?LECLERC|INTERMARCH|AUCHAN|LIDL|MONOPRIX|CASINO|ALDI|DECATHLON|ACTION|FNAC|DARTY|BOULANGER|PICARD|BIOCOOP|PRIMARK|ZARA|IKEA|H&M|TOTAL(?:\s?ENERGIES)?|REDSYS|GASOPAS|GASOLINERA|ES\s+GASOPAS|E\.S\.)/i;

  let supplier = '';
  for (let i=0;i<Math.min(15, lines.length); i++){
    const L = lines[i];
    if (!L || bad.test(L) || looksAddr.test(L)) continue;
    if (known.test(L) || /^[A-ZÀ-ÖØ-Þ0-9\.\- ']{2,45}$/.test(L)) { supplier = L; break; }
  }

  // Date -> première date trouvée
  const dateRx = /(\b[0-3]?\d[\/.\-][01]?\d[\/.\-](?:\d{2}|\d{4})\b)/g;
  const dates = []; let m;
  while ((m = dateRx.exec(txt))) dates.push(m[1]);
  const dateISO = toISO(dates[0] || '');

  // Total -> mots-clés, sinon plus grand montant plausible
  const keyRxs = [
    /TOTAL\s*TT?C?/i, /TOTAL\s+À\s+PAYER/i, /NET\s+À\s+PAYER/i, /TOTAL\s+CB/i,
    /IMPORTE\s+TOTAL/i, /A\s+PAGAR/i, /PAGADO/i, /TOTAL\s+RESERVAT/i, /TOTAL\s+SUMINIST/i
  ];
  let total = null;
  for (const k of keyRxs) {
    const mm = txt.match(new RegExp(k.source + `\\s*[:\\-]?\\s*([\\d\\s]+[\\.,]\\d{2})\\s*(?:€|EUR)?`, k.flags));
    if (mm) { total = toNum(mm[1]); break; }
  }
  if (total == null) {
    const rx = /(\d[\d\s]{0,3}(?:\s?\d{3})*[.,]\d{2})\s*(?:€|EUR)?\b(?!\s*%)/g;
    const nums = []; let m2;
    while ((m2 = rx.exec(txt))) {
      const n = toNum(m2[1]); if (n != null && n > 0.2 && n < 20000) nums.push(n);
    }
    if (nums.length) total = nums.sort((a,b)=>b-a)[0];
  }

  return { supplier: supplier || '', dateISO: dateISO || null, total: total != null ? +total.toFixed(2) : null, rawText: txt };
}
function toISO(s){ const m=String(s||'').match(/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/); if(!m) return null; let[_,d,mo,y]=m; y=+y<100?+y+2000:+y; const pad=x=>String(x).padStart(2,'0'); return `${y}-${pad(+mo)}-${pad(+d)}`; }
function toNum(s){ const n=parseFloat(String(s).replace(/\s/g,'').replace(',','.')); return Number.isFinite(n)?n:null; }
