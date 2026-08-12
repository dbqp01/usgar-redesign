<?php
declare(strict_types=1);

/**
 * tests/user-flow.php — Flujo COMPLETO del usuario: buen usuario + mal usuario.
 *
 * Reemplaza funcionalmente a tests/pentest.php y tests/api-harness.php (ambos
 * con fechas hardcodeadas que quedan obsoletas). Fechas SIEMPRE dinámicas.
 *
 * Buen usuario (flujo transaccional real):
 *   health → rooms (disponibilidad) → booking (hold) → extend-hold →
 *   booking-status (sin/con token) → process-payment (rechazo seguro, sin
 *   cobrar: token de tarjeta inválido nunca cobra) → payment-check.
 *
 * Mal usuario (lo que no debería funcionar):
 *   días negativos, fechas pasadas, rango invertido, checkIn == checkOut,
 *   guests -5/0/99, roomSlug inexistente, id_room_type 999, XSS, SQLi,
 *   webhook sin firma / firma falsa, /api/user/bookings sin auth, email
 *   inválido, rateType desconocido, cart_id inexistente, extend sin token.
 *
 * Uso: php tests/user-flow.php          (arranca server propio en puerto libre)
 *      php tests/user-flow.php 8000     (usa server ya corriendo en :8000)
 * Exit code 0 = todo OK, 1 = hay fallos.
 */

$usePort = isset($argv[1]) ? (int)$argv[1] : 0;
$host = '127.0.0.1';
$port = $usePort;

$results = [];
$proc = null;
$serverLogFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-user-flow-' . getmypid() . '.log';

function httpReq(string $method, string $url, ?array $payload = null, array $headers = []): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false) {
        return ['code' => 0, 'body' => null, 'raw' => ''];
    }
    $body = substr($raw, $headerSize);
    return ['code' => $code, 'body' => json_decode($body, true), 'raw' => $body];
}

function logTest(string $title, bool $passed, string $details = ''): void {
    global $results;
    $status = $passed ? "\033[32m[PASS]\033[0m" : "\033[31m[FAIL]\033[0m";
    echo "{$status} {$title}\n";
    if ($details !== '') {
        echo "    ↳ {$details}\n";
    }
    echo "\n";
    $results[] = ['title' => $title, 'passed' => $passed];
}

// ---------- Arranque del server (si no se pasó puerto) ----------
if ($port === 0) {
    for ($try = 8089; $try <= 8110; $try++) {
        $fp = @fsockopen($host, $try, $errno, $errstr, 0.1);
        if (!$fp) {
            $port = $try;
            break;
        }
        fclose($fp);
    }

    $docRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public';
    $router  = $docRoot . DIRECTORY_SEPARATOR . 'index.php';
    $cmd = sprintf('php -S %s:%d -t %s %s', $host, $port, escapeshellarg($docRoot), escapeshellarg($router));
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $serverLogFile, 'a'], 2 => ['file', $serverLogFile, 'a']];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));

    $ready = false;
    for ($i = 0; $i < 30; $i++) {
        usleep(200000);
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if ($fp) {
            fclose($fp);
            $health = @file_get_contents("http://$host:$port/api/health");
            if ($health !== false && str_contains($health, '"success":true')) {
                $ready = true;
                break;
            }
        }
    }
    if (!$ready) {
        echo "ERROR: server no pudo arrancar en :$port — ver {$serverLogFile}\n";
        exit(1);
    }
    echo "Server de pruebas propio en http://$host:$port\n\n";
} else {
    $health = @file_get_contents("http://$host:$port/api/health");
    if ($health === false || !str_contains((string)$health, '"success":true')) {
        echo "ERROR: no hay server sano en :$port\n";
        exit(1);
    }
    echo "Usando server existente en http://$host:$port\n\n";
}

$base = "http://$host:$port";

// Fechas dinámicas (nunca hardcodeadas)
$in2  = date('Y-m-d', strtotime('+2 days'));
$out4 = date('Y-m-d', strtotime('+4 days'));
$out9 = date('Y-m-d', strtotime('+9 days'));

