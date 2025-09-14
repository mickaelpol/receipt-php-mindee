<?php
/** -------------------------------------------------------------
 *  Receipt Parser API (Render)
 *  POST { imageBase64 }  -> { supplier, dateISO, total }
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

// Mindee SDK
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
    $y=(int)$m[3]; if($y<100)$y+=2000;
    return sprintf('%04d-%02d-%02d',$y,$m[2],$m[1]);
  }
  return null;
}
function flattenAssoc(array $a, string $prefix=''): array {
  $out=[]; foreach ($a as $k=>$v){
    $path = $prefix===''?(string)$k:$prefix.'.'.$k;
    if (is_array($v)) $out += flattenAssoc($v,$path);
    else $out[$path] = $v;
  } return $out;
}
function uniqueKeepOrder(array $arr){
  $seen=[]; $out=[];
  foreach($arr as $x){ $k=md5((string)$x); if(isset($seen[$k])) continue; $seen[$k]=1; $out[]=$x; }
  return $out;
}
function firstText($v){
  if (is_array($v)) { $v0=$v[0]??null; if (is_array($v0)) return $v0['content']??$v0['text']??$v0['value']??null; return $v0; }
  if (is_object($v)) return $v->content??$v->text??$v->value??null;
  return $v;
}
function isFilenameLike(string $s): bool {
  $s=trim($s); if($s==='') return false;
  if (preg_match('/\.(jpg|jpeg|png|pdf|tif|tiff)$/i',$s)) return true;
  if (preg_match('#[\\\\/]+#',$s)) return true;
  if (preg_match('/^(img|pXL|dsc|rcpt|scan)[_\-]?\d+/i',$s)) return true;
  if (preg_match('/^[a-z0-9_\-]+\.(?:tmp|dat)$/i',$s)) return true;
  return false;
}
function pickSupplierFromList(array $cands): ?string {
  foreach ($cands as $c){
    $t=trim((string)$c);
    if ($t!=='' && mb_strlen($t)>=2 && !preg_match('/^\d+$/',$t) && !isFilenameLike($t)) return $t;
  }
  return null;
}

/** --------- TOTAL logic: classify & score with “base+tax≈total” bonus ---------- */
function classifyContext(string $path, string $ctx): array {
  $p=strtolower($path); $c=strtolower($ctx); $pc=$p.' '.$c;

  $reserve = preg_match('/reservat|reserva|pre.?aut|pre.?autor|preauth|pre.?authorization|cauci[oó]n|dep[oó]sito|bloqueo|reservation/', $pc);
  $supply  = preg_match('/suminist|suministro|combustible|fuel/', $pc);  // “Total suminist.”
  $toPay   = preg_match('/a\s*pagar|à\s*payer|importe\s+total|total\s*cb|total\s*ttc|paid|payment|to\s*pay|net\s*(?:to|à)?\s*payer/', $pc);
  $generic = preg_match('/\btotal\b|\bamount\b|\bsum\b|\bgrand\b|ttc|pay/i', $pc);

  $base    = preg_match('/b\.?\s*imp|base\s*imp|base\s+imponible|subtotal|neto|base[^a-z]?$/i', $pc); // 64,77
  $vat     = preg_match('/\b(iva|igi|tva|vat|tax|impuesto|impuestos)\b/i', $pc);                      // 2,91
  $currency= preg_match('/(?:€|\bEUR\b)/i', $pc);

  return [
    'reserve'=>(bool)$reserve,
    'supply'=>(bool)$supply,
    'toPay'=>(bool)$toPay,
    'generic'=>(bool)$generic,
    'base'=>(bool)$base,
    'vat'=>(bool)$vat,
    'currency'=>(bool)$currency,
  ];
}
function scoreAmountCandidate(float $n, string $path, string $ctx): int {
  $f=classifyContext($path,$ctx); $s=0;
  if ($f['supply'])   $s+=10;               // “Total suminist.” → très probable
  if ($f['toPay'])    $s+=8;                // “À payer / A pagar / Total CB…”
  if ($f['currency']) $s+=3;                // “EUR / €” présent
  if ($f['generic'])  $s+=1;
  if ($f['base'])     $s-=6;                // B. Imp / Base imponible
  if ($f['vat'])      $s-=6;                // IGI / IVA / TAX
  if ($f['reserve'])  $s-=12;               // réservations du terminal
  return $s;
}
function pickBestTotal(array $cands): ?float {
  if (empty($cands)) return null;

  // Bonus si “base + VAT ≈ candidat”
  $bases = array_filter($cands, fn($a)=>$a['base'] && !$a['reserve']);
  $vats  = array_filter($cands, fn($a)=>$a['vat']  && !$a['reserve']);
  foreach ($cands as &$c) {
    foreach ($bases as $b) {
      foreach ($vats as $v) {
        $sum = round($b['num'] + $v['num'], 2);
        if (abs($sum - $c['num']) <= 0.02) { $c['score'] += 5; break 2; }
      }
    }
  } unset($c);

  // Préférences par groupes
  $prefSupply = array_filter($cands, fn($a)=>!$a['reserve'] && $a['supply']);
  $prefPay    = array_filter($cands, fn($a)=>!$a['reserve'] && $a['toPay']);
  $nonReserve = array_filter($cands, fn($a)=>!$a['reserve']);

  $cmp=function($a,$b){
    if ($a['score']!==$b['score']) return $b['score']<=>$a['score'];
    $a00 = fmod($a['num'],1.0)===0.0; $b00 = fmod($b['num'],1.0)===0.0;
    if ($a00!==$b00) return $a00<=>$b00;      // 67,68 > 99,00
    return $b['num']<=>$a['num'];
  };

  if (!empty($prefSupply)) { usort($prefSupply,$cmp); return $prefSupply[0]['num']; }
  if (!empty($prefPay))    { usort($prefPay,$cmp);    return $prefPay[0]['num']; }
  if (!empty($nonReserve)) { usort($nonReserve,$cmp); return $nonReserve[0]['num']; }

  usort($cands,$cmp); return $cands[0]['num'];
}

