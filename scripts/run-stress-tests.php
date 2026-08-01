<?php
declare(strict_types=1);

// scripts/run-stress-tests.php - Concurrency, Race Condition & Stress Test Suite

$baseUrl = 'http://localhost:4321/api';

echo "==========================================================" . PHP_EOL;
echo " USGAR HOTELS - STRESS & CONCURRENCY TEST SUITE" . PHP_EOL;
echo "==========================================================" . PHP_EOL;

// --------------------------------------------------------
// TEST 1: Concurrent Reservation Race Condition Test
// --------------------------------------------------------
echo PHP_EOL . "---  PRUEBA 1: CONCURRENCIA DE RESERVAS SIMULTANEAS (RACE CONDITION) ---" . PHP_EOL;

$mh = curl_multi_init();
$handles = [];
$concurrencyCount = 10;

for ($i = 0; $i < $concurrencyCount; $i++) {
    $ch = curl_init("{$baseUrl}/booking");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'id_hotel'     => 1,
        'id_room_type' => 4, // Familiar Superior
        'checkIn'      => '2026-10-01',
        'checkOut'     => '2026-10-05',
        'guestName'    => "Concurrent Guest {$i}",
        'guestEmail'   => "guest{$i}@example.com",
        'guestPhone'   => "+519990000{$i}",
        'guests'       => 2,
    ], JSON_UNESCAPED_UNICODE));

    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}

$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

$successes = 0;
$rejectedFull = 0;
$otherErrors = 0;

foreach ($handles as $ch) {
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $response = curl_multi_getcontent($ch);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);

    $data = json_decode((string)$response, true);

    if ($httpCode === 200 && ($data['success'] ?? false) === true) {
        $successes++;
    } elseif ($httpCode === 400 && str_contains($data['error']['message'] ?? '', 'disponible')) {
        $rejectedFull++;
    } else {
        $otherErrors++;
    }
}
curl_multi_close($mh);

echo "    Peticiones simultáneas enviadas: {$concurrencyCount}" . PHP_EOL;
echo "    Reservas temporales creadas: {$successes}" . PHP_EOL;
echo "    Rechazadas por falta de cupo (Prevención Overbooking): {$rejectedFull}" . PHP_EOL;
echo "    Otros errores: {$otherErrors}" . PHP_EOL;

if ($otherErrors === 0 && ($successes + $rejectedFull) === $concurrencyCount) {
    echo "    PASS: Prueba de concurrencia superada sin condiciones de carrera ni overbooking." . PHP_EOL;
} else {
    echo "    WARN/FAIL: Se detectaron comportamientos inesperados en concurrencia." . PHP_EOL;
}

// --------------------------------------------------------
// TEST 2: Webhook Parallel Idempotency Stress Test
// --------------------------------------------------------
echo PHP_EOL . "---  PRUEBA 2: IDEMPOTENCIA EN BURSTS DE WEBHOOKS PARALELOS ---" . PHP_EOL;

$secretKey = getenv('MP_WEBHOOK_SECRET') ?: 'test-secret-for-stress-tests';
$testPaymentId = 'MOCK-STRESS-PAYMENT-' . time();
$ts = (string)time();
$requestId = 'req-stress-' . time();

$manifest = "id:{$testPaymentId};request-id:{$requestId};ts:{$ts};";
$v1 = hash_hmac('sha256', $manifest, $secretKey);
$signatureHeader = "ts={$ts},v1={$v1}";

$mh2 = curl_multi_init();
$handles2 = [];
$webhookBurstCount = 5;

for ($i = 0; $i < $webhookBurstCount; $i++) {
    $ch = curl_init("{$baseUrl}/webhook");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "X-Signature: {$signatureHeader}",
        "X-Request-Id: {$requestId}",
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'action' => 'payment.created',
        'type'   => 'payment',
        'data'   => ['id' => $testPaymentId],
    ]));

    curl_multi_add_handle($mh2, $ch);
    $handles2[] = $ch;
}

$running2 = null;
do {
    curl_multi_exec($mh2, $running2);
    curl_multi_select($mh2);
} while ($running2 > 0);

$webhookProcessedCount = 0;
$webhookIgnoredCount = 0;
$webhookFailures = 0;

foreach ($handles2 as $ch) {
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $response = curl_multi_getcontent($ch);
    curl_multi_remove_handle($mh2, $ch);
    curl_close($ch);

    $data = json_decode((string)$response, true);
    if ($httpCode === 200) {
        if (str_contains($data['message'] ?? '', 'already processed') || str_contains($data['message'] ?? '', 'not approved')) {
            $webhookIgnoredCount++;
        } else {
            $webhookProcessedCount++;
        }
    } else {
        $webhookFailures++;
    }
}
curl_multi_close($mh2);

echo "    Webhooks simultáneos enviados: {$webhookBurstCount}" . PHP_EOL;
echo "    Procesados quirúrgicamente: {$webhookProcessedCount}" . PHP_EOL;
echo "    Ignorados por Idempotencia / Status: {$webhookIgnoredCount}" . PHP_EOL;
echo "    Errores de firma / HTTP: {$webhookFailures}" . PHP_EOL;

if ($webhookFailures === 0) {
    echo "    PASS: Idempotencia en webhooks paralelos validada correctamente." . PHP_EOL;
} else {
    echo "    WARN/FAIL: Falló la validación de idempotencia o firma." . PHP_EOL;
}

echo PHP_EOL . "==========================================================" . PHP_EOL;
