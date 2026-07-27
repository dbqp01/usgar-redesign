<?php
declare(strict_types=1);

$baseUrl = 'http://localhost:4321/api';

echo "==========================================================" . PHP_EOL;
echo " USGAR HOTELS - RUNTIME API TEST SUITE (PORT 4321)" . PHP_EOL;
echo "==========================================================" . PHP_EOL;

function testEndpoint(string $name, string $method, string $path, ?array $payload = null, array $headers = []): void {
    global $baseUrl;
    $url = $baseUrl . $path;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $reqHeaders = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $reqHeaders[] = "$k: $v";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo sprintf(" [%3d] %-6s %-35s -> ", $httpCode, $method, $path);
    if ($httpCode >= 200 && $httpCode < 300) {
        echo "PASS: " . substr((string)$response, 0, 140) . PHP_EOL;
    } else {
        echo "RESPONSE: " . (string)$response . PHP_EOL;
    }
}

// 1. GET /health
testEndpoint("Health Check", "GET", "/health");

// 2. GET /rooms
testEndpoint("Get Rooms Available", "GET", "/rooms?checkIn=2026-08-01&checkOut=2026-08-05");

// 3. POST /booking
$ch = curl_init('http://localhost:4321/api/booking');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'id_hotel'     => 1,
    'id_room_type' => 3,
    'checkIn'      => '2026-08-01',
    'checkOut'     => '2026-08-05',
    'guestName'    => 'Akim Mena',
    'guestEmail'   => 'akim@example.com',
    'guestPhone'   => '+51999888777',
    'guests'       => 2,
]));
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo sprintf(" [%3d] %-6s %-35s -> ", $httpCode, 'POST', '/booking');
$cartId = null;
if ($httpCode === 200) {
    echo "PASS: " . substr((string)$res, 0, 140) . PHP_EOL;
    $json = json_decode((string)$res, true);
    $cartId = $json['cart_id'] ?? null;
} else {
    echo "RESPONSE: " . (string)$res . PHP_EOL;
}

// 4. GET /booking-status
if ($cartId) {
    testEndpoint("Booking Status Valid", "GET", "/booking-status?cart_id=" . urlencode($cartId));
} else {
    testEndpoint("Booking Status Invalid", "GET", "/booking-status");
}

// 5. GET /auth/providers
testEndpoint("Auth Providers", "GET", "/auth/providers");

echo "==========================================================" . PHP_EOL;
