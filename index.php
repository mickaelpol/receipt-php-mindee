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
function flattenAssoc(array $a, string $prefix=''): array {
  $out=[];
  foreach ($a as $k=>$v){
    $path = $prefix==='' ? (string)$k : $prefix.'.'.$k;
    if (is_array($v)) $out += flattenAssoc($v,$path);
    else $out[$path] = $v;
  }
  return $out;
}
function uniqueKeepOrder(array $arr){ $seen=[]; $out=[]; foreach($arr as $x){ $k=md5((string)$x); if(isset($seen[$k])) continue; $seen[$k]=1; $out[]=$x; } return $out; }
function firstText($v){
  if (is_array($v)) { $v0 = $v[0] ?? null; if (is_array($v0)) return $v0['content'] ?? $v0['text'] ?? $v0['value'] ?? null; return $v0; }
  if (is_object($v)) return $v->content ?? $v->text ?? $v->value ?? null;
  return $v;
}

try {
  function uniqueKeepOrder(array $arr){ $seen=[]; $out=[]; foreach($arr as $x){ $k=md5((string)$x); if(isset($seen[$k])) continue; $seen[$k]=1; $out[]=$x; } return $out; }

// convertir l'objet réponse en tableau associatif
$arr   = json_decode(json_encode($response), true);
$flat  = flattenAssoc($arr);

// candidates
$suppliers = [];
$dates     = [];
$amounts   = [];

// heuristique : on scanne toutes les clés/valeurs
foreach ($flat as $path => $val) {
  if ($val === null || $val === '') continue;

  // supplier / merchant / company / store …
  if (preg_match('/supplier|merchant|store|vendor|company|retailer|name/i', $path)) {
    if (is_string($val)) {
      $txt = trim($val);
      if (mb_strlen($txt) >= 3 && !preg_match('/^\d+$/', $txt)) {
        $suppliers[] = $txt;
      }
    }
  }

  // dates : 2025-08-07 ou 07/08/2025 etc.
  if (is_string($val)) {
    if (preg_match('/\b(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4})\b/', $val, $m)) {
      $dates[] = $m[1];
    }
  }

  // montants : nombre avec décimales, ou numérique sous clés "total"/"amount"
  if (is_string($val)) {
    if (preg_match('/(\d[\d\s]{0,3}(?:\s?\d{3})*[.,]\d{2})\b/', $val, $m)) {
      $n = toNum($m[1]);
      if ($n !== null && $n > 0) $amounts[] = $n;
    }
  } elseif (is_numeric($val)) {
    if (preg_match('/total|amount|sum|grand/i', $path)) {
      $n = toNum($val);
      if ($n !== null && $n > 0) $amounts[] = $n;
    }
  }
}

// dédoublonner
$suppliers = uniqueKeepOrder($suppliers);
$dates     = uniqueKeepOrder($dates);
$amounts   = uniqueKeepOrder($amounts);

// choisir les meilleurs candidats
$supplier = $suppliers[0] ?? '';

// date : première date valable
$dateISO  = null;
foreach ($dates as $d) { $dt = toISO($d); if ($dt) { $dateISO = $dt; break; } }

// total : on prend le plus grand plausible (souvent "total à payer")
$total    = null;
if (!empty($amounts)) {
  // bornes de sécurité pour éviter des numéros de carte capturés par erreur
  $plausible = array_values(array_filter($amounts, fn($x) => $x > 0.2 && $x < 20000));
  if (!empty($plausible)) $total = max($plausible);
}

// fallback : si tu veux tenter les clés "directes" d'abord (si ton modèle les expose)
// (décommente si besoin)
// $pred = $arr['inference']['prediction'] ?? $arr['document']['inference']['prediction'] ?? [];
// $supplier = $supplier ?: ($pred['supplier_name']['value'] ?? $pred['merchant_name']['value'] ?? '');
// $dateISO  = $dateISO  ?: toISO($pred['date']['value'] ?? $pred['purchase_date']['value'] ?? null);
// $total    = $total    ?: toNum($pred['total_amount']['value'] ?? $pred['amount_total']['value'] ?? null);

// retourner au client
echo json_encode([
  'supplier' => $supplier,
  'dateISO'  => $dateISO,
  'total'    => $total
]);
exit;

} catch (\Throwable $e) {
  http_response_code(502);
  echo json_encode(['error'=>'SDK error', 'message'=>$e->getMessage()]);
  exit;
}
