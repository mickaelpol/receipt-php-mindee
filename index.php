<?php
/** -------------------------------------------------------------
 *  Receipt Parser API (Render)
 *  POST { imageBase64 }  -> { supplier, dateISO, total }
 *  -------------------------------------------------------------
 */

// --- garder la réponse JSON propre (pas d’avertissements à l’écran)
if (function_exists('ini_set')) {
  ini_set('display_errors', '0');
  ini_set('log_errors', '1'); // les erreurs iront dans les logs Render
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
header('Content-Type: application/json');

// --- CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (($_SERVER['REQUEST_URI'] ?? '/') === '/ping') { echo json_encode(['ok' => true]); exit; }
  http_response_code(405); echo json_encode(['error' => 'Use POST']); exit;
}

// --- payload
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body || empty($body['imageBase64']) || strlen($body['imageBase64']) < 100) {
  http_response_code(400); echo json_encode(['error' => 'imageBase64 required']); exit;
}

// --- env vars
$apiKey  = getenv('MINDEE_API_KEY') ?: '';
$modelId = getenv('MINDEE_MODEL_ID') ?: '';
if (!$apiKey || !$modelId) {
  http_response_code(500);
  echo json_encode(['error' => 'MINDEE_API_KEY or MINDEE_MODEL_ID not set']);
  exit;
}

// --- Mindee SDK
require __DIR__ . '/vendor/autoload.php';
use Mindee\ClientV2;
use Mindee\Input\PathInput;
use Mindee\Input\InferenceParameters;

/* ======================
   Helpers
====================== */
function toNum($s){
  if ($s === null || $s === '') return null;
  $n = floatval(str_replace([' ', ','], ['', '.'], (string)$s));
  return is_finite($n) ? round($n, 2) : null;
}
function toISO($s){
  if (!$s) return null; $s = trim((string)$s);
  if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $s)) return $s;
  if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/', $s, $m)){
    $y = (int)$m[3]; if ($y < 100) $y += 2000;
    return sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]);
  }
  return null;
}
function flattenAssoc(array $a, string $prefix=''): array {
  $out = [];
  foreach ($a as $k => $v) {
    $path = $prefix === '' ? (string)$k : $prefix . '.' . $k;
    if (is_array($v)) $out += flattenAssoc($v, $path);
    else $out[$path] = $v;
  }
  return $out;
}
function uniqueKeepOrder(array $arr){
  $seen = []; $out = [];
  foreach($arr as $x){
    $k = md5((string)$x);
    if (isset($seen[$k])) continue;
    $seen[$k] = 1; $out[] = $x;
  }
  return $out;
}
// renvoie le texte d'un node Mindee possible
function firstText($v){
  if (is_array($v)) { $v0 = $v[0] ?? null; if (is_array($v0)) return $v0['content'] ?? $v0['text'] ?? $v0['value'] ?? null; return $v0; }
  if (is_object($v)) return $v->content ?? $v->text ?? $v->value ?? null;
  return $v;
}
// détecte "ça ressemble à un nom de fichier"
function isFilenameLike(string $s): bool {
  $s = trim($s);
  if ($s === '') return false;
  if (preg_match('/\.(jpg|jpeg|png|pdf|tif|tiff)$/i', $s)) return true;
  if (preg_match('#[\\\\/]+#', $s)) return true;
  if (preg_match('/^(img|pXL|dsc|rcpt|scan)[_\-]?\d+/i', $s)) return true;
  if (preg_match('/^[a-z0-9_\-]+\.(?:tmp|dat)$/i', $s)) return true;
  return false;
}
function pickSupplierFromList(array $cands): ?string {
  foreach ($cands as $c) {
    $t = trim((string)$c);
    if ($t !== '' && mb_strlen($t) >= 2 && !preg_match('/^\d+$/', $t) && !isFilenameLike($t)) {
      return $t;
    }
  }
  return null;
}

// Score “intelligent” pour les montants (favorise total à payer, pénalise réservations)
function scoreAmountCandidate(float $n, string $path, string $ctx): int {
  $s = 0;
  $good = '/a\s*pagar|à\s*payer|pagado|importe\s+total|total\s*cb|total\s*ttc|ttc|paid|payment|to\s*pay|net\s*(?:to|à)?\s*payer|suminist|suministro/i';
  $bad  = '/reservat|reserva|pre.?aut|pre.?autor|preauth|pre.?authorization|cauci[oó]n|dep[oó]sito|bloqueo|reservation/i';
  if (preg_match($good, $ctx) || preg_match($good, $path)) $s += 5;
  if (preg_match($bad,  $ctx) || preg_match($bad,  $path)) $s -= 6;
  if (preg_match('/total|amount|sum|grand|ttc|pay/i', $path)) $s += 1;
  return $s;
}

