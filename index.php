<?php
/**
 * index.php — API JSON pour analyser un ticket de caisse avec Mindee (ReceiptV5)
 *
 * Requête attendue (exemple):
 *   curl -X POST http://localhost/index.php \
 *     -H "Accept: application/json" \
 *     -F "document=@/chemin/vers/ticket.jpg"
 *
 * Réponse: JSON
 */

declare(strict_types=1);

// --- Réglages d'erreur (on journalise, on n'affiche pas en prod)
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

// Refuse tout sauf POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'Méthode non autorisée. Utilisez POST avec un fichier "document".'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/vendor/autoload.php';

use Mindee\Client;
use Mindee\Product\Receipt\ReceiptV5;

// Clé API depuis l’environnement
$apiKey = getenv('MINDEE_API_KEY') ?: '';
// fallback temporaire : header "X-Api-Key" ou champ POST "api_key"
if ($apiKey === '') {
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? ($_POST['api_key'] ?? '');
}

// Vérif upload
if (!isset($_FILES['document']) || !is_array($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Aucun fichier "document" reçu ou erreur d’upload.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Stockage temporaire
$srcTmp  = $_FILES['document']['tmp_name'];
$ext     = pathinfo($_FILES['document']['name'] ?? '', PATHINFO_EXTENSION);
$dstPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . ('mindee_receipt_' . bin2hex(random_bytes(6)) . ($ext ? ('.' . $ext) : ''));

// move_uploaded_file peut échouer selon la conf; tentez copy() en secours
if (!@move_uploaded_file($srcTmp, $dstPath)) {
    if (!@copy($srcTmp, $dstPath)) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Impossible de stocker temporairement le fichier.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    // Client Mindee
    $client = new Client($apiKey);

    // Source depuis le fichier local
    $inputSource = $client->sourceFromPath($dstPath);

    // Lancement de la prédiction sur le modèle Receipt V5 (tickets de caisse)
    $apiResponse = $client->parse(ReceiptV5::class, $inputSource);

    // Raccourcis
    $doc        = $apiResponse->document ?? null;
    $inference  = $doc?->inference ?? null;
    $product    = $inference?->product ?? null;
    $pred       = $inference?->prediction ?? null;

    // Normalisation "taxes"
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

    // Normalisation "line_items"
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

    // Normalisation "registrations" fournisseur
    $regs = [];
    if (is_iterable($pred?->supplierCompanyRegistrations ?? null)) {
        foreach ($pred->supplierCompanyRegistrations as $r) {
            $regs[] = $r->value ?? null;
        }
    }

    $payload = [
        'ok' => true,
        'mindee' => [
            'product'   => $product->name    ?? 'expense_receipts',
            'version'   => $product->version ?? 'v5',
            'document_id' => $doc->id        ?? null,
            'filename'  => $doc->name        ?? basename($dstPath),
        ],
        'receipt' => [
            'category'        => $pred?->category?->value        ?? null,
            'subcategory'     => $pred?->subcategory?->value     ?? null,
            'document_type'   => $pred?->documentType?->value    ?? null,
            'locale'          => $pred?->locale?->value          ?? null,
            'date'            => $pred?->date?->value            ?? null, // "YYYY-MM-DD"
            'time'            => $pred?->time?->value            ?? null, // "HH:MM"
            'total_amount'    => $pred?->totalAmount?->value     ?? null,
            'total_net'       => $pred?->totalNet?->value        ?? null,
            'total_tax'       => $pred?->totalTax?->value        ?? null,
            'tip'             => $pred?->tip?->value             ?? null,
            'receipt_number'  => $pred?->receiptNumber?->value   ?? null,
            'supplier' => [
                'name'           => $pred?->supplierName?->value         ?? null,
                'address'        => $pred?->supplierAddress?->value      ?? null,
                'phone'          => $pred?->supplierPhoneNumber?->value  ?? null,
                'registrations'  => $regs,
            ],
            'taxes'      => $taxes,
            'line_items' => $lineItems,
        ],
    ];

    // Optionnel: inclure le rendu texte Mindee si APP_DEBUG=1 (pratique pour debug)
    if ((string)getenv('APP_DEBUG') === '1') {
        $payload['debug_rst'] = (string)$doc;
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
    // Nettoyage
    if (is_file($dstPath)) {
        @unlink($dstPath);
    }
}
