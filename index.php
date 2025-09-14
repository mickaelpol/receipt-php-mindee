<?php

if (function_exists('ini_set')) {
  ini_set('display_errors', '0');     // ne pas afficher les erreurs à l’écran
  ini_set('log_errors', '1');         // logguer dans les logs Render
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING); // masque les deprecated/warnings
header('Content-Type: application/json'); // garantit une réponse JSON

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
$modelId = getenv('MINDEE_MODEL_ID') ?: '';
if (!$apiKey || !$modelId) {
  http_response_code(500);
  echo json_encode(['error'=>'MINDEE_API_KEY or MINDEE_MODEL_ID not set']); exit;
}

require __DIR__ . '/vendor/autoload.php';

use Mindee\ClientV2;
use Mindee\Input\PathInput;
use Mindee\Input\InferenceParameters;

// ------- helpers -------
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

try {
  // 1) Écrire l'image base64 dans un fichier temporaire
  $tmp = tempnam(sys_get_temp_dir(), 'rcpt_');
  // Essayons d'inférer une extension JPEG pour éviter quelques parsers tatillons
  $tmpJpg = $tmp . '.jpg';
  file_put_contents($tmpJpg, base64_decode($body['imageBase64']));

  // 2) Client V2 + modelId
  $client = new ClientV2($apiKey);
  $params = new InferenceParameters($modelId);

  // 3) Input via PathInput (compat 100%)
  $input  = new PathInput($tmpJpg);

  // 4) Lancer l'inférence
  $response = $client->enqueueAndGetInference($input, $params);

  // (nettoyage fichier temp)
  @unlink($tmpJpg);
  @unlink($tmp);

  // 5) Extraire les champs
  $pred = $response->inference->prediction ?? [];

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
  ]);
  exit;

} catch (\Throwable $e) {
  http_response_code(502);
  echo json_encode(['error'=>'SDK error', 'message'=>$e->getMessage()]);
  exit;
}
