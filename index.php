<?php
/**
 * Minimal Mindee → JSON
 * POST { imageBase64 } -> { supplier, dateISO, total }
 */

if (function_exists('ini_set')) { ini_set('display_errors','0'); ini_set('log_errors','1'); }
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE);
header('Content-Type: application/json');

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (($_SERVER['REQUEST_URI'] ?? '') === '/ping') { echo json_encode(['ok'=>true]); exit; }
  http_response_code(405); echo json_encode(['error'=>'Use POST']); exit;
}

// Payload
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body || empty($body['imageBase64']) || strlen($body['imageBase64']) < 100) {
  http_response_code(400); echo json_encode(['error'=>'imageBase64 required']); exit;
}

// Secrets
$apiKey  = getenv('MINDEE_API_KEY') ?: '';
$modelId = getenv('MINDEE_MODEL_ID') ?: '';
if (!$apiKey || !$modelId) {
  http_response_code(500); echo json_encode(['error'=>'MINDEE_API_KEY or MINDEE_MODEL_ID not set']); exit;
}

// SDK
require __DIR__ . '/vendor/autoload.php';
use Mindee\ClientV2;
use Mindee\Input\PathInput;
use Mindee\Input\InferenceParameters;

/* Helpers (petits et strictement sur les champs structurés) */
function firstText($v){
  if (is_array($v)) { $v0 = $v[0] ?? null; if (is_array($v0)) return $v0['value'] ?? $v0['content'] ?? $v0['text'] ?? null; return $v0; }
  if (is_object($v)) return $v->value ?? $v->content ?? $v->text ?? null;
  return $v;
}
function toNum($v){ if($v===null || $v==='') return null; $n = floatval(str_replace([',',' '], ['.',''], (string)$v)); return is_finite($n) ? round($n,2) : null; }
function toISO($v){
  if(!$v) return null; $s = trim((string)$v);
  if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $s)) return $s;
  if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/', $s, $m)) {
    $y=(int)$m[3]; if($y<100) $y+=2000; return sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]);
  }
  return null;
}

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

  // 3) On ne lit QUE les champs structurés du bloc prediction
  $arr  = json_decode(json_encode($resp), true);
  $pred = $arr['document']['inference']['prediction']
       ?? $arr['inference']['prediction']
       ?? [];

  // supplier (ordre de préférence simple)
  $supplier = null;
  foreach (['supplier_name','merchant_name','company_name','store_name','retailer_name','supplier','merchant'] as $k) {
    if (isset($pred[$k])) { $supplier = trim((string) firstText($pred[$k])); if ($supplier !== '') break; }
  }

  // date (simple)
  $dateISO = null;
  foreach (['date','purchase_date'] as $k) {
    if (isset($pred[$k])) { $dateISO = toISO(firstText($pred[$k])); if ($dateISO) break; }
  }

  // total (uniquement total du modèle)
  $total = null;
  foreach (['total_amount','amount_total','total_incl','total_ttc','total','total_amount_value'] as $k) {
    if (isset($pred[$k])) { $total = toNum(firstText($pred[$k])); if ($total !== null && $total > 0) break; }
  }
  // petite somme net+tax si dispo (structuré aussi)
  if ($total === null) {
    $net = null; $tax = null;
    foreach (['total_net','total_excl','amount_net','net_amount'] as $k) if(isset($pred[$k])) { $net = toNum(firstText($pred[$k])); if($net!==null) break; }
    foreach (['total_tax','tax_amount','vat_amount','igi_amount','iva_amount'] as $k) if(isset($pred[$k])) { $tax = toNum(firstText($pred[$k])); if($tax!==null) break; }
    if ($net !== null && $tax !== null) $total = round($net + $tax, 2);
  }

  // Mode debug: renvoyer aussi toutes les clés du prediction si ?debug=1
  if (!empty($_GET['debug'])) {
    echo json_encode([
      'supplier' => $supplier ?: '',
      'dateISO'  => $dateISO,
      'total'    => $total,
      '_prediction_keys' => array_keys($pred),
      '_prediction' => $pred,
    ]);
    exit;
  }

  echo json_encode(['supplier'=>$supplier ?: '', 'dateISO'=>$dateISO, 'total'=>$total]);
  exit;

} catch (\Throwable $e) {
  http_response_code(502);
  echo json_encode(['error'=>'SDK error', 'message'=>$e->getMessage()]);
  exit;
}
