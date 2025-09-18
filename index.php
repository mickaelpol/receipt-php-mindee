<?php
declare(strict_types=1);

/**
 * Backend Mindee V2 sans SDK — spécial front GitHub Pages
 * Reçoit : POST JSON { imageBase64 }  OU  multipart "document"
 * Renvoie : { ok, supplier, dateISO, total }
 *
 * ENV (Render) :
 *   MINDEE_API_KEY         = md_xxx            (clé brute, sans "Token"/"Bearer")
 *   MODEL_ID               = uuid du modèle
 *   ALLOWED_ORIGINS        = https://<user>.github.io[,https://autre-domaine.tld]
 *   PUBLIC_RECEIPT_API_URL = https://receipt-php-mindee.onrender.com/index.php
 *   (optionnel) PUBLIC_BASE_URL = https://receipt-php-mindee.onrender.com
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

/* ---------- CORS ---------- */
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = getenv('ALLOWED_ORIGINS') ?: '*';
$allow   = '*';
if ($allowed !== '*') {
    $list = array_map('trim', explode(',', $allowed));
    if ($origin && in_array($origin, $list, true)) $allow = $origin;
    else $allow = $list[0] ?? '*';
}
header('Access-Control-Allow-Origin: '.$allow);
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); // <-- ajoute GET
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Google-Access-Token');
header('Access-Control-Max-Age: 86400');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = $_SERVER['REQUEST_URI'] ?? '/';

// Préflight
if ($method === 'OPTIONS') { http_response_code(204); exit; }

/* ---------- ENDPOINT CONFIG (GET) ----------
   Renvoie l'URL publique de l'API (depuis PUBLIC_RECEIPT_API_URL si dispo)
   Répond à :
   - /index.php?config=1
   - /config  ou /config.php (si ton router pointe sur index.php)
------------------------------------------------ */
if ($method === 'GET' && (isset($_GET['config']) || preg_match('~/config(\.php)?($|\?)~', $uri))) {
    header('Content-Type: application/json; charset=utf-8');

    // 1) On privilégie la variable d'env fournie
    $apiUrl = getenv('PUBLIC_RECEIPT_API_URL');

    // 2) Fallback auto si jamais absente
    if (!$apiUrl) {
        $forwardProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
        $httpsOn      = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $forwardProto ?: ($httpsOn ? 'https' : 'http');
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = getenv('PUBLIC_BASE_URL') ?: ($scheme.'://'.$host);
        $apiUrl  = rtrim($baseUrl, '/').'/index.php';
    }

    echo json_encode([
        'ok'              => true,
        'receipt_api_url' => $apiUrl,
        'ts'              => time(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

/* ---------- ENV ---------- */
$API_KEY  = getenv('MINDEE_API_KEY') ?: '';
$MODEL_ID = getenv('MODEL_ID') ?: '';

if ($API_KEY === '' || $MODEL_ID === '') {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'MINDEE_API_KEY ou MODEL_ID manquant.']);
    exit;
}

/* ---------- HTTP helper ---------- */
function http_req(string $method, string $url, array $headers = [], $body = null, bool $multipart = false): array {
    $ch = curl_init($url);
    $hdrs = [];
    foreach ($headers as $k => $v) $hdrs[] = $k . ': ' . $v;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($method !== 'GET') {
        if ($multipart && is_array($body)) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        else curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$body);
    }
    $resp = curl_exec($ch);
    if ($resp === false) { $err = curl_error($ch); curl_close($ch); return ['status'=>0,'headers'=>[], 'body'=>null,'error'=>$err]; }
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdr_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $raw_headers = substr($resp, 0, $hdr_size);
    $body_str    = substr($resp, $hdr_size);
    $headers_out = [];
    foreach (explode("\r\n", $raw_headers) as $line) {
        if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $headers_out[strtolower(trim($k))] = trim($v); }
    }
    return ['status'=>$status, 'headers'=>$headers_out, 'body'=>$body_str, 'error'=>null];
}

/* ---------- récupérer l'image (JSON base64 OU multipart) ---------- */
function save_base64_to_tmp(string $b64, string $ext='.jpg'): string {
    $raw = preg_replace('#^data:[^;]+;base64,#', '', $b64);
    $bin = base64_decode($raw, true);
    if ($bin === false) throw new RuntimeException('Base64 invalide');
    $path = sys_get_temp_dir().'/mindee_'.bin2hex(random_bytes(6)).$ext;
    file_put_contents($path, $bin);
    return $path;
}

// Refuser tout sauf POST pour l'analyse
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Utilisez POST avec JSON {imageBase64} ou multipart "document".']);
    exit;
}

$tmpPath = null;
$ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

