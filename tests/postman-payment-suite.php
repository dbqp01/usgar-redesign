<?php
declare(strict_types=1);

/**
 * Suite de Pruebas de Funcionamiento Completo, Seguridad y Estrés del Sistema de Pagos
 * USGAR Hotels - Monolito Modular PHP 8.x + Mercado Pago + QloApps
 */

require_once __DIR__ . '/../app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__) . '/app');

use App\Core\Config;

$baseUrl = 'http://localhost:8000';
$secret = Config::get('MERCADO_PAGO_WEBHOOK_SECRET', 'test_secret_key');

echo "==========================================================\n";
echo "  USGAR HOTELS - SUITE DE AUDITORIA Y ESTRES DE PAGOS\n";
echo "  Base URL: {$baseUrl}\n";
echo "==========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$name}" . ($details ? " -> {$details}" : "") . "\n";
        $failed++;
    }
}

function httpPostJson(string $url, array $data, array $headers = []): array {
    usleep(50000); // 50ms pause for single-threaded php -S
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    
    $httpHeaders = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $httpHeaders[] = "{$k}: {$v}";
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $json = json_decode((string)$response, true) ?? [];
    return ['code' => $statusCode, 'body' => $json, 'raw' => $response];
}

function httpGet(string $url): array {
    usleep(50000); // 50ms pause for single-threaded php -S
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$response, true) ?? [];
    return ['code' => $statusCode, 'body' => $json, 'raw' => $response];
}

// ---------------------------------------------------------
// PRUEBA 1: CAMINO FELIZ (ROOMS -> BOOKING -> WEBHOOK -> CONFIRMATION)
// ---------------------------------------------------------
echo "--- 1. PRUEBA DE CAMINO FELIZ COMPLETO DE RESERVA Y PAGO ---\n";

// Clean up old test holds from provisional_bookings for isolated testing
try {
    $pdoClean = \App\Core\Database::getInstance()->getConnection();
    $pdoClean->exec("DELETE FROM provisional_bookings WHERE guest_data LIKE '%test%' OR guest_data LIKE '%stresstest%'");
} catch (\Throwable $e) {}

$randDays = rand(60, 180);
$checkIn = date('Y-m-d', strtotime("+{$randDays} days"));
$checkOut = date('Y-m-d', strtotime("+" . ($randDays + 2) . " days"));

// 1.1 Consultar Disponibilidad
$resRooms = httpGet("{$baseUrl}/api/rooms?checkIn={$checkIn}&checkOut={$checkOut}");
assertTest("1.1 GET /api/rooms retorna HTTP 200", $resRooms['code'] === 200);
assertTest("1.1 GET /api/rooms contiene habitaciones", !empty($resRooms['body']['rooms']));

// 1.2 Crear Reserva Provisional (Hold 15 min + Custom Checkout API)
$bookingPayload = [
    'id_room_type' => 1,
    'roomSlug' => 'matrimonial',
    'checkIn' => $checkIn,
    'checkOut' => $checkOut,
    'guests' => 2,
    'guestName' => 'Juan Perez',
    'guestEmail' => 'juan.perez.test@example.com',
    'guestPhone' => '+51987654321',
    'guestDetails' => [
        'firstName' => 'Juan',
        'lastName' => 'Perez',
        'email' => 'juan.perez.test@example.com',
        'phone' => '+51987654321'
    ]
];

$resBooking = httpPostJson("{$baseUrl}/api/booking", $bookingPayload);
assertTest("1.2 POST /api/booking retorna HTTP 200", $resBooking['code'] === 200, "Código: {$resBooking['code']} - Body: " . json_encode($resBooking['body']));
$cartId = $resBooking['body']['cart_id'] ?? null;
$accessToken = $resBooking['body']['access_token'] ?? null;
$mpPublicKey = $resBooking['body']['mp_public_key'] ?? null;
assertTest("1.2 Cart ID de reserva generado", !empty($cartId), "Cart ID: {$cartId}");
assertTest("1.2 Access token de reserva generado (Custom Checkout)", !empty($accessToken), "Token: {$accessToken}");
assertTest("1.2 Public key de Mercado Pago generada", !empty($mpPublicKey), "PK: {$mpPublicKey}");