echo "==========================================================\n";
echo "  FLUJO COMPLETO DEL USUARIO — buen y mal usuario\n";
echo "  Fechas de prueba: {$in2} → {$out4} (mañana+días)\n";
echo "==========================================================\n\n";

// ==========================================================
// PARTE A: BUEN USUARIO — flujo transaccional real
// ==========================================================
echo "--- A: BUEN USUARIO ---\n\n";

// A1. health
$r = httpReq('GET', "$base/api/health");
logTest('A1 health responde 200 con success', $r['code'] === 200 && ($r['body']['success'] ?? false) === true, "HTTP {$r['code']}");

// A2. disponibilidad real
$r = httpReq('GET', "$base/api/rooms?checkIn=$in2&checkOut=$out4");
$rooms = $r['body']['rooms'] ?? [];
$firstRoom = $rooms[0] ?? null;
logTest(
    'A2 /api/rooms devuelve habitaciones con precio y qty',
    $r['code'] === 200 && count($rooms) > 0 && isset($firstRoom['slug'], $firstRoom['price'], $firstRoom['available_qty']),
    'HTTP ' . $r['code'] . ' | ' . count($rooms) . ' habitaciones | primera: ' . json_encode($firstRoom ?: null)
);

// A3. crear hold (booking feliz)
$r = httpReq('POST', "$base/api/booking", [
    'roomSlug'     => $firstRoom['slug'] ?? 'matrimonial',
    'checkIn'      => $in2,
    'checkOut'     => $out4,
    'guests'       => 1,
    'rateType'     => 'standard',
    'guestDetails' => [
        'firstName' => 'Buen',
        'lastName'  => 'Usuario',
        'email'     => 'flow.good@example.com',
        'phone'     => '+51999111222',
    ],
]);
$cartId = $r['body']['cart_id'] ?? '';
$token  = $r['body']['access_token'] ?? '';
$price  = $r['body']['price'] ?? null;
logTest(
    'A3 POST /api/booking crea hold (cart_id + access_token + precio)',
    $r['code'] === 200 && $cartId !== '' && $token !== '' && is_numeric($price) && (float)$price > 0,
    'HTTP ' . $r['code'] . ' | cart=' . $cartId . ' | price=' . var_export($price, true)
);

// A4. extender hold (con token válido)
$r = httpReq('POST', "$base/api/extend-hold", ['cart_id' => $cartId, 'access_token' => $token]);
logTest(
    'A4 POST /api/extend-hold extiende el hold con token válido',
    $r['code'] === 200 && ($r['body']['success'] ?? false) === true && isset($r['body']['expires_at']),
    'HTTP ' . $r['code'] . ' | expires_at=' . ($r['body']['expires_at'] ?? '?')
);

// A5. booking-status SIN token → 200 sin PII (auditoría 2026-08-11)
$r = httpReq('GET', "$base/api/booking-status?cart_id=$cartId");
$hasPii = isset($r['body']['guest_name']) || isset($r['body']['guest_email']) || isset($r['body']['guest_phone']);
logTest(
    'A5 booking-status sin token NO filtra PII',
    $r['code'] === 200 && !$hasPii,
    'HTTP ' . $r['code'] . ' | PII presente: ' . ($hasPii ? 'SÍ (BUG)' : 'no')
);

// A6. booking-status CON token → 200 con PII
$r = httpReq('GET', "$base/api/booking-status?cart_id=$cartId&token=$token");
logTest(
    'A6 booking-status con token devuelve PII del huésped',
    $r['code'] === 200 && ($r['body']['guest_name'] ?? '') === 'Buen Usuario',
    'HTTP ' . $r['code'] . ' | guest_name=' . ($r['body']['guest_name'] ?? '(ausente)')
);

