<?php
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

$apiKey = getenv('MINDEE_API_KEY');
if (!$apiKey) { http_response_code(500); echo json_encode(['error'=>'MINDEE_API_KEY not set']); exit; }

require __DIR__ . '/vendor/autoload.php';

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

try {
  // SDK Mindee (v2)
  $client = new \Mindee\Client($apiKey);
  $dataUri = 'data:image/jpeg;base64,' . $body['imageBase64'];
  $input = \Mindee\Inputs\Base64Input::fromString($dataUri);

  if (!class_exists('\\Mindee\\Product\\Receipt\\ReceiptV5')) throw new \RuntimeException('ReceiptV5 class not found');
  $resp = $client->parse(\Mindee\Product\Receipt\ReceiptV5::class, $input);

  $pred = $resp->document->inference->prediction ?? [];
  $supplier = $pred['supplier_name']['value'] ?? $pred['supplier_name']['content'] ?? $pred['merchant_name']['value'] ?? $pred['merchant_name']['content'] ?? '';
  $dateRaw  = $pred['date']['value'] ?? $pred['purchase_date']['value'] ?? null;
  $totalRaw = $pred['total_amount']['value'] ?? $pred['amount_total']['value'] ?? null;

  echo json_encode(['supplier'=>$supplier, 'dateISO'=>toISO($dateRaw), 'total'=>toNum($totalRaw)]);
  exit;

} catch (\Throwable $e) {
  // Fallback REST brut + transparence d’erreur
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

  echo json_encode(['supplier'=>$supplier, 'dateISO'=>toISO($dateRaw), 'total'=>toNum($totalRaw)]);
  exit;
}
