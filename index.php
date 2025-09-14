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

  $suppliers = [];
  $dates     = [];
  $amounts   = [];

  foreach ($flat as $path => $val) {
    if ($val === null || $val === '') continue;

    // Nom d'enseigne (supplier/merchant/store/company/vendor/retailer/name…)
    if (preg_match('/supplier|merchant|store|vendor|company|retailer|name/i', $path)) {
      if (is_string($val)) {
        $txt = trim($val);
        if (mb_strlen($txt) >= 3 && !preg_match('/^\d+$/', $txt)) {
          $suppliers[] = $txt;
        }
      }
    }

    // Dates (ISO ou jj/mm/aaaa)
    if (is_string($val)) {
      if (preg_match('/\b(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4})\b/', $val, $m)) {
        $dates[] = $m[1];
      }
    }

    // Montants (string avec décimales)
    if (is_string($val)) {
      if (preg_match('/(\d[\d\s]{0,3}(?:\s?\d{3})*[.,]\d{2})\b/', $val, $m)) {
        $n = toNum($m[1]);
        if ($n !== null && $n > 0) $amounts[] = $n;
      }
    }
    // Montants numériques sous clés total/amount/sum…
    elseif (is_numeric($val)) {
      if (preg_match('/total|amount|sum|grand/i', $path)) {
        $n = toNum($val);
        if ($n !== null && $n > 0) $amounts[] = $n;
      }
    }
  }

  // Dédupliquer
  $suppliers = uniqueKeepOrder($suppliers);
  $dates     = uniqueKeepOrder($dates);
  $amounts   = uniqueKeepOrder($amounts);

  // Choix final
  $supplier = $suppliers[0] ?? '';

  $dateISO  = null;
  foreach ($dates as $d) { $dt = toISO($d); if ($dt) { $dateISO = $dt; break; } }

  $total = null;
  if (!empty($amounts)) {
    $plausible = array_values(array_filter($amounts, fn($x) => $x > 0.2 && $x < 20000));
    if (!empty($plausible)) $total = max($plausible);
  }

  // (fallbacks directs si ton modèle expose des clés “propres”)
  // $pred     = $arr['inference']['prediction'] ?? $arr['document']['inference']['prediction'] ?? [];
  // $supplier = $supplier ?: ($pred['supplier_name']['value'] ?? $pred['merchant_name']['value'] ?? '');
  // $dateISO  = $dateISO  ?: toISO($pred['date']['value'] ?? $pred['purchase_date']['value'] ?? null);
  // $total    = $total    ?: toNum($pred['total_amount']['value'] ?? $pred['amount_total']['value'] ?? null);

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
