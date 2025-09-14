<?php
/** -------------------------------------------------------------
 *  Receipt Parser API (Render)
 *  POST { imageBase64 }  -> { supplier, dateISO, total }
 *  -------------------------------------------------------------
 */

// --- garder la réponse JSON propre (pas d’avertissements à l’écran)
if (function_exists('ini_set')) {
  ini_set('display_errors', '0');
  ini_set('log_errors', '1'); // les erreurs iront dans les logs Render
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
header('Content-Type: application/json');

// --- CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (($_SERVER['REQUEST_URI'] ?? '/') === '/ping') { echo json_encode(['ok' => true]); exit; }
  http_response_code(405); echo json_encode(['error' => 'Use POST']); exit;
}

// --- payload
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body || empty($body['imageBase64']) || strlen($body['imageBase64']) < 100) {
  http_response_code(400); echo json_encode(['error' => 'imageBase64 required']); exit;
}

// --- env vars
$apiKey  = getenv('MINDEE_API_KEY') ?: '';
$modelId = getenv('MINDEE_MODEL_ID') ?: '';
if (!$apiKey || !$modelId) {
  http_response_code(500);
  echo json_encode(['error' => 'MINDEE_API_KEY or MINDEE_MODEL_ID not set']);
  exit;
}

// --- Mindee SDK
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
// recherche récursive dans un tableau associatif
function findFirstByKeyRegex($node, $regex) {
  if (is_array($node)) {
    foreach ($node as $k => $v) {
      if (is_string($k) && preg_match($regex, $k)) {
        if (is_array($v)) {
          if (isset($v['value']))   return $v['value'];
          if (isset($v['content'])) return $v['content'];
          if (isset($v['text']))    return $v['text'];
        }
        if (!is_array($v)) return $v;
      }
      $r = findFirstByKeyRegex($v, $regex);
      if ($r !== null && $r !== '') return $r;
    }
  }
  return null;
}

/* ======================
   Main
====================== */
try {
  // 1) Écrire l’image base64 dans un fichier temporaire
  $tmp    = tempnam(sys_get_temp_dir(), 'rcpt_');
  $tmpJpg = $tmp . '.jpg';
  file_put_contents($tmpJpg, base64_decode($body['imageBase64']));

  // 2) Client Mindee v2
  $client = new ClientV2($apiKey);
  $params = new InferenceParameters($modelId);

  // 3) Input via PathInput
  $input     = new PathInput($tmpJpg);
  $resp      = $client->enqueueAndGetInference($input, $params);

  // Nettoyage fichiers temporaires
  @unlink($tmpJpg);
  @unlink($tmp);

  // 4) On lit UNIQUEMENT la partie "prediction"
  $arr = json_decode(json_encode($resp), true);

  $prediction = $arr['document']['inference']['prediction']
    ?? $arr['inference']['prediction']
    ?? ($arr['document']['inference']['pages'][0]['prediction'] ?? null)
    ?? ($arr['inference']['pages'][0]['prediction'] ?? null)
    ?? null;

  if (!$prediction || !is_array($prediction)) {
    echo json_encode(['supplier'=>'','dateISO'=>null,'total'=>null,'_note'=>'prediction not found']); exit;
  }

  // champs recherchés
  $supplierRaw = findFirstByKeyRegex($prediction, '/^(supplier|merchant|company|retailer|store).*$/i');
  $dateRaw     = findFirstByKeyRegex($prediction, '/^(date|purchase_date)$/i');
  $totalRaw    = findFirstByKeyRegex($prediction, '/^(total(_amount)?|amount_total|total_ttc|total_incl)$/i');

  // fallback net + tax
  if ($totalRaw === null || $totalRaw === '') {
    $netRaw = findFirstByKeyRegex($prediction, '/^(total_net|total_excl|amount_net|net_amount)$/i');
    $taxRaw = findFirstByKeyRegex($prediction, '/^(total_tax|tax_amount|vat_amount|igi_amount|iva_amount)$/i');
    $net = toNum($netRaw); $tax = toNum($taxRaw);
    if ($net !== null && $tax !== null) $totalRaw = $net + $tax;
  }

  $supplier = is_string($supplierRaw) ? trim($supplierRaw) : (string)$supplierRaw;
  $dateISO  = toISO($dateRaw);
  $total    = toNum($totalRaw);

  // Mode debug: afficher clés dispo
  if (!empty($_GET['debug'])) {
    echo json_encode([
      'supplier'=>$supplier,'dateISO'=>$dateISO,'total'=>$total,
      '_keys'=>array_keys($prediction),
      '_sample'=>$prediction
    ]);
    exit;
  }

  // Résultat final minimal
  echo json_encode([
    'supplier'=>$supplier?:'',
    'dateISO'=>$dateISO,
    'total'=>$total
  ]);
  exit;

} catch (\Throwable $e) {
  http_response_code(502);
  echo json_encode(['error'=>'SDK error','message'=>$e->getMessage()]);
  exit;
}
