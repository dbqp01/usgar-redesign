<?php
/**
 * API Smoke Test Harness — USGAR Hotels
 * Version PHP para ejecutar en Hostinger donde no hay bash.
 * Usage: php tests/api-harness.php [base_url]
 */
declare(strict_types=1);

$baseUrl = $argv[1] ?? 'http://localhost:8000';
$pass = 0;
$warn = 0;
$fail = 0;

function testEndpoint(string $method, string $path, int $expectedStatus, ?string $body = null): array {
    global $baseUrl;

    $url = $baseUrl . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '{}');
    }

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['status' => 0, 'label' => "$method $path", 'expected' => $expectedStatus, 'error' => $error];
    }

    return ['status' => $httpCode, 'label' => "$method $path", 'expected' => $expectedStatus, 'body' => $response];
}

echo "=================================\n";
echo "  USGAR Hotels API Harness (PHP)\n";
echo "  Target: $baseUrl\n";
echo "=================================\n\n";

$tests = [
    ['GET',  '/api/health',         200],
    ['GET',  '/api/rooms',          200],
    ['GET',  '/api/rooms?checkIn=2026-08-01&checkOut=2026-08-03', 200],
    ['POST', '/api/booking',        400, '{}'],
    ['POST', '/api/extend-hold',    400, '{}'],
    ['GET',  '/api/booking-status', 400],
    ['GET',  '/api/auth/me',        401],
    ['POST', '/api/auth/register',  400, '{}'],
    ['POST', '/api/auth/login-email', 400, '{}'],
    ['POST', '/api/auth/logout',    401],
];

foreach ($tests as $test) {
    $result = testEndpoint($test[0], $test[1], $test[2], $test[3] ?? null);

    if ($result['status'] === 0) {
        echo " {$result['label']} → CONNECTION REFUSED ({$result['error']})\n";
        $fail++;
    } elseif ($result['status'] === $result['expected']) {
        echo " {$result['label']} → {$result['status']}\n";
        $pass++;
    } elseif ($result['status'] === 500) {
        echo " {$result['label']} → {$result['status']} (SERVER ERROR)\n";
        $fail++;
    } else {
        echo "️  {$result['label']} → {$result['status']} (expected {$result['expected']})\n";
        $warn++;
    }
}

echo "\n=================================\n";
echo "  Results:  $pass  ️  $warn   $fail\n";
echo "=================================\n";

exit($fail > 0 ? 1 : 0);
