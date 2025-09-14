<?php
// API minimaliste : POST JSON { "imageBase64": "<base64_sans_prefixe>" }
// Réponse: { supplier, dateISO, total }
// CORS permissif pour GitHub Pages

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Use POST']); exit; }

$maxBody = 12 * 1024 * 1024; // 12 Mo (base64)
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
  http_response_code(413); echo json_encode(['error'=>'Payload too large']); exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body || empty($body['imageBase64']) || strlen($body['imageBase64']) < 1000) {
  http_response_code(400); echo json_encode(['error'=>'imageBase64 required (>= 1000 chars)']); exit;
}

$apiKey = getenv('MINDEE_API_KEY');
if (!$apiKey) { http_response_code(500); echo json_encode(['error'=>'MINDEE_API_KEY not set']); exit; }

function toNum($s){ if($s===null||$s==='')return null; $n=floatval(str_replace([' ', ','], ['', '.'], (string)$s)); return is_finite($n)?round($n,2):null; }
function normalizeDateToISO($s){
  if(!$s) return null; $s=trim((string)$s);
  if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/',$s)) return $s;
  if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/',$s,$m)){
    $y=(int)$m[3]; if($y<100)$y+=2000;
    $d=str_pad($m[1],2,'0',STR_PAD_LEFT); $mo=str_pad($m[2],2,'0',STR_PAD_LEFT);
    return "$y-$mo-$d";
  }
  return null;
}

// ------------- Essai SDK Mindee -------------
require __DIR__ . '/vendor/autoload.php';

try {
  // Certaines versions v2 du SDK exposent ces classes/namespaces :
  if (!class_exists('\\Mindee\\Client')) throw new \RuntimeException('Mindee SDK not available');

  $client = new \Mindee\Client($apiKey);

  // Donne un data URI (le SDK l’accepte)
  $dataUri = 'data:image/jpeg;base64,' . $body['imageBase64'];
  $input = \Mindee\Inputs\Base64Input::fromString($dataUri);

  // Produit “Receipt v5” (pré-entraîné)
  if (!class_exists('\\Mindee\\Product\\Receipt\\ReceiptV5')) {
    throw new \RuntimeException('ReceiptV5 class not found in this SDK version');
  }
  $resp = $client->parse(\Mindee\Product\Receipt\ReceiptV5::class, $input);
  $pred = $resp->document->inference->prediction ?? [];

  // Helpers de lecture
  $get = function($obj, $keys){
    foreach ($keys as $k) {
      if (is_array($obj) && isset($obj[$k])) return $obj[$k];
      if (is_object($obj) && isset($obj->$k)) return $obj->$k;
    }
    return null;
  };
  $toText = function($v){
    if (is_array($v)) { $v0 = $v[0] ?? null; if (is_array($v0)) return $v0['content'] ?? $v0['text'] ?? $v0['value'] ?? null; return $v0; }
    if (is_object($v)) return $v->content ?? $v->text ?? $v->value ?? null;
    return $v;
  };

  $supplier = $toText($get($pred, ['supplier_name'])) ?: $toText($get($pred, ['merchant_name'])) ?: '';
  $dateRaw  = $toText($get($pred, ['date'])) ?: $toText($get($pred, ['purchase_date'])) ?: null;
  $totalRaw = $toText($get($pred, ['total_amount'])) ?: $toText($get($pred, ['amount_total'])) ?: null;

  echo json_encode([
    'supplier' => $supplier,
    'dateISO'  => normalizeDateToISO($dateRaw),
    'total'    => toNum($totalRaw)
  ]);
  exit;

} catch (\Throwable $e) {
  // ------------- Fallback REST brut -------------
  $url = 'https://api.mindee.net/v1/products/mindee/receipt/v5/predict';
  $payload = json_encode(['document'=>['type'=>'base64','value'=>$body['imageBase64']]]);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: Token ' . $apiKey,
      'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => $payload,
  ]);
  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($resp === false || $http >= 400) {
    http_response_code(502);
    echo json_encode(['error'=>'Mindee REST error', 'http'=>$http, 'curl'=>$err, 'resp'=>$resp]);
    exit;
  }

  $data = json_decode($resp, true);
  $pred = $data['documents'][0]['inference']['prediction'] ?? [];

  $supplier = $pred['supplier_name']['content'] ?? $pred['merchant_name']['content'] ?? '';
  $dateRaw  = $pred['date']['value'] ?? $pred['purchase_date']['value'] ?? null;
  $totalRaw = $pred['total_amount']['value'] ?? $pred['amount_total']['value'] ?? null;

  echo json_encode([
    'supplier' => $supplier,
    'dateISO'  => normalizeDateToISO($dateRaw),
    'total'    => toNum($totalRaw)
  ]);
  exit;
}
