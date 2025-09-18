<?php
declare(strict_types=1);

/**
 * /config.php : renvoie l'URL publique de l'API pour le front.
 * ENV attendues (Render/Local):
 *   ALLOWED_ORIGINS        = https://<user>.github.io[,https://autre]
 *   PUBLIC_RECEIPT_API_URL = https://receipt-php-mindee.onrender.com/index.php
 * (optionnel)
 *   PUBLIC_BASE_URL        = https://receipt-php-mindee.onrender.com
 */

error_reporting(0);

// CORS
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = getenv('ALLOWED_ORIGINS') ?: '*';
$allow = '*';
if ($allowed !== '*') {
    $list = array_map('trim', explode(',', $allowed));
    if ($origin && in_array($origin, $list, true)) $allow = $origin;
    else $allow = $list[0] ?? '*';
}
header('Access-Control-Allow-Origin: '.$allow);
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Construit l'URL par défaut si var d'env absente
$forwardProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
$httpsOn      = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$scheme = $forwardProto ?: ($httpsOn ? 'https' : 'http');
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

$baseUrl  = getenv('PUBLIC_BASE_URL') ?: ($scheme.'://'.$host);
$apiUrl   = getenv('PUBLIC_RECEIPT_API_URL') ?: rtrim($baseUrl, '/').'/index.php';

echo json_encode([
    'ok' => true,
    'receipt_api_url' => $apiUrl,
    'ts' => time()
], JSON_UNESCAPED_UNICODE);