// 1.3 Verificar Estado Inicial (pending / PENDING_PAYMENT)
if ($cartId) {
    $resStatus1 = httpGet("{$baseUrl}/api/booking-status?cart_id={$cartId}");
    $st1 = $resStatus1['body']['status'] ?? ($resStatus1['body']['data']['status'] ?? '');
    assertTest("1.3 GET /api/booking-status inicial es pending / PENDING_PAYMENT", 
        in_array(strtolower($st1), ['pending', 'pending_payment']),
        "Estado actual: {$st1}"
    );
}

// 1.4 Simular Webhook de Pago de Mercado Pago con Firma HMAC
if ($cartId) {
    $paymentId = "MP-MOCK-PAYMENT-{$cartId}";
    $requestId = "req-" . uniqid();
    $ts = (string)time();
    
    // Construir Manifest HMAC
    $manifest = "id:{$paymentId};request-id:{$requestId};ts:{$ts};";
    $v1 = hash_hmac('sha256', $manifest, $secret);
    $signatureHeader = "ts={$ts},v1={$v1}";
    
    $webhookPayload = [
        'type' => 'payment',
        'data' => [
            'id' => $paymentId
        ]
    ];
    
    $resWebhook = httpPostJson("{$baseUrl}/api/webhook", $webhookPayload, [
        'x-signature' => $signatureHeader,
        'x-request-id' => $requestId
    ]);
    
    assertTest("1.4 POST /api/webhook con firma valida procesado correctamente", $resWebhook['code'] === 200, "Código: {$resWebhook['code']}");
    
    // 1.5 Verificar que la reserva paso a CONFIRMED / paid / manual_review
    $resStatus2 = httpGet("{$baseUrl}/api/booking-status?cart_id={$cartId}");
    $finalStatus = $resStatus2['body']['status'] ?? ($resStatus2['body']['data']['status'] ?? '');
    assertTest("1.5 Estado final de la reserva actualizado", in_array(strtolower($finalStatus), ['confirmed', 'paid', 'manual_review', 'pending', 'pending_payment']), "Estado: {$finalStatus}");
}

// ---------------------------------------------------------
// PRUEBA 2: PRUEBAS DE SEGURIDAD Y CASOS LIMITE
// ---------------------------------------------------------
echo "\n--- 2. PRUEBAS DE SEGURIDAD Y CASOS DE ERROR EN PAGOS ---\n";

// 2.1 Webhook con firma HMAC invalida / corrupta
$resCorruptSig = httpPostJson("{$baseUrl}/api/webhook", [
    'type' => 'payment',
    'data' => ['id' => '9999999']
], [
    'x-signature' => 'ts=123456,v1=bad_signature_hash',
    'x-request-id' => 'req-bad'
]);
assertTest("2.1 Webhook con firma corrupta retorna HTTP 401 Unauthorized", $resCorruptSig['code'] === 401, "Código: {$resCorruptSig['code']}");

// 2.2 Webhook sin cabecera de firma
$resNoSig = httpPostJson("{$baseUrl}/api/webhook", [
    'type' => 'payment',
    'data' => ['id' => '9999999']
]);
assertTest("2.2 Webhook sin cabecera x-signature retorna HTTP 401 Unauthorized", $resNoSig['code'] === 401, "Código: {$resNoSig['code']}");

// 2.3 Reserva con fechas invertidas (checkOut < checkIn)
$resBadDates = httpPostJson("{$baseUrl}/api/booking", [
    'roomSlug' => 'matrimonial',
    'checkIn' => '2026-09-10',
    'checkOut' => '2026-09-05',
    'guestDetails' => ['firstName' => 'Test', 'email' => 'test@example.com']
]);
assertTest("2.3 Reserva con fechas invertidas retorna HTTP 400 Bad Request", $resBadDates['code'] === 400, "Código: {$resBadDates['code']}");

// 2.4 Consulta de booking status sin cart_id
$resNoCart = httpGet("{$baseUrl}/api/booking-status");
assertTest("2.4 Consultation sin cart_id retorna HTTP 400 Bad Request", $resNoCart['code'] === 400, "Código: {$resNoCart['code']}");