// A7. process-payment con tarjeta inválida → rechazo seguro (400/422), nunca cobra
$r = httpReq('POST', "$base/api/process-payment", [
    'cart_id'      => $cartId,
    'access_token' => $token,
    'payment_data' => [
        'token'             => 'card_token_invalido_000',
        'payment_method_id' => 'visa',
        'installments'      => 1,
        'payer'             => ['email' => 'flow.good@example.com'],
    ],
]);
$rejected = ($r['code'] === 400 || $r['code'] === 422 || $r['code'] === 401);
logTest(
    'A7 process-payment con tarjeta inválida se rechaza sin cobrar',
    $rejected && ($r['body']['success'] ?? true) === false,
    'HTTP ' . $r['code'] . ' | ' . substr($r['raw'], 0, 200)
);

// A8. payment-check del hold (con token → 200 sin payment_id; sin token → 401 ownership)
$r = httpReq('GET', "$base/api/payment-check?cart_id=$cartId&token=$token");
logTest(
    'A8 /api/payment-check con token responde 200',
    $r['code'] === 200,
    'HTTP ' . $r['code'] . ' | body: ' . substr($r['raw'], 0, 150)
);

// ==========================================================
// PARTE B: MAL USUARIO — lo que no debería funcionar
// ==========================================================
echo "--- B: MAL USUARIO ---\n\n";

// Cada caso B de booking usa una ventana de fechas ÚNICA (in2+N) para no
// colisionar con el inventario de matrimonial que agotan los casos previos.
$bSlot = fn(int $n): array => [
    date('Y-m-d', strtotime("+2 days +{$n} days")),
    date('Y-m-d', strtotime("+4 days +{$n} days")),
];

// B1. días negativos (checkIn antes de hoy)
$past = date('Y-m-d', strtotime('-3 days'));
$r = httpReq('GET', "$base/api/rooms?checkIn=$past&checkOut=$out4");
logTest('B1 fechas pasadas en /api/rooms → 400', $r['code'] === 400, 'HTTP ' . $r['code']);

// B2. booking con fechas pasadas
[$bin, $bout] = $bSlot(2);
$r = httpReq('POST', "$base/api/booking", [
    'roomSlug' => 'matrimonial', 'checkIn' => $past, 'checkOut' => $bout, 'guests' => 1,
    'guestDetails' => ['firstName' => 'Mal', 'lastName' => 'Usuario', 'email' => 'flow.bad@example.com'],
]);
logTest('B2 booking con fechas pasadas → 400', $r['code'] === 400, 'HTTP ' . $r['code']);

// B3. rango invertido
[$bin, $bout] = $bSlot(3);
$r = httpReq('POST', "$base/api/booking", [
    'roomSlug' => 'matrimonial', 'checkIn' => $bout, 'checkOut' => $bin, 'guests' => 1,
    'guestDetails' => ['firstName' => 'Mal', 'lastName' => 'Usuario', 'email' => 'flow.bad@example.com'],
]);
logTest('B3 checkIn > checkOut → 400', $r['code'] === 400, 'HTTP ' . $r['code']);

// B4. checkIn == checkOut (0 noches)
[$bin, $bout] = $bSlot(4);
$r = httpReq('POST', "$base/api/booking", [
    'roomSlug' => 'matrimonial', 'checkIn' => $bin, 'checkOut' => $bin, 'guests' => 1,
    'guestDetails' => ['firstName' => 'Mal', 'lastName' => 'Usuario', 'email' => 'flow.bad@example.com'],
]);
logTest('B4 checkIn == checkOut (0 noches) → 400', $r['code'] === 400, 'HTTP ' . $r['code']);

// B5. guests NEGATIVOS → debe ser 400 (el código hace max(1, ...) — sospecha de bug)
[$bin, $bout] = $bSlot(5);
$r = httpReq('POST', "$base/api/booking", [
    'roomSlug' => 'matrimonial', 'checkIn' => $bin, 'checkOut' => $bout, 'guests' => -5,
    'guestDetails' => ['firstName' => 'Mal', 'lastName' => 'Usuario', 'email' => 'flow.bad@example.com'],
]);
logTest(
    'B5 guests=-5 → 400 (no hold silencioso con 1 huésped)',
    $r['code'] === 400,
    'HTTP ' . $r['code'] . ' | body: ' . substr($r['raw'], 0, 150)
);

