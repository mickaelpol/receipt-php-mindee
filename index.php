<?php
// API: POST { imageBase64 } -> { supplier, dateISO, total }  (CORS on)

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (($_SERVER['REQUEST_URI'] ?? '/') === '/ping') { echo json_encode(['ok'=>true]); exit; }
  http_response_code(405); echo json_encode(['error'=>'Use POST']); exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body || empty($body['imageBase64']) || strlen($body['imageBase64']) < 100) {
  http_response_code(400); echo json_encode(['error'=>'imageBase64 required']); exit;
}

$apiKey  = getenv('MINDEE_API_KEY') ?: '';
$modelId = getenv('MINDEE_MODEL_ID') ?: '';  // <<<<<< IMPORTANT
if (!$apiKey || !$modelId) {
  http_response_code(500);
  echo json_encode(['error'=>'MINDEE_API_KEY or MINDEE_MODEL_ID not set']); exit;
}

// ---------- helpers ----------
function toNum($s){ if($s===null||$s==='')return null; $n=floatval(str_replace([' ', ','], ['', '.'], (string)$s)); return is_finite($n)?round($n,2):null; }
function toISO($s){
  if(!$s) return null; $s=trim((string)$s);
  if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/',$s)) return $s;
  if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/',$s,$m)){
    $y=(int)$m[3]; if($y<100)$y+=2000;
    return sprintf('%04d-%02d-%02d',$y,$m[2],$m[1]);
  }
  return null;
}
function firstText($v){
  if (is_array($v)) { $v0 = $v[0] ?? null; if (is_array($v0)) return $v0['content'] ?? $v0['text'] ?? $v0['value'] ?? null; return $v0; }
  if (is_object($v)) return $v->content ?? $v->text ?? $v->value ?? null;
  return $v;
}

require __DIR__ . '/vendor/autoload.php';

use Mindee\ClientV2;
use Mindee\Input\Base64Input;
use Mindee\Input\InferenceParameters;

try {
  // Client V2 + modelId (fourni par ta page “API docs” du modèle)
  $client = new ClientV2($apiKey);
  $params = new InferenceParameters($modelId);

  $dataUri = 'data:image/jpeg;base64,' . $body['imageBase64'];
  $input   = Base64Input::fromString($dataUri);

  $response = $client->enqueueAndGetInference($input, $params);

  // Selon la version, la forme est: $response->inference->prediction
  $pred = $response->inference->prediction ?? [];

  // Champs possibles (diffèrent un peu selon versions)
  $supplier = firstText($pred['supplier_name'] ?? null)
           ?: firstText($pred['merchant_name'] ?? null) ?: '';
  $dateRaw  = firstText($pred['date'] ?? null)
           ?: firstText($pred['purchase_date'] ?? null) ?: null;
  $totalRaw = firstText($pred['total_amount'] ?? null)
           ?: firstText($pred['amount_total'] ?? null) ?: null;

  echo json_encode([
    'supplier' => $supplier,
    'dateISO'  => toISO($dateRaw),
    'total'    => toNum($totalRaw),
    // 'debug' => $response, // décommenter au besoin
  ]);
  exit;

} catch (\Throwable $e) {
  // Pour diagnostiquer si ça casse avant d’appeler Mindee (clé/modelId manquants, etc.)
  http_response_code(502);
  echo json_encode(['error'=>'SDK error', 'message'=>$e->getMessage()]);
  exit;
}
