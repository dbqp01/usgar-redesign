<?php
require dirname(__DIR__) . '/vendor/autoload.php';

// Test 1: Adapter instantiation
try {
    $adapter = new App\Features\Shared\Adapters\MercadoPagoAdapter();
    echo "OK: MercadoPagoAdapter instantiated successfully\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: SDK WebhookSignatureValidator class exists
echo "SDK WebhookSignatureValidator: " . (class_exists('MercadoPago\Webhook\WebhookSignatureValidator') ? 'EXISTS' : 'NOT FOUND') . "\n";

// Test 3: verifySignature with bad data (should return false, not crash)
try {
    $result = $adapter->verifySignature('ts=123,v1=abc', 'req-123', 'data-456');
    echo "OK: verifySignature returned " . ($result ? 'true' : 'false') . " (expected false)\n";
} catch (Throwable $e) {
    echo "FAIL: verifySignature threw: " . $e->getMessage() . "\n";
}

// Test 4: verifySignature with correct HMAC
$secret = getenv('MERCADO_PAGO_WEBHOOK_SECRET') ?: 'test-secret-for-sdk-self-test';
$dataId = '171073692716';
$requestId = 'a70c8599-bd35-48e3-b6ea-576ca1c36ba3';
$ts = '1764699137';
$manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
$hash = hash_hmac('sha256', $manifest, $secret);
$signatureHeader = "ts={$ts},v1={$hash}";

echo "\n--- HMAC Self-Test ---\n";
echo "Manifest: {$manifest}\n";
echo "Computed hash: {$hash}\n";
echo "Signature header: {$signatureHeader}\n";

// Now validate using SDK
try {
    MercadoPago\Webhook\WebhookSignatureValidator::validate(
        $signatureHeader,
        $requestId,
        $dataId,
        $secret
    );
    echo "OK: SDK validation PASSED with self-computed HMAC\n";
} catch (MercadoPago\Exceptions\InvalidWebhookSignatureException $e) {
    echo "FAIL: SDK validation FAILED: " . $e->getMessage() . "\n";
}

echo "\nAll tests completed.\n";
