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
  $supply  = preg_match('/suminist|suministro|combustible|fuel/', $pc);
  $toPay   = preg_match('/a\s*pagar|à\s*payer|importe\s+total|total\s*cb|total\s*ttc|paid|payment|to\s*pay|net\s*(?:to|à)?\s*payer/', $pc);
  $generic = preg_match('/\btotal\b|\bamount\b|\bsum\b|\bgrand\b|ttc|pay/i', $pc);

  $base    = preg_match('/b\.?\s*imp|base\s*imp|base\s+imponible|subtotal|neto|base[^a-z]?$/i', $pc);
  $vat     = preg_match('/\b(iva|igi|tva|vat|tax|impuesto|impuestos)\b/i', $pc);
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
  if ($f['supply'])   $s+=10;
  if ($f['toPay'])    $s+=8;
  if ($f['currency']) $s+=3;
  if ($f['generic'])  $s+=1;
  if ($f['base'])     $s-=6;
  if ($f['vat'])      $s-=6;
  if ($f['reserve'])  $s-=12;
  return $s;
}
function pickBestTotal(array $cands): ?float {
  if (empty($cands)) return null;

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

  $prefSupply = array_filter($cands, fn($a)=>!$a['reserve'] && $a['supply']);
  $prefPay    = array_filter($cands, fn($a)=>!$a['reserve'] && $a['toPay']);
  $nonReserve = array_filter($cands, fn($a)=>!$a['reserve']);

  $cmp=function($a,$b){
    if ($a['score']!==$b['score']) return $b['score']<=>$a['score'];
    $a00 = fmod($a['num'],1.0)===0.0; $b00 = fmod($b['num'],1.0)===0.0;
    if ($a00!==$b00) return $a00<=>$b00;      // 67,68 > 99,00 si égalité de score
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

  // Prediction bloc (v2)
  $pred = $arr['document']['inference']['prediction']
       ?? $arr['inference']['prediction']
       ?? null;

  // ---------- supplier ----------
  $supplier = '';
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

  // ---------- date (structurée d'abord) ----------
  $dateISO = null;
  if (is_array($pred)) {
    $dateISO = toISO(firstText($pred['date'] ?? $pred['purchase_date'] ?? null));
  }
  if (!$dateISO) {
    $dates=[];
    foreach ($flat as $path=>$val){
      if (!is_string($val)) continue;
      if (preg_match('/\b(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4})\b/',$val,$m)) $dates[]=$m[1];
    }
    $dates = uniqueKeepOrder($dates);
    foreach ($dates as $d){ $dt=toISO($d); if($dt){ $dateISO=$dt; break; } }
  }

  // ---------- total (structuré d'abord) ----------
  $total = null;
  if (is_array($pred)) {
    // liste large de clés possibles selon modèles / versions
    $totalKeys = [
      'total_amount','amount_total','total_incl','total_ttc','total','total_amount_value'
    ];
    foreach ($totalKeys as $k) {
      if (!isset($pred[$k])) continue;
      $tv = toNum(firstText($pred[$k]));
      if ($tv !== null && $tv>0.2 && $tv<20000) { $total = $tv; break; }
    }
    // si pas de total, mais net+tax présents → somme
    if ($total === null) {
      $netKeys = ['total_net','total_excl','amount_net','net_amount'];
      $taxKeys = ['total_tax','tax_amount','vat_amount','igi_amount','iva_amount'];
      $net=null; $tax=null;
      foreach ($netKeys as $k){ if(isset($pred[$k])){ $net=toNum(firstText($pred[$k])); if($net!==null) break; } }
      foreach ($taxKeys as $k){ if(isset($pred[$k])){ $tax=toNum(firstText($pred[$k])); if($tax!==null) break; } }
      if ($net!==null && $tax!==null) $total = round($net+$tax,2);
    }
  }

  // ---------- fallback heuristique si pas de total structuré ----------
  if ($total === null) {
    $amountCands=[];
    foreach ($flat as $path=>$val) {
      if ($val===null || $val==='') continue;

      if (is_string($val)) {
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
    $total = pickBestTotal($amountCands);
  }

  echo json_encode(['supplier'=>$supplier,'dateISO'=>$dateISO,'total'=>$total]); exit;

} catch (\Throwable $e) {
  http_response_code(502);
  echo json_encode(['error'=>'SDK error','message'=>$e->getMessage()]);
  exit;
}
