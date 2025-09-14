<?php
/**
 * Minimal Mindee PHP endpoint (Render)
 * POST { imageBase64 } -> { supplier, dateISO, total }
 * - Utilise ClientV2 + InferenceParameters comme dans la doc officielle
 * - Lit MINDEE_API_KEY et MINDEE_MODEL_ID depuis les variables d'env
 * - ?debug=1 : renvoie la prediction brute pour inspection
 */

if (function_exists('ini_set')) {
  ini_set('display_errors', '0'); // pas d'erreurs à l'écran
  ini_set('log_errors', '1');     // erreurs dans les logs Render
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
header('Content-Type: application/json');

// CORS simple
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  // santé simple
  if (($_SERVER['REQUEST_URI'] ?? '') === '/ping') { echo json_encode(['ok'=>true]); exit; }
  http_response_code(405); echo json_encode(['error'=>'Use POST']); exit;
}

// Charge le body
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body || empty($body['imageBase64']) || strlen($body['imageBase64']) < 100) {
  http_response_code(400);
  echo json_encode(['error'=>'imageBase64 required']);
  exit;
}

// Secrets (Render → Settings → Environment)
$apiKey  = getenv('MINDEE_API_KEY') ?: '';
$modelId = getenv('MINDEE_MODEL_ID') ?: ''; // ex: 5c4cbb10-c69c-4c65-b942-7f8ca0559519
if (!$apiKey || !$modelId) {
  http_response_code(500);
  echo json_encode(['error'=>'MINDEE_API_KEY or MINDEE_MODEL_ID not set']);
  exit;
}

// SDK Mindee
require __DIR__ . '/vendor/autoload.php';

use Mindee\ClientV2;
use Mindee\Input\PathInput;
use Mindee\Input\InferenceParameters;

/* Helpers compacts et neutres (aucune heuristique OCR libre) */
function firstVal($v) {
  if (is_array($v)) {
    // Mindee peut renvoyer soit un scalaire, soit un array d'objets {value,text,content}
    if (isset($v['value']) || isset($v['text']) || isset($v['content'])) {
      return $v['value'] ?? $v['text'] ?? $v['content'] ?? null;
    }
    $v0 = $v[0] ?? null;
    if (is_array($v0)) return $v0['value'] ?? $v0['text'] ?? $v0['content'] ?? null;
    return $v0;
  }
  if (is_object($v)) return $v->value ?? $v->text ?? $v->content ?? null;
  return $v;
}
function toNum($v) {
  if ($v === null || $v === '') return null;
  $n = floatval(str_replace([',',' '], ['.',''], (string)$v));
  return is_finite($n) ? round($n, 2) : null;
}
function toISO($v) {
  if (!$v) return null;
  $s = trim((string)$v);
  if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $s)) return $s;
  if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/', $s, $m)) {
    $y = (int)$m[3]; if ($y < 100) $y += 2000;
    return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]);
  }
  return null;
}

// Récupère "prediction" où qu'elle se trouve dans la réponse (custom ou pré-entraîné)
function findPrediction($arr) {
  if (!is_array($arr)) return null;
  // cas classiques
  if (isset($arr['document']['inference']['prediction']) && is_array($arr['document']['inference']['prediction'])) {
    return $arr['document']['inference']['prediction'];
  }
  if (isset($arr['inference']['prediction']) && is_array($arr['inference']['prediction'])) {
    return $arr['inference']['prediction'];
  }
  // certains modèles stockent par "pages"
  if (isset($arr['document']['inference']['pages'][0]['prediction']) && is_array($arr['document']['inference']['pages'][0]['prediction'])) {
    return $arr['document']['inference']['pages'][0]['prediction'];
  }
  if (isset($arr['inference']['pages'][0]['prediction']) && is_array($arr['inference']['pages'][0]['prediction'])) {
    return $arr['inference']['pages'][0]['prediction'];
  }
  // fallback récursif simple
  foreach ($arr as $k => $v) {
    if ($k === 'prediction' && is_array($v)) return $v;
    if (is_array($v)) {
      $found = findPrediction($v);
      if ($found) return $found;
    }
  }
  return null;
}

try {
  // 1) Sauve l'image base64 en fichier tmp
  $tmp = tempnam(sys_get_temp_dir(), 'rcpt_');
  $jpg = $tmp . '.jpg';
  file_put_contents($jpg, base64_decode($body['imageBase64']));

  // 2) Client + Params EXACTEMENT comme doc Mindee (ton modelId GUID)
  $mindeeClient    = new ClientV2($apiKey);
  $inferenceParams = new InferenceParameters(
    $modelId,
    rag: null,
    rawText: null,
    polygon: null,
    confidence: null
  );
  $inputSource     = new PathInput($jpg);

  // 3) Enqueue & Poll
  $response = $mindeeClient->enqueueAndGetInference($inputSource, $inferenceParams);

  // Nettoyage
  @unlink($jpg);
  @unlink($tmp);

  // 4) Convertit la réponse en array pour accès facile
  $arr = json_decode(json_encode($response), true);

  // 5) Récupère la prediction (structure Mindee)
  $prediction = findPrediction($arr);

  // Mode debug : renvoie la prediction brute et ses clés
  if (!empty($_GET['debug'])) {
    echo json_encode([
      'has_prediction'    => (bool)$prediction,
      'prediction_keys'   => $prediction ? array_keys($prediction) : [],
      'prediction_sample' => $prediction ?: null,
    ]);
    exit;
  }

  if (!$prediction || !is_array($prediction)) {
    echo json_encode(['supplier'=>'', 'dateISO'=>null, 'total'=>null, '_note'=>'prediction not found']);
    exit;
  }

  // 6) Extraction MINIMALISTE (on ne lit que les champs structurés)
  // -> adapte les noms de clés ci-dessous à CE QUE TON MODÈLE RENVOIE réellement.
  //    (Utilise ?debug=1 pour voir les clés exactes)
  $supplier = null;
  foreach (['supplier_name','merchant_name','company_name','store_name','retailer_name','supplier','merchant'] as $k) {
    if (!array_key_exists($k, $prediction)) continue;
    $supplier = trim((string) firstVal($prediction[$k]));
    if ($supplier !== '') break;
  }

  $dateISO = null;
  foreach (['date','purchase_date'] as $k) {
    if (!array_key_exists($k, $prediction)) continue;
    $dateISO = toISO(firstVal($prediction[$k]));
    if ($dateISO) break;
  }

  $total = null;
  foreach (['total_amount','amount_total','total_incl','total_ttc','total','total_amount_value'] as $k) {
    if (!array_key_exists($k, $prediction)) continue;
    $total = toNum(firstVal($prediction[$k]));
    if ($total !== null && $total > 0) break;
  }
  if ($total === null) {
    $net = null; $tax = null;
    foreach (['total_net','total_excl','amount_net','net_amount'] as $k) {
      if (array_key_exists($k, $prediction)) { $net = toNum(firstVal($prediction[$k])); if ($net !== null) break; }
    }
    foreach (['total_tax','tax_amount','vat_amount','igi_amount','iva_amount'] as $k) {
      if (array_key_exists($k, $prediction)) { $tax = toNum(firstVal($prediction[$k])); if ($tax !== null) break; }
    }
    if ($net !== null && $tax !== null) $total = round($net + $tax, 2);
  }

  echo json_encode([
    'supplier' => $supplier ?: '',
    'dateISO'  => $dateISO,
    'total'    => $total,
  ]);
  exit;

} catch (\Throwable $e) {
  http_response_code(502);
  echo json_encode(['error'=>'SDK error', 'message'=>$e->getMessage()]);
  exit;
}
