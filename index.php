<?php
/**
 * index.php — API JSON pour analyser un ticket (Expense Receipt) avec le SDK PHP Mindee.
 * - Dépendances : composer require mindee/mindee
 * - Auth conseillée : variable d'env MINDEE_API_KEY=md_xxx
 * - Fallbacks acceptés (facultatifs) : 
 *      Authorization: Token md_xxx   |   X-Api-Key: md_xxx   |   champ POST api_key=md_xxx
 * - Requête attendue : POST multipart avec champ fichier "document"
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

/** 1) Récupération de la clé API */
$apiKey = getenv('MINDEE_API_KEY') ?: '';
if ($apiKey === '') {
    // Fallback 1: Authorization: Token md_xxx
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($auth, 'Token ') === 0) {
        $apiKey = trim(substr($auth, 6));
    }
}
if ($apiKey === '') {
    // Fallback 2: X-Api-Key: md_xxx  (accepte avec ou sans "Token ")
    $x = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($x !== '') {
        $apiKey = preg_replace('/^Token\s+/i', '', trim($x));
    }
}
if ($apiKey === '') {
    // Fallback 3: champ POST api_key
    $apiKey = isset($_POST['api_key']) ? preg_replace('/^Token\s+/i', '', trim((string)$_POST['api_key'])) : '';
}
if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Clé API manquante. Définis MINDEE_API_KEY ou envoie Authorization/X-Api-Key/api_key.'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
}

/** 2) Vérification fichier */
if (!isset($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Aucun fichier "document" reçu ou erreur d’upload.'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
}

/** 3) Stocker temporairement */
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
    /** 4) Appel SDK Mindee — ReceiptV5 (produit Expense Receipts) */
    $client      = new Client($apiKey);
    $inputSource = $client->sourceFromPath($dstPath);
    $apiResponse = $client->parse(ReceiptV5::class, $inputSource);

    $doc       = $apiResponse->document ?? null;
    $inference = $doc?->inference ?? null;
    $product   = $inference?->product ?? null;
    $pred      = $inference?->prediction ?? null;

    // Normalisation taxes
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

    // Normalisation lignes
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

    // Immatriculations fournisseur
    $regs = [];
    if (is_iterable($pred?->supplierCompanyRegistrations ?? null)) {
        foreach ($pred->supplierCompanyRegistrations as $r) {
            $regs[] = $r->value ?? null;
        }
    }

    $payload = [
        'ok' => true,
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

    if ((string)getenv('APP_DEBUG') === '1') {
        $payload['debug_rst'] = (string)$doc; // rendu texte complet du SDK (pratique pour debug)
    }

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Erreur lors de l’analyse Mindee.',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} finally {
    if (is_file($dstPath)) { @unlink($dstPath); }
}
