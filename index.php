<?php
/** -------------------------------------------------------------
 *  Receipt Parser API (Render)
 *  POST { imageBase64 }  -> { supplier, dateISO, total }
 *  (avec debug robuste pour localiser "prediction")
 *  ------------------------------------------------------------- */

if (function_exists('ini_set')) {
  ini_set('display_errors', '0');
  ini_set('log_errors', '1');
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
header('Content-Type: application/json');

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (($_SERVER['REQUEST_URI'] ?? '/') === '/ping') { echo json_encode(['ok'=>true]); exit; }
  http_response_code(405); echo json_encode(['error'=>'Use POST']); exit;
}

// Payload
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body || empty($body['imageBase64']) || strlen($body['imageBase64']) < 100) {
  http_response_code(400); echo json_encode(['error'=>'imageBase64 required']); exit;
}

// Env vars
$apiKey  = getenv('MINDEE_API_KEY') ?: '';
$modelId = getenv('MINDEE_MODEL_ID') ?: '';
if (!$apiKey || !$modelId) {
  http_response_code(500);
  echo json_encode(['error'=>'MINDEE_API_KEY or MINDEE_MODEL_ID not set']);
  exit;
}

// SDK
require __DIR__ . '/vendor/autoload.php';
use Mindee\ClientV2;
use Mindee\Input\PathInput;
use Mindee\Input\InferenceParameters;

/* ======================
   Helpers
====================== */
function toNum($v){ if($v===null||$v==='') return null; $n=floatval(str_replace([',',' '],['.',''],(string)$v)); return is_finite($n)?round($n,2):null; }
function toISO($v){
  if(!$v) return null; $s=trim((string)$v);
  if(preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/',$s)) return $s;
  if(preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/',$s,$m)){
    $y=(int)$m[3]; if($y<100)$y+=2000; return sprintf('%04d-%02d-%02d',$y,$m[2],$m[1]);
  }
  return null;
}
function firstText($v){
  if (is_array($v)) { $v0 = $v[0] ?? null; if (is_array($v0)) return $v0['value'] ?? $v0['content'] ?? $v0['text'] ?? null; return $v0; }
  if (is_object($v)) return $v->value ?? $v->content ?? $v->text ?? null;
  return $v;
}

/** Liste les chemins (paths) d’un tableau associatif, de façon plate. */
function listPaths($node, $prefix='') : array {
  $out = [];
  if (is_array($node)) {
    foreach ($node as $k => $v) {
      $key = is_int($k) ? "[$k]" : ($prefix === '' ? (string)$k : $prefix.'.'.$k);
      $out[] = $key;
      if (is_array($v)) {
        $out = array_merge($out, listPaths($v, $key));
      }
    }
  }
  return $out;
}

/** Trouve récursivement la première "prediction" crédible. */
function findPredictionRecursive($node) {
  if (!is_array($node)) return null;

  // cas directs connus
  if (isset($node['prediction']) && is_array($node['prediction'])) {
    return $node['prediction'];
  }
  if (isset($node['document']['inference']['prediction']) && is_array($node['document']['inference']['prediction'])) {
    return $node['document']['inference']['prediction'];
  }
  if (isset($node['inference']['prediction']) && is_array($node['inference']['prediction'])) {
    return $node['inference']['prediction'];
  }
  // pages[...] ?
  if (isset($node['document']['inference']['pages'][0]['prediction']) && is_array($node['document']['inference']['pages'][0]['prediction'])) {
    return $node['document']['inference']['pages'][0]['prediction'];
  }
  if (isset($node['inference']['pages'][0]['prediction']) && is_array($node['inference']['pages'][0]['prediction'])) {
    return $node['inference']['pages'][0]['prediction'];
  }

  // recherche générique : si un sous-noeud a une clé "prediction" qui ressemble à un objet
  foreach ($node as $k => $v) {
    if ($k === 'prediction' && is_array($v)) {
      return $v;
    }
    $found = findPredictionRecursive($v);
    if ($found) return $found;
  }
  return null;
}

/* ======================
   Main
====================== */
try {
  // 1) Écrire l’image en tmp
  $tmp = tempnam(sys_get_temp_dir(), 'rcpt_');
  $jpg = $tmp.'.jpg';
  file_put_contents($jpg, base64_decode($body['imageBase64']));

  // 2) Inference Mindee
  $client  = new ClientV2($apiKey);
  $params  = new InferenceParameters($modelId);
  $input   = new PathInput($jpg);
  $resp    = $client->enqueueAndGetInference($input, $params);

  @unlink($jpg); @unlink($tmp);

  // 3) JSON associatif
  $arr = json_decode(json_encode($resp), true);

  // 4) Localiser la prediction de façon large
  $prediction = findPredictionRecursive($arr);

  // Mode super-debug si ?debug=1
  if (!empty($_GET['debug'])) {
    $paths = listPaths($arr);
    $predPaths = array_values(array_filter($paths, fn($p) => stripos($p, 'prediction') !== false));

    echo json_encode([
      'has_prediction' => (bool)$prediction,
      'prediction_keys' => $prediction ? array_keys($prediction) : [],
      'prediction_sample' => $prediction ?: null,
      'all_paths_count' => count($paths),
      'paths_with_prediction' => $predPaths,
      'hint' => 'Si prediction_keys est vide, vérifie MINDEE_MODEL_ID (doit être le même que dans Live Test).',
    ]);
    exit;
  }

  if (!$prediction || !is_array($prediction)) {
    echo json_encode([
      'supplier' => '',
      'dateISO'  => null,
      'total'    => null,
      '_note'    => 'prediction not found',
    ]);
    exit;
  }

  // 5) Extraction MINIMALISTE sur prediction uniquement (pas d’heuristiques OCR libres)
  // supplier
  $supplier = '';
  foreach (['supplier_name','merchant_name','company_name','store_name','retailer_name','supplier','merchant'] as $k) {
    if (isset($prediction[$k])) { $supplier = trim((string) firstText($prediction[$k])); if ($supplier !== '') break; }
  }

  // date
  $dateISO = null;
  foreach (['date','purchase_date'] as $k) {
    if (isset($prediction[$k])) { $dateISO = toISO(firstText($prediction[$k])); if ($dateISO) break; }
  }

  // total
  $total = null;
  foreach (['total_amount','amount_total','total_incl','total_ttc','total','total_amount_value'] as $k) {
    if (isset($prediction[$k])) { $total = toNum(firstText($prediction[$k])); if ($total !== null && $total > 0) break; }
  }
  if ($total === null) {
    $net = null; $tax = null;
    foreach (['total_net','total_excl','amount_net','net_amount'] as $k) if(isset($prediction[$k])) { $net = toNum(firstText($prediction[$k])); if($net!==null) break; }
    foreach (['total_tax','tax_amount','vat_amount','igi_amount','iva_amount'] as $k) if(isset($prediction[$k])) { $tax = toNum(firstText($prediction[$k])); if($tax!==null) break; }
    if ($net !== null && $tax !== null) $total = round($net + $tax, 2);
  }

  echo json_encode(['supplier'=>$supplier ?: '', 'dateISO'=>$dateISO, 'total'=>$total]);
  exit;

} catch (\Throwable $e) {
  http_response_code(502);
  echo json_encode(['error'=>'SDK error', 'message'=>$e->getMessage()]);
  exit;
}