/* ======================
   Main
====================== */
try {
  // 1) Temp file for Mindee
  $tmp    = tempnam(sys_get_temp_dir(), 'rcpt_');
  $tmpJpg = $tmp . '.jpg';
  file_put_contents($tmpJpg, base64_decode($body['imageBase64']));

  // 2) Mindee
  $client = new ClientV2($apiKey);
  $params = new InferenceParameters($modelId);
  $input  = new PathInput($tmpJpg);
  $response = $client->enqueueAndGetInference($input, $params);

  @unlink($tmpJpg); @unlink($tmp);

  // 3) Parsing
  $arr  = json_decode(json_encode($response), true);
  $flat = flattenAssoc($arr);

  // Supplier
  $pred = $arr['inference']['prediction'] ?? ($arr['document']['inference']['prediction'] ?? null);
  $supplierCandidates=[];
  if (is_array($pred)) {
    foreach (['supplier_name','merchant_name','company_name','store_name','retailer_name','supplier','merchant'] as $k) {
      $v = firstText($pred[$k] ?? null);
      if ($v!==null && $v!=='') $supplierCandidates[] = $v;
    }
  }
  if (empty($supplierCandidates)) {
    foreach ($flat as $path=>$val){
      if ($val===null || $val==='') continue;
      if (!preg_match('/supplier|merchant|store|vendor|company|retailer|name/i',$path)) continue;
      if (is_string($val) && !isFilenameLike($val)) $supplierCandidates[]=$val;
    }
  }
  $supplier = pickSupplierFromList($supplierCandidates) ?? '';

  // Dates + Totaux (catégorisé)
  $dates=[]; $amountCands=[];
  foreach ($flat as $path=>$val) {
    if ($val===null || $val==='') continue;

    if (is_string($val)) {
      if (preg_match('/\b(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4})\b/',$val,$m)) $dates[]=$m[1];

      if (preg_match_all('/(\d[\d\s]{0,3}(?:\s?\d{3})*[.,]\d{2})\b/', $val, $mm)) {
        foreach ($mm[1] as $found){
          $n=toNum($found);
          if ($n!==null && $n>0.2 && $n<20000){
            $flags = classifyContext((string)$path,(string)$val);
            $amountCands[]=[
              'num'=>$n, 'path'=>(string)$path, 'ctx'=>(string)$val,
              'score'=>scoreAmountCandidate($n,(string)$path,(string)$val)
            ] + $flags;
          }
        }
      }
    } elseif (is_numeric($val) && preg_match('/total|amount|sum|grand/i',$path)) {
      $n=toNum($val);
      if ($n!==null && $n>0.2 && $n<20000){
        $flags = classifyContext((string)$path,(string)$val);
        $amountCands[]=[
          'num'=>$n,'path'=>(string)$path,'ctx'=>(string)$val,
          'score'=>scoreAmountCandidate($n,(string)$path,(string)$val)
        ] + $flags;
      }
    }
  }
  $dates = uniqueKeepOrder($dates);
  $dateISO=null; foreach ($dates as $d){ $dt=toISO($d); if ($dt){ $dateISO=$dt; break; } }

  $total = pickBestTotal($amountCands);

  echo json_encode(['supplier'=>$supplier,'dateISO'=>$dateISO,'total'=>$total]); exit;

} catch (\Throwable $e) {
  http_response_code(502);
  echo json_encode(['error'=>'SDK error','message'=>$e->getMessage()]);
  exit;
}
