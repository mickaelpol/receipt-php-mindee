<?php
/**
 * Receipt Parser API (Mindee official expense_receipts/v5)
 * POST { imageBase64 } -> { supplier, dateISO, total }
 */

require __DIR__ . '/vendor/autoload.php';

use Mindee\Client;
use Mindee\Input\PathInput;
use Mindee\Input\InferenceParameters;

// getenv('MINDEE_API_KEY') 
// getenv('MINDEE_MODEL_ID') 

$mindeeClient = new Client(getenv('MINDEE_API_KEY'));
return json_encode(['test' => 'ok']);
