<?php
/**
 * index.php — API JSON Mindee (Expense Receipts) avec diagnostics de clé.
 * Dépendance : composer require mindee/mindee
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée. Utilisez POST avec un fichier "document".'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/vendor/autoload.php';

use Mindee\Client;
use Mindee\Product\Receipt\ReceiptV5;

/** Helpers **/
function mask_key(?string $k): string {
    if (!$k) return '';
    $k = (string)$k;
    $len = strlen($k);
    if ($len <= 10) return substr($k, 0, 2) . str_repeat('*', max(0, $len-4)) . substr($k, -2);
    return substr($k, 0, 4) . str_repeat('*', $len - 8) . substr($k, -4);
}
function clean_key(?string $k): string {
    $k = (string)$k;
    // supprime "Token " au début, espaces/retours, guillemets exotiques
    $k = preg_replace('/^\s*Token\s+/i', '', $k);
    $k = str_replace(["\r", "\n", "\t", '’', '“', '”'], '', $k);
    return trim($k);
}

/** 1) Récupération de la clé API (env + fallbacks) **/
$apiKeySource = 'env';
$apiKey = getenv('MINDEE_API_KEY') ?: '';

if ($apiKey === '') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth) {
        $apiKey = $auth;
        $apiKeySource = 'Authorization header';
    }
}
if ($apiKey === '') {
    $x = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($x) {
        $apiKey = $x;
        $apiKeySource = 'X-Api-Key header';
    }
}
if ($apiKey === '') {
    $postKey = $_POST['api_key'] ?? '';
    if ($postKey) {
        $apiKey = $postKey;
        $apiKeySource = 'POST field api_key';
    }
}
$apiKey = clean_key($apiKey);

if ($apiKey === '') {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Clé API manquante. Définis MINDEE_API_KEY ou envoie Authorization: Token md_xxx / X-Api-Key: md_xxx / api_key=md_xxx.'
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
}

/** 1.b) Validation rapide du format de clé **/
$formatOk = (bool)preg_match('/^md_[A-Za-z0-9]+$/', $apiKey);
if (!$formatOk) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Format de clé invalide. Elle doit commencer par "md_..." (sans "Token ").',
        'diagnostic' => [
            'source' => $apiKeySource,
            'key_masked' => mask_key($apiKey),
            'hint' => 'Envoie la clé brute (md_...), sans préfixe "Token " et sans espaces/retours.'
        ]
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
}

/** 2) Vérification du fichier **/
if (!isset($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Aucun fichier "document" reçu ou erreur d’upload.'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
}

/** 3) Stockage temporaire **/
$srcTmp  = $_FILES['document']['tmp_name'];
$ext     = pathinfo($_FILES['document']['name'] ?? '', PATHINFO_EXTENSION);
$dstPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . ('mindee_receipt_' . bin2hex(random_bytes(6)) . ($ext ? ('.' . $ext) : ''));
if (!@move_uploaded_file($srcTmp, $dstPath)) {
    if (!@copy($srcTmp, $dstPath)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Impossible de stocker temporairement le fichier.'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    /** 4) Appel SDK Mindee — ReceiptV5 */
    $client      = new Client($apiKey);
    $inputSource = $client->sourceFromPath($dstPath);
    $apiResponse = $client->parse(ReceiptV5::class, $inputSource);

    $doc       = $apiResponse->document ?? null;
    $inference = $doc?->inference ?? null;
    $product   = $inference?->product ?? null;
    $pred      = $inference?->prediction ?? null;

    // Taxes
    $taxes = [];
    if (is_iterable($pred?->taxes ?? null)) {
        foreach ($pred->taxes as $t) {
            $taxes[] = [
                'base'   => $t->base?->value   ?? null,
                'code'   => $t->code?->value   ?? null,
                'rate'   => $t->rate?->value   ?? null,
                'amount' => $t->amount?->value ?? null,
            ];
        }
    }
    // Lignes
    $lineItems = [];
    if (is_iterable($pred?->lineItems ?? null)) {
        foreach ($pred->lineItems as $li) {
            $lineItems[] = [
                'description'  => $li->description   ?? null,
                'quantity'     => $li->quantity      ?? null,
                'unit_price'   => $li->unitPrice     ?? null,
                'total_amount' => $li->totalAmount   ?? null,
            ];
        }
    }
    // Immatriculations
    $regs = [];
    if (is_iterable($pred?->supplierCompanyRegistrations ?? null)) {
        foreach ($pred->supplierCompanyRegistrations as $r) {
            $regs[] = $r->value ?? null;
        }
    }

    $payload = [
        'ok' => true,
        'diagnostic' => [
            'key_source' => $apiKeySource,
            'key_masked' => mask_key($apiKey),
        ],
        'mindee' => [
            'product'     => $product->name    ?? 'expense_receipts',
            'version'     => $product->version ?? 'v5',
            'document_id' => $doc->id          ?? null,
            'filename'    => $doc->name        ?? basename($dstPath),
        ],
        'receipt' => [
            'category'        => $pred?->category?->value        ?? null,
            'subcategory'     => $pred?->subcategory?->value     ?? null,
            'document_type'   => $pred?->documentType?->value    ?? null,
            'locale'          => $pred?->locale?->value          ?? null,
            'date'            => $pred?->date?->value            ?? null,
            'time'            => $pred?->time?->value            ?? null,
            'total_amount'    => $pred?->totalAmount?->value     ?? null,
            'total_net'       => $pred?->totalNet?->value        ?? null,
            'total_tax'       => $pred?->totalTax?->value        ?? null,
            'tip'             => $pred?->tip?->value             ?? null,
            'receipt_number'  => $pred?->receiptNumber?->value   ?? null,
            'supplier' => [
                'name'          => $pred?->supplierName?->value        ?? null,
                'address'       => $pred?->supplierAddress?->value     ?? null,
                'phone'         => $pred?->supplierPhoneNumber?->value ?? null,
                'registrations' => $regs,
            ],
            'taxes'      => $taxes,
            'line_items' => $lineItems,
        ],
    ];

    echo json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'error'   => 'Erreur lors de l’analyse Mindee.',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
} finally {
    if (is_file($dstPath)) { @unlink($dstPath); }
}
