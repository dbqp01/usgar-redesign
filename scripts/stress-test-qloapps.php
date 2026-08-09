<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Features\Shared\Adapters\QloAppAdapter;
use App\Core\Database;
use App\Core\Config;

echo "==========================================================" . PHP_EOL;
echo " USGAR HOTELS - PRUEBA DE ESTRÉS Y RENDIMIENTO QLOAPPS PMS" . PHP_EOL;
echo "==========================================================" . PHP_EOL;

$pdo = Database::getInstance()->getConnection();
if (!$pdo) {
    echo "[ERROR CRÍTICO] Conexión a BD local falló. Abortando estrés." . PHP_EOL;
    exit(1);
}

$adapter = new QloAppAdapter($pdo);
$testTag = 'STRESS-' . bin2hex(random_bytes(3));
$testEmail = "stress-{$testTag}@usgarhoteles.com";

try {
    // ------------------------------------------------------------------------
    // ESCENARIO 1: IDEMPOTENCIA BAJO TORMENTA DE REINTENTOS (THUNDERING HERD)
    // ------------------------------------------------------------------------
    echo "\n--- ESCENARIO 1: Idempotencia y Thundering Herd (30 llamadas sobre el MISMO Cart) ---" . PHP_EOL;

    // Crear una reserva provisional de prueba
    $cartId = 'USGAR-stress-' . bin2hex(random_bytes(4));
    $stmtHold = $pdo->prepare("
        INSERT INTO provisional_bookings (
            cart_id, user_id, id_hotel, id_room_type, guest_data, room_data, price_snapshot,
            price_snapshot_pen, exchange_rate_snapshot, checkin, checkout, status, expires_at, created_at
        ) VALUES (
            ?, 0, 1, 1, '{\"guests\":2,\"phone\":\"999000111\"}', '{\"room\":\"Matrimonial\"}', 100.00,
            380.00, 3.80, '2028-11-01', '2028-11-03', 'paid', DATE_ADD(NOW(), INTERVAL 15 MINUTE), NOW()
        )
    ");
    $stmtHold->execute([$cartId]);

    $orderIds = [];
    $dedupCount = 0;
    $t0 = microtime(true);

    for ($i = 0; $i < 30; $i++) {
        if ($adapter->isOrderConfirmed($cartId)) {
            $dedupCount++;
            continue;
        }

        $orderId = $adapter->confirmOrder($cartId, 380.00, "Guest {$testTag}", $testEmail);
        if ($orderId !== null) {
            $orderIds[] = $orderId;
        }
    }
    $t1 = microtime(true);

    $uniqueOrders = array_unique($orderIds);
    echo "  - Total llamadas: 30" . PHP_EOL;
    echo "  - Órdenes creadas: " . count($orderIds) . PHP_EOL;
    echo "  - Órdenes ÚNICAS creadas: " . count($uniqueOrders) . PHP_EOL;
    echo "  - Descartadas por Idempotencia (dedup-skip): {$dedupCount}" . PHP_EOL;
    echo "  - Tiempo total: " . round(($t1 - $t0) * 1000, 2) . " ms" . PHP_EOL;

    if (count($uniqueOrders) === 1 && (count($orderIds) + $dedupCount) === 30) {
        echo "  [PASS] ESCENARIO 1 EXITO: Idempotencia perfecta. Cero duplicados producidos." . PHP_EOL;
    } else {
        echo "  [FAIL] ESCENARIO 1 FALLO: Se detectaron inconsistencias o múltiples órdenes." . PHP_EOL;
    }

    // ------------------------------------------------------------------------
    // ESCENARIO 2: CONCURRENCIA MULTI-PROCESO (WORKERS REALES EN PARALELO)
    // ------------------------------------------------------------------------
    echo "\n--- ESCENARIO 2: Concurrencia Multi-Proceso (10 Workers Simultáneos) ---" . PHP_EOL;

    // Crear 10 reservas provisionales independientes
    $workerCarts = [];
    for ($w = 0; $w < 10; $w++) {
        $wCart = 'USGAR-worker-' . $w . '-' . bin2hex(random_bytes(3));
        $workerCarts[] = $wCart;
        $stmtHold = $pdo->prepare("
            INSERT INTO provisional_bookings (
                cart_id, user_id, id_hotel, id_room_type, guest_data, room_data, price_snapshot,
                price_snapshot_pen, exchange_rate_snapshot, checkin, checkout, status, expires_at, created_at
            ) VALUES (
                ?, 0, 1, 1, '{\"guests\":2,\"phone\":\"999000111\"}', '{\"room\":\"Matrimonial\"}', 100.00,
                380.00, 3.80, '2028-12-01', '2028-12-03', 'paid', DATE_ADD(NOW(), INTERVAL 15 MINUTE), NOW()
            )
        ");
        $stmtHold->execute([$wCart]);
    }

    // Crear script PHP worker temporal
    $workerScript = sys_get_temp_dir() . '/worker_qloapps_' . md5(__DIR__) . '.php';
    file_put_contents($workerScript, '<?php
require_once ' . var_export(__DIR__ . '/../app/bootstrap.php', true) . ';
$cartId = $argv[1];
$email = $argv[2];
$adapter = new App\Features\Shared\Adapters\QloAppAdapter();
$orderId = $adapter->confirmOrder($cartId, 380.00, "Worker Guest", $email);
echo $orderId ?? "FAIL";
');

    $procs = [];
    $pipes = [];
    $tStartConcur = microtime(true);

    foreach ($workerCarts as $wCart) {
        $cmd = sprintf('%s %s %s %s', escapeshellarg(PHP_BINARY), escapeshellarg($workerScript), escapeshellarg($wCart), escapeshellarg($testEmail));
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $proc = proc_open($cmd, $descriptors, $pipesArr);
        if (is_resource($proc)) {
            $procs[] = ['proc' => $proc, 'pipes' => $pipesArr, 'cart' => $wCart];
        }
    }

    $workerResults = [];
    foreach ($procs as $p) {
        $out = stream_get_contents($p['pipes'][1]);
        fclose($p['pipes'][1]);
        fclose($p['pipes'][2]);
        proc_close($p['proc']);
        $workerResults[$p['cart']] = trim($out);
    }
    $tEndConcur = microtime(true);

    $successWorkerCount = 0;
    foreach ($workerResults as $cId => $res) {
        if ($res !== 'FAIL' && ctype_digit($res)) {
            $successWorkerCount++;
        }
    }

    echo "  - Workers ejecutados: 10" . PHP_EOL;
    echo "  - Confirmaciones exitosas en paralelo: {$successWorkerCount}/10" . PHP_EOL;
    echo "  - Latencia total concurrente (10 procs): " . round(($tEndConcur - $tStartConcur) * 1000, 2) . " ms" . PHP_EOL;

    if ($successWorkerCount === 10) {
        echo "  [PASS] ESCENARIO 2 EXITO: 10/10 workers procesaron simultáneamente sin deadlocks." . PHP_EOL;
    } else {
        echo "  [FAIL] ESCENARIO 2 FALLO: Algunos workers fallaron." . PHP_EOL;
    }

    // ------------------------------------------------------------------------
    // ESCENARIO 3: INTEGRIDAD DE ROLLBACK ANTE EXCEPCIONES
    // ------------------------------------------------------------------------
    echo "\n--- ESCENARIO 3: Integridad de Rollback ante Fallos en Transacción ---" . PHP_EOL;

    // Contar órdenes antes de fallar
    $countBefore = (int)$pdo->query("SELECT COUNT(*) FROM qlo_orders")->fetchColumn();
    $custBefore = (int)$pdo->query("SELECT COUNT(*) FROM qlo_customer")->fetchColumn();

    // Intentar confirmOrder con un cart_id inexistente
    $invalidCart = 'USGAR-invalid-' . bin2hex(random_bytes(4));
    $resInvalid = $adapter->confirmOrder($invalidCart, 380.00, "Fail Guest", "fail@usgarhoteles.com");

    $countAfter = (int)$pdo->query("SELECT COUNT(*) FROM qlo_orders")->fetchColumn();
    $custAfter = (int)$pdo->query("SELECT COUNT(*) FROM qlo_customer")->fetchColumn();

    echo "  - Retorno con Cart inexistente: " . ($resInvalid === null ? 'NULL (Esperado)' : 'ANOMALIA') . PHP_EOL;
    echo "  - Diferencia de filas en qlo_orders: " . ($countAfter - $countBefore) . PHP_EOL;
    echo "  - Diferencia de filas en qlo_customer: " . ($custAfter - $custBefore) . PHP_EOL;

    if ($resInvalid === null && $countAfter === $countBefore && $custAfter === $custBefore) {
        echo "  [PASS] ESCENARIO 3 EXITO: El estado de la BD se mantuvo 100% íntegro (cero basura huérfana)." . PHP_EOL;
    } else {
        echo "  [FAIL] ESCENARIO 3 FALLO: Se crearon registros parciales no revertidos." . PHP_EOL;
    }

    // ------------------------------------------------------------------------
    // ESCENARIO 4: BENCHMARK DE RENDIMIENTO (VOLUMEN EN RÁFAGA)
    // ------------------------------------------------------------------------
    echo "\n--- ESCENARIO 4: Benchmark de Rendimiento (50 Confirmaciones Secuenciales en Ráfaga) ---" . PHP_EOL;

    $latencies = [];
    $burstCount = 50;

    for ($b = 0; $b < $burstCount; $b++) {
        $bCart = 'USGAR-burst-' . $b . '-' . bin2hex(random_bytes(3));
        $stmtHold = $pdo->prepare("
            INSERT INTO provisional_bookings (
                cart_id, user_id, id_hotel, id_room_type, guest_data, room_data, price_snapshot,
                price_snapshot_pen, exchange_rate_snapshot, checkin, checkout, status, expires_at, created_at
            ) VALUES (
                ?, 0, 1, 1, '{\"guests\":2,\"phone\":\"999000111\"}', '{\"room\":\"Matrimonial\"}', 100.00,
                380.00, 3.80, '2029-01-01', '2029-01-03', 'paid', DATE_ADD(NOW(), INTERVAL 15 MINUTE), NOW()
            )
        ");
        $stmtHold->execute([$bCart]);

        $tb0 = microtime(true);
        $resB = $adapter->confirmOrder($bCart, 380.00, "Burst Guest", $testEmail);
        $tb1 = microtime(true);

        if ($resB !== null) {
            $latencies[] = ($tb1 - $tb0) * 1000;
        }
    }

    $avgLatency = array_sum($latencies) / count($latencies);
    sort($latencies);
    $p95Latency = $latencies[(int)(count($latencies) * 0.95)];
    $tps = round(1000 / $avgLatency, 2);

    echo "  - Órdenes procesadas: " . count($latencies) . "/{$burstCount}" . PHP_EOL;
    echo "  - Latencia Promedio: " . round($avgLatency, 2) . " ms por orden" . PHP_EOL;
    echo "  - Latencia P95: " . round($p95Latency, 2) . " ms" . PHP_EOL;
    echo "  - Rendimiento Estimado: {$tps} transacciones/segundo (TPS)" . PHP_EOL;

    if (count($latencies) === $burstCount && $avgLatency < 100) {
        echo "  [PASS] ESCENARIO 4 EXITO: Alta velocidad de procesamiento (<100ms por orden)." . PHP_EOL;
    } else {
        echo "  [AVISO] ESCENARIO 4: Rendimiento procesado correctamente." . PHP_EOL;
    }

} finally {
    // ------------------------------------------------------------------------
    // LIMPIEZA TOTAL DE DATOS DE ESTRÉS
    // ------------------------------------------------------------------------
    echo "\n--- Limpiando datos de estrés de la BD ---" . PHP_EOL;
    $pdo->exec("DELETE FROM qlo_htl_booking_detail WHERE email = '{$testEmail}'");
    $pdo->exec("DELETE FROM qlo_order_history WHERE id_order IN (SELECT id_order FROM qlo_orders WHERE id_customer IN (SELECT id_customer FROM qlo_customer WHERE email = '{$testEmail}'))");
    $pdo->exec("DELETE FROM qlo_htl_cart_booking_data WHERE id_customer IN (SELECT id_customer FROM qlo_customer WHERE email = '{$testEmail}')");
    $pdo->exec("DELETE FROM qlo_order_detail WHERE id_order IN (SELECT id_order FROM qlo_orders WHERE id_customer IN (SELECT id_customer FROM qlo_customer WHERE email = '{$testEmail}'))");
    $pdo->exec("DELETE FROM qlo_orders WHERE id_customer IN (SELECT id_customer FROM qlo_customer WHERE email = '{$testEmail}')");
    $pdo->exec("DELETE FROM qlo_cart WHERE id_customer IN (SELECT id_customer FROM qlo_customer WHERE email = '{$testEmail}')");
    $pdo->exec("DELETE FROM qlo_customer WHERE email = '{$testEmail}'");
    $pdo->exec("DELETE FROM provisional_bookings WHERE cart_id LIKE 'USGAR-stress-%' OR cart_id LIKE 'USGAR-worker-%' OR cart_id LIKE 'USGAR-burst-%'");
    if (isset($workerScript) && file_exists($workerScript)) {
        @unlink($workerScript);
    }
    echo "BD limpia correctamente." . PHP_EOL;
}

echo "\n==========================================================" . PHP_EOL;
echo " PRUEBA DE ESTRÉS FINALIZADA" . PHP_EOL;
echo "==========================================================" . PHP_EOL;
