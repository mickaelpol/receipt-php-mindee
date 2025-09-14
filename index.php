<?php
/**
 * Receipt Parser API (Mindee official expense_receipts/v5)
 * POST { imageBase64 } -> { supplier, dateISO, total }
 */

if (function_exists('ini_set')) {
  ini_set('display_errors', '0');
  ini_set('log_errors', '1');
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
header('Content-Type: application/json');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (($_SERVER['REQUEST_URI'] ?? '') === '/ping') { echo json_encode(['ok'=>true]); exit; }
  http_response_code(405); echo json_encode(['error'=>'Use POST']); exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body || empty($body['imageBase64']) || strlen($body['imageBase64']) < 100) {
  http_response_code(400); echo json_encode(['error'=>'imageBase64 required']); exit;
}

$apiKey  = getenv('MINDEE_API_KEY') ?: '';
$modelId = getenv('MINDEE_MODEL_ID') ?: 'mindee/expense_receipts/v5'; // <= modèle officiel
if (!$apiKey || !$modelId) {
  http_response_code(500);
  echo json_encode(['error'=>'MINDEE_API_KEY or MINDEE_MODEL_ID not set']);
  exit;
}

require __DIR__ . '/vendor/autoload.php';
use Mindee\ClientV2;
use Mindee\Input\PathInput;
use Mindee\Input\InferenceParameters;

function firstVal($v) {
  if (is_array($v)) {
    if (isset($v['value'])) return $v['value'];
    if (isset($v[0])) return is_array($v[0]) ? ($v[0]['value'] ?? null) : $v[0];
  }
  if (is_object($v)) return $v->value ?? null;
  return $v;
}
function toISO($s) {
  if (!$s) return null;
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $s, $m)) {
    return "$m[3]-$m[2]-$m[1]";
  }
  return null;
}
function toNum($s) {
  if ($s === null) return null;
  $n = floatval(str_replace([',',' '], ['.',''], (string)$s));
  return is_finite($n) ? round($n,2) : null;
}

try {
  $tmp = tempnam(sys_get_temp_dir(), 'rcpt_');
  $jpg = $tmp.'.jpg';
  file_put_contents($jpg, base64_decode($body['imageBase64']));

  $client = new ClientV2($apiKey);
  $params = new InferenceParameters($modelId);
  $input  = new PathInput($jpg);
  $resp   = $client->enqueueAndGetInference($input, $params);

  @unlink($jpg); @unlink($tmp);

  $arr = json_decode(json_encode($resp), true);
  $prediction = $arr['document']['inference']['prediction'] ?? null;

  if (!$prediction) {
    echo json_encode(['supplier'=>'', 'dateISO'=>null, 'total'=>null, '_note'=>'prediction not found']);
    exit;
  }

  $supplier = firstVal($prediction['supplier_name'] ?? null) ?: '';
  $dateISO  = toISO(firstVal($prediction['date'] ?? null));
  $total    = toNum(firstVal($prediction['total_amount'] ?? null));

  echo json_encode([
    'supplier' => $supplier,
    'dateISO'  => $dateISO,
    'total'    => $total
  ]);
  exit;

} catch (\Throwable $e) {
  http_response_code(502);
  echo json_encode(['error'=>'SDK error','message'=>$e->getMessage()]);
  exit;
}