try {
    if (stripos($ct, 'application/json') !== false) {
        $raw  = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        $b64  = $json['imageBase64'] ?? '';
        if (!$b64) throw new RuntimeException('Champ "imageBase64" manquant dans le JSON.');
        // si possible, essaie de déduire l’extension
        $ext = '.jpg';
        if (preg_match('#^data:image/(\w+)#', $b64, $m)) $ext='.'.strtolower($m[1]);
        $tmpPath = save_base64_to_tmp($b64, $ext);
    } elseif (!empty($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['document']['tmp_name'];
        $ext = pathinfo($_FILES['document']['name'] ?? '', PATHINFO_EXTENSION);
        $dst = sys_get_temp_dir().'/mindee_'.bin2hex(random_bytes(6)).($ext?".$ext":'');
        @move_uploaded_file($tmp, $dst) || @copy($tmp, $dst);
        $tmpPath = $dst;
    } else {
        throw new RuntimeException('Aucun "imageBase64" ni fichier "document" reçu.');
    }

    /* ---------- 1) ENQUEUE ---------- */
    $enqueueUrl = 'https://api-v2.mindee.net/v2/inferences/enqueue';
    $mime = @mime_content_type($tmpPath) ?: 'application/octet-stream';
    $curlFile = new CURLFile($tmpPath, $mime, basename($tmpPath));
    $enqHeaders = ['Authorization'=>$API_KEY, 'Accept'=>'application/json'];
    $enqBody = ['model_id'=>$MODEL_ID, 'file'=>$curlFile];

    $enq = http_req('POST', $enqueueUrl, $enqHeaders, $enqBody, true);
    if ($enq['status'] < 200 || $enq['status'] >= 300) {
        http_response_code(502);
        echo json_encode(['ok'=>false,'step'=>'enqueue','status'=>$enq['status'],'body'=>$enq['body']]);
        exit;
    }
    $enqJson = json_decode($enq['body'], true);
    $job     = $enqJson['job'] ?? null;
    if (!$job) { http_response_code(502); echo json_encode(['ok'=>false,'step'=>'enqueue','error'=>'job manquant']); exit; }

    $pollingUrl = $job['polling_url'] ?? ($job['id'] ? "https://api-v2.mindee.net/v2/jobs/{$job['id']}" : null);
    $resultUrl  = $job['result_url'] ?? null;

    /* ---------- 2) POLL JOB ---------- */
    $pollHeaders = ['Authorization'=>$API_KEY, 'Accept'=>'application/json'];
    $max = 60; $delay = 1;
    for ($i=0; $i<$max && !$resultUrl; $i++) {
        $poll = http_req('GET', $pollingUrl, $pollHeaders, null, false);
        if ($poll['status'] === 302) {
            $loc = $poll['headers']['location'] ?? '';
            if ($loc) { $resultUrl = $loc; break; }
        }
        $pj = json_decode($poll['body'] ?? '', true);
        $st = $pj['job']['status'] ?? '';
        if ($st === 'Processed') { $resultUrl = $pj['job']['result_url'] ?? $resultUrl; break; }
        if ($st === 'Failed') { http_response_code(502); echo json_encode(['ok'=>false,'step'=>'poll','status'=>'Failed','job'=>$pj['job']]); exit; }
        sleep($delay);
    }
    if (!$resultUrl) { http_response_code(504); echo json_encode(['ok'=>false,'step'=>'poll','error'=>'timeout']); exit; }

    /* ---------- 3) GET INFERENCE ---------- */
    $infHeaders = ['Authorization'=>$API_KEY, 'Accept'=>'application/json'];
    $inf = http_req('GET', $resultUrl, $infHeaders, null, false);
    if ($inf['status'] < 200 || $inf['status'] >= 300) {
        http_response_code(502);
        echo json_encode(['ok'=>false,'step'=>'inference','status'=>$inf['status'],'body'=>$inf['body']]);
        exit;
    }
    $infJson   = json_decode($inf['body'], true);
    $inference = $infJson['inference'] ?? $infJson;
    $fields    = $inference['result']['fields'] ?? [];

    // --- extraction robuste des 3 champs (snake/camel, avec .value) ---
    $supplier = $fields['supplier_name']['value'] ?? $fields['supplierName']['value'] ?? null;
    $dateISO  = $fields['date']['value']          ?? $fields['Date']['value']         ?? null;

    // possible variantes: total_amount / totalAmount / total_price / totalPrice
    $totalRaw = $fields['total_amount']['value']  ?? $fields['totalAmount']['value']
        ?? $fields['total_price']['value']   ?? $fields['totalPrice']['value']   ?? null;

    // Si total absent, tentative fallback: total_net + total_tax
    if ($totalRaw === null) {
        $net = $fields['total_net']['value'] ?? $fields['totalNet']['value'] ?? null;
        $tax = $fields['total_tax']['value'] ?? $fields['totalTax']['value'] ?? null;
        if (is_numeric($net) && is_numeric($tax)) $totalRaw = (float)$net + (float)$tax;
    }

    // Normalisation
    if (is_string($totalRaw)) $totalRaw = str_replace(',', '.', $totalRaw);
    $total = is_numeric($totalRaw) ? (float)$totalRaw : null;

    // Réponse minimaliste pour le front
    echo json_encode([
        'ok'       => true,
        'supplier' => $supplier,           // string | null
        'dateISO'  => $dateISO,            // "YYYY-MM-DD" | null
        'total'    => $total               // float | null (pas d’arrondi ici)
    ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
} finally {
    if (isset($tmpPath) && $tmpPath && is_file($tmpPath)) @unlink($tmpPath);
}
