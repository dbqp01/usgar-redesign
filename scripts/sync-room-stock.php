<?php
// Sincroniza el stock de habitaciones en QloApps prod con el inventario oficial
// del cliente (10/08/2026): Matrimonial 3, Doble 8, Triple 3, Familiar 3.
// El stock = COUNT(*) de qlo_htl_room_information por id_product (lo lee
// QloAppAdapter::getAvailableRooms). Idempotente: solo inserta/borra el delta.
//
// Uso:
//   php scripts/sync-room-stock.php            # dry-run: muestra el delta
//   php scripts/sync-room-stock.php --apply    # aplica los cambios
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app');

use App\Core\Database;

// Inventario oficial del cliente: nombre del room type => cantidad de habitaciones.
// Los nombres vienen de qlo_product_lang (id_lang=1).
$TARGETS = [
    'Matrimonial Superior' => 3,
    'Doble Superior'       => 8,
    'Triple Estándar'      => 3,
    'Familiar Superior'    => 3,
];

// room_num base por tipo (solo se usa si el tipo no tiene ninguna habitacion).
$BASE_ROOM_NUM = [
    'Matrimonial Superior' => 1,
    'Doble Superior'       => 101,
    'Triple Estándar'      => 201,
    'Familiar Superior'    => 301,
];

$apply = in_array('--apply', $argv, true);

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmtType = $pdo->prepare("
    SELECT rt.id, rt.id_product, pl.name
    FROM qlo_htl_room_type rt
    INNER JOIN qlo_product p ON p.id_product = rt.id_product
    INNER JOIN qlo_product_lang pl ON pl.id_product = rt.id_product AND pl.id_lang = 1
    WHERE p.active = 1
");
$stmtType->execute();
$types = [];
foreach ($stmtType->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $types[$r['name']] = (int) $r['id_product'];
}

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM qlo_htl_room_information WHERE id_product = ?");
$stmtMaxNum = $pdo->prepare("SELECT MAX(CAST(room_num AS UNSIGNED)) FROM qlo_htl_room_information WHERE id_product = ?");
$stmtInsert = $pdo->prepare("
    INSERT INTO qlo_htl_room_information (id_product, id_hotel, room_num, id_status, floor, comment, date_add, date_upd)
    VALUES (?, 1, ?, 1, '1', '', NOW(), NOW())
");
$stmtRefs = $pdo->prepare("
    SELECT COUNT(*) FROM qlo_htl_booking_detail
    WHERE id_room = ? AND is_cancelled = 0 AND is_refunded = 0
");

$toInsert = []; // [id_product => [room_num, ...]]
$toDelete = []; // [id_room => id_product]

foreach ($TARGETS as $name => $target) {
    $idProduct = $types[$name] ?? null;
    if ($idProduct === null) {
        echo "SKIP: room type '$name' no encontrado en QloApps\n";
        continue;
    }
    $stmtCount->execute([$idProduct]);
    $current = (int) $stmtCount->fetchColumn();
    $delta = $target - $current;
    if ($delta === 0) {
        echo "OK: $name = $current (sin cambios)\n";
        continue;
    }
    if ($delta > 0) {
        $stmtMaxNum->execute([$idProduct]);
        $next = (int) $stmtMaxNum->fetchColumn();
        if ($next === 0) $next = $BASE_ROOM_NUM[$name] - 1;
        $nums = [];
        for ($i = 1; $i <= $delta; $i++) {
            $nums[] = $next + $i;
        }
        $toInsert[$idProduct] = $nums;
        echo "INSERT +$delta: $name ($current -> $target) room_num=" . implode(',', $nums) . "\n";
    } else {
        // Borrar los extras: los de mayor id, nunca los referenciados por bookings activos.
        $stmt = $pdo->prepare("
            SELECT id FROM qlo_htl_room_information WHERE id_product = ? ORDER BY id DESC LIMIT ?
        ");
        $stmt->execute([$idProduct, -$delta]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $idRoom) {
            $stmtRefs->execute([$idRoom]);
            if ((int) $stmtRefs->fetchColumn() > 0) {
                echo "SKIP delete: id_room=$idRoom tiene bookings activos\n";
                continue;
            }
            $toDelete[] = (int) $idRoom;
        }
        echo "DELETE " . count($toDelete) . " de $name ($current -> $target)\n";
    }
}

if (!$apply) {
    echo "\nDRY-RUN: no se aplico nada. Usa --apply para escribir.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $stmtDelete = $pdo->prepare("DELETE FROM qlo_htl_room_information WHERE id = ?");
    foreach ($toDelete as $idRoom) {
        $stmtDelete->execute([$idRoom]);
    }
    foreach ($toInsert as $idProduct => $nums) {
        foreach ($nums as $num) {
            $stmtInsert->execute([$idProduct, (string) $num]);
        }
    }
    $pdo->commit();
    echo "\nAPLICADO: " . count($toInsert) . " inserciones, " . count($toDelete) . " borrados.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