// B6. guests = 0 → 400
[$bin, $bout] = $bSlot(6);
$r = httpReq('POST', "$base/api/booking", [
    'roomSlug' => 'matrimonial', 'checkIn' => $bin, 'checkOut' => $bout, 'guests' => 0,
    'guestDetails' => ['firstName' => 'Mal', 'lastName' => 'Usuario', 'email' => 'flow.bad@example.com'],
]);
logTest('B6 guests=0 → 400', $r['code'] === 400, 'HTTP ' . $r['code']);

// B7. overcapacity (99 en matrimonial, max 2)
[$bin, $bout] = $bSlot(7);
$r = httpReq('POST', "$base/api/booking", [
    'roomSlug' => 'matrimonial', 'checkIn' => $bin, 'checkOut' => $bout, 'guests' => 99,
    'guestDetails' => ['firstName' => 'Mal', 'lastName' => 'Usuario', 'email' => 'flow.bad@example.com'],
]);
logTest('B7 guests=99 (overcapacity) → 400', $r['code'] === 400, 'HTTP ' . $r['code']);

// B8. roomSlug inexistente → 400 (el código hace getIdBySlug ?? 1 — sospecha de bug: reservaría matrimonial)
[$bin, $bout] = $bSlot(8);
$r = httpReq('POST', "$base/api/booking", [
    'roomSlug' => 'suite-imperial-inexistente', 'checkIn' => $bin, 'checkOut' => $bout, 'guests' => 1,
    'guestDetails' => ['firstName' => 'Mal', 'lastName' => 'Usuario', 'email' => 'flow.bad@example.com'],
]);
logTest(
    'B8 roomSlug inexistente → 400 (no reservar matrimonial por defecto)',
    $r['code'] === 400,
    'HTTP ' . $r['code'] . ' | body: ' . substr($r['raw'], 0, 150)
);

// B9. id_room_type inexistente (999)
[$bin, $bout] = $bSlot(9);
$r = httpReq('POST', "$base/api/booking", [
    'id_room_type' => 999, 'checkIn' => $bin, 'checkOut' => $bout, 'guests' => 1,
    'guestDetails' => ['firstName' => 'Mal', 'lastName' => 'Usuario', 'email' => 'flow.bad@example.com'],
]);
logTest('B9 id_room_type=999 → 400', $r['code'] === 400, 'HTTP ' . $r['code']);

// B10. XSS en nombre (sanitizado en respuesta)
[$bin, $bout] = $bSlot(10);
$xss = "<script>alert('xss')</script>";
$r = httpReq('POST', "$base/api/booking", [
    'roomSlug' => 'matrimonial', 'checkIn' => $bin, 'checkOut' => $bout, 'guests' => 1,
    'guestDetails' => ['firstName' => $xss, 'lastName' => 'XSS', 'email' => 'flow.bad@example.com'],
]);
$rawHasScript = str_contains($r['raw'], '<script>alert');
logTest(
    'B10 XSS en nombre → no se refleja crudo en la respuesta',
    $r['code'] === 200 && !$rawHasScript,
    'HTTP ' . $r['code'] . ' | <script> en respuesta: ' . ($rawHasScript ? 'SÍ (BUG)' : 'no')
);

// B11. SQLi en checkIn
$r = httpReq('GET', "$base/api/rooms?checkIn=" . urlencode("2026-08-01' OR '1'='1") . "&checkOut=$out4");
logTest('B11 SQLi en checkIn → 400', $r['code'] === 400, 'HTTP ' . $r['code']);