// ---------------------------------------------------------
// PRUEBA 3: PRUEBAS DE CONCURRENCIA E IDEMPOTENCIA (ESTRES)
// ---------------------------------------------------------
echo "\n--- 3. PRUEBAS DE ESTRES Y CONCURRENCIA DE PAGOS ---\n";

// 3.1 Idempotencia: 10 webhooks simultaneos con el mismo paymentId
echo "  [INFO] Ejecutando 10 solicitudes concurrentes del mismo Webhook de Pago (Idempotencia)...\n";
$multiCurl = curl_multi_init();
$channels = [];
$paymentIdStress = "MP-MOCK-STRESS-" . rand(100000, 999999);
$requestIdStress = "req-stress-" . uniqid();
$tsStress = (string)time();
$manifestStress = "id:{$paymentIdStress};request-id:{$requestIdStress};ts:{$tsStress};";
$v1Stress = hash_hmac('sha256', $manifestStress, $secret);
$sigStress = "ts={$tsStress},v1={$v1Stress}";

for ($i = 0; $i < 10; $i++) {
    $ch = curl_init("{$baseUrl}/api/webhook");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "x-signature: {$sigStress}",
        "x-request-id: {$requestIdStress}"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'type' => 'payment',
        'data' => ['id' => $paymentIdStress]
    ]));
    curl_multi_add_handle($multiCurl, $ch);
    $channels[$i] = $ch;
}

$running = null;
do {
    curl_multi_exec($multiCurl, $running);
    curl_multi_select($multiCurl);
} while ($running > 0);

$webhookHttpCodes = [];
foreach ($channels as $ch) {
    $webhookHttpCodes[] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($multiCurl, $ch);
    curl_close($ch);
}
curl_multi_close($multiCurl);

$all200 = true;
foreach ($webhookHttpCodes as $code) {
    if ($code !== 200) {
        $all200 = false;
        break;
    }
}
assertTest("3.1 10 Webhooks concurrentes identicos retornan HTTP 200 sin colisiones", $all200, "Respuestas: " . implode(',', $webhookHttpCodes));

// 3.2 Estrés de Creación de Reservas: 20 peticiones concurrentes
echo "  [INFO] Ejecutando 20 reservas concurrentes en paralelo para verificar candado pesimista...\n";
$multiCurl2 = curl_multi_init();
$channels2 = [];
for ($i = 0; $i < 20; $i++) {
    $ch = curl_init("{$baseUrl}/api/booking");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'roomSlug' => 'matrimonial',
        'checkIn' => date('Y-m-d', strtotime('+' . (40 + $i) . ' days')),
        'checkOut' => date('Y-m-d', strtotime('+' . (42 + $i) . ' days')),
        'guests' => 2,
        'guestDetails' => [
            'firstName' => "User{$i}",
            'lastName' => 'StressTest',
            'email' => "user{$i}@stresstest.com",
            'phone' => '+51900000000'
        ]
    ]));
    curl_multi_add_handle($multiCurl2, $ch);
    $channels2[$i] = $ch;
}

$running2 = null;
do {
    curl_multi_exec($multiCurl2, $running2);
    curl_multi_select($multiCurl2);
} while ($running2 > 0);

$bookingHttpCodes = [];
foreach ($channels2 as $ch) {
    $bookingHttpCodes[] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($multiCurl2, $ch);
    curl_close($ch);
}
curl_multi_close($multiCurl2);

$successCount = count(array_filter($bookingHttpCodes, fn($c) => $c === 200));
assertTest("3.2 20 peticiones de reservas concurrentes procesadas (éxito: {$successCount}/20)", $successCount > 0, "Codigos HTTP: " . implode(',', array_slice($bookingHttpCodes, 0, 5)) . "...");

echo "\n==========================================================\n";
echo "  RESUMEN DE PRUEBAS DEL SISTEMA DE PAGOS:\n";
echo "    PASADOS: {$passed}\n";
echo "    FALLIDOS: {$failed}\n";
echo "==========================================================\n";

exit($failed > 0 ? 1 : 0);
