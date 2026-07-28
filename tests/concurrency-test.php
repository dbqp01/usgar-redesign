<?php
declare(strict_types=1);

/**
 * USGAR Hotels — Concurrency & Race Condition Test
 * Envia 10 peticiones de reserva simultaneas para el mismo tipo de habitacion y fechas.
 */

$baseUrl = 'http://localhost:8000';
$url = "{$baseUrl}/api/booking";
$mh = curl_multi_init();
$handles = [];
$concurrencyCount = 10;

echo "=======================================================\n";
echo "  USGAR Hotels — Concurrency & Race Condition Audit    \n";
echo "  Sending {$concurrencyCount} simultaneous booking requests...  \n";
echo "=======================================================\n\n";

for ($i = 0; $i < $concurrencyCount; $i++) {
    $ch = curl_init($url);
    $payload = json_encode([
        'roomSlug'     => 'matrimonial',
        'checkIn'      => '2026-09-01',
        'checkOut'     => '2026-09-03',
        'guests'       => 1,
        'guestDetails' => [
            'firstName' => "ConcurrentGuest_{$i}",
            'lastName'  => 'Test',
            'email'     => "guest_{$i}@example.com",
            'phone'     => '+51999999999'
        ]
    ]);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);

    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}

$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

$successes = 0;
$failures = 0;
$responses = [];

foreach ($handles as $index => $ch) {
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content = curl_multi_getcontent($ch);
    $data = json_decode($content ?: '', true);

    if ($code === 200 && ($data['success'] ?? false) === true) {
        $successes++;
        echo "\033[32m[200 OK]\033[0m Request #{$index} succeeded. Cart ID: " . ($data['cart_id'] ?? 'N/A') . "\n";
    } else {
        $failures++;
        echo "\033[31m[{$code}]\033[0m Request #{$index} failed: " . json_encode($data) . "\n";
    }

    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}

curl_multi_close($mh);

echo "\n=======================================================\n";
echo "  Concurrency Results: {$successes} Succeeded | {$failures} Rejected/Failed\n";
echo "=======================================================\n";