// B12. email inválido
[$bin, $bout] = $bSlot(12);
$r = httpReq('POST', "$base/api/booking", [
    'roomSlug' => 'matrimonial', 'checkIn' => $bin, 'checkOut' => $bout, 'guests' => 1,
    'guestDetails' => ['firstName' => 'Mal', 'lastName' => 'Usuario', 'email' => 'no-es-un-email'],
]);
logTest('B12 email inválido → 400', $r['code'] === 400, 'HTTP ' . $r['code']);

// B13. faltan campos requeridos
$r = httpReq('POST', "$base/api/booking", ['roomSlug' => 'matrimonial']);
logTest('B13 booking sin campos requeridos → 400', $r['code'] === 400, 'HTTP ' . $r['code']);

// B14. webhook sin firma → 400/401/403 (fail-closed). type='payment' es el único
//     que llega a validación de firma (filtro estricto TODO 13: otros tipos se
//     acusean 200 sin procesar, intencional).
$r = httpReq('POST', "$base/api/webhook", ['type' => 'payment', 'data' => ['id' => '123456789']]);
logTest('B14 webhook type=payment sin firma HMAC → 400/401/403', in_array($r['code'], [400, 401, 403], true), 'HTTP ' . $r['code']);

// B15. webhook con firma falsa
$r = httpReq('POST', "$base/api/webhook", ['type' => 'payment', 'data' => ['id' => '123456789']], ['x-signature: ts=12345,v1=fake_hash']);
logTest('B15 webhook type=payment con firma falsa → 400/401/403', in_array($r['code'], [400, 401, 403], true), 'HTTP ' . $r['code']);

// B16. /api/user/bookings sin auth → 401
$r = httpReq('GET', "$base/api/user/bookings");
logTest('B16 /api/user/bookings sin auth → 401', $r['code'] === 401, 'HTTP ' . $r['code']);

// B17. newsletter con email inválido → 422
$r = httpReq('POST', "$base/api/newsletter", ['email' => 'no-es-email', 'locale' => 'en']);
logTest('B17 newsletter email inválido → 422', $r['code'] === 422, 'HTTP ' . $r['code']);

// B18. rateType desconocido → 200 con rate standard (whitelist cerrada, sin descuento)
[$bin, $bout] = $bSlot(18);
$r = httpReq('POST', "$base/api/booking", [
    'roomSlug' => 'matrimonial', 'checkIn' => $bin, 'checkOut' => $bout, 'guests' => 1,
    'rateType' => 'gratis-total', // intento de rateType inventado
    'guestDetails' => ['firstName' => 'Mal', 'lastName' => 'Usuario', 'email' => 'flow.bad@example.com'],
]);
$rateOk = ($r['body']['rate_type'] ?? '') === 'standard' && ($r['body']['price'] ?? 0) > 0;
logTest('B18 rateType desconocido cae a standard (sin descuento)', $r['code'] === 200 && $rateOk, 'HTTP ' . $r['code'] . ' | rate_type=' . ($r['body']['rate_type'] ?? '?') . ' price=' . ($r['body']['price'] ?? '?'));

// B19. booking-status con cart inexistente → 404
$r = httpReq('GET', "$base/api/booking-status?cart_id=999999999");
logTest('B19 booking-status cart inexistente → 404', $r['code'] === 404, 'HTTP ' . $r['code']);

// B20. extend-hold sin token → 401
$r = httpReq('POST', "$base/api/extend-hold", ['cart_id' => $cartId]);
logTest('B20 extend-hold sin token → 401', $r['code'] === 401, 'HTTP ' . $r['code']);

// ==========================================================
// Resumen
// ==========================================================
$passed = count(array_filter($results, fn($x) => $x['passed']));
$failed = count($results) - $passed;

echo "==========================================================\n";
echo " RESUMEN: {$passed}/" . count($results) . " OK · {$failed} fallos\n";
echo "==========================================================\n";

if ($proc && is_resource($proc)) {
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    proc_terminate($proc);
    proc_close($proc);
    echo "Server de pruebas apagado.\n";
}

exit($failed > 0 ? 1 : 0);