/* ======================
   Main
====================== */
try {
  // 1) Écrire l’image base64 dans un fichier temporaire (compat maximale)
  $tmp    = tempnam(sys_get_temp_dir(), 'rcpt_');
  $tmpJpg = $tmp . '.jpg';
  file_put_contents($tmpJpg, base64_decode($body['imageBase64']));

  // 2) Client Mindee v2 + paramètres (modelId obligatoire)
  $client = new ClientV2($apiKey);
  $params = new InferenceParameters($modelId);

  // 3) Input via PathInput (évite les soucis de versions de SDK)
  $input     = new PathInput($tmpJpg);
  $response  = $client->enqueueAndGetInference($input, $params);

  // (facultatif) pour diagnostiquer la forme exacte de la réponse :
  // error_log("MINDEE RAW: " . print_r($response, true));

  // Nettoyage des temporaires
  @unlink($tmpJpg);
  @unlink($tmp);

  // 4) Extraction robuste des champs à partir de la réponse
  $arr  = json_decode(json_encode($response), true);
  $flat = flattenAssoc($arr);

  // ----- SUPPLIER (priorité aux champs "propres") -----
  $pred = $arr['inference']['prediction'] ?? ($arr['document']['inference']['prediction'] ?? null);

  $supplierCandidates = [];
  if (is_array($pred)) {
    $supplierCandidates[] = firstText($pred['supplier_name'] ?? null);
    $supplierCandidates[] = firstText($pred['merchant_name'] ?? null);
    $supplierCandidates[] = firstText($pred['company_name'] ?? null);
    $supplierCandidates[] = firstText($pred['store_name'] ?? null);
    $supplierCandidates[] = firstText($pred['retailer_name'] ?? null);
    $supplierCandidates[] = firstText($pred['supplier'] ?? null);
    $supplierCandidates[] = firstText($pred['merchant'] ?? null);
    $supplierCandidates = array_filter($supplierCandidates, fn($x) => $x !== null && $x !== '');
  }
  if (empty($supplierCandidates)) {
    foreach ($flat as $path => $val) {
      if ($val === null || $val === '') continue;
      if (!preg_match('/supplier|merchant|store|vendor|company|retailer|name/i', $path)) continue;
      if (is_string($val) && !isFilenameLike($val)) $supplierCandidates[] = $val;
    }
  }
  $supplier = pickSupplierFromList($supplierCandidates) ?? '';

  // ----- DATES & TOTAL (pondération) -----
  $dates   = [];
  $amounts = []; // tableau de candidats: ['num'=>float,'path'=>string,'ctx'=>string,'score'=>int]

  foreach ($flat as $path => $val) {
    if ($val === null || $val === '') continue;

    // dates
    if (is_string($val)) {
      if (preg_match('/\b(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4})\b/', $val, $m)) {
        $dates[] = $m[1];
      }
    }

    // montants (string)
    if (is_string($val)) {
      if (preg_match_all('/(\d[\d\s]{0,3}(?:\s?\d{3})*[.,]\d{2})\b/', $val, $mm)) {
        foreach ($mm[1] as $found) {
          $n = toNum($found);
          if ($n !== null && $n > 0) {
            $amounts[] = [
              'num'   => $n,
              'path'  => (string)$path,
              'ctx'   => (string)$val,
              'score' => scoreAmountCandidate($n, (string)$path, (string)$val),
            ];
          }
        }
      }
    }
    // montants numériques sous clés total/amount/sum…
    elseif (is_numeric($val)) {
      if (preg_match('/total|amount|sum|grand/i', $path)) {
        $n = toNum($val);
        if ($n !== null && $n > 0) {
          $amounts[] = [
            'num'   => $n,
            'path'  => (string)$path,
            'ctx'   => (string)$val,
            'score' => scoreAmountCandidate($n, (string)$path, (string)$val),
          ];
        }
      }
    }
  }

  $dates = uniqueKeepOrder($dates);

  // Choix de la date
  $dateISO = null;
  foreach ($dates as $d) { $dt = toISO($d); if ($dt) { $dateISO = $dt; break; } }

  // Sélection du total avec pondération
  $total = null;
  if (!empty($amounts)) {
    // éliminer les très “mauvais” si on a d'autres options
    $nonBad = array_filter($amounts, fn($a) => $a['score'] > -3);
    $pool   = !empty($nonBad) ? $nonBad : $amounts;

    // tri par score desc, puis en cas d’égalité on préfère celui qui n’est pas .00
    usort($pool, function($a, $b){
      if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
      $a00 = fmod($a['num'], 1.0) === 0.0;
      $b00 = fmod($b['num'], 1.0) === 0.0;
      if ($a00 !== $b00) return $a00 <=> $b00; // false(=0.68) < true(=99.00) → 0.68 priorisé
      return $b['num'] <=> $a['num']; // sinon plus grand
    });

    $best = $pool[0];
    $total = $best['num'];
    // (debug) error_log("TOTAL CANDS: " . print_r($pool, true));
  }

  echo json_encode([
    'supplier' => $supplier,
    'dateISO'  => $dateISO,
    'total'    => $total,
  ]);
  exit;

} catch (\Throwable $e) {
  http_response_code(502);
  echo json_encode(['error' => 'SDK error', 'message' => $e->getMessage()]);
  exit;
}
