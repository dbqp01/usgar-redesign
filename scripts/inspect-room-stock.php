<?php
// Inspeccion (read-only) del stock de habitaciones en QloApps prod.
// Uso: php scripts/inspect-room-stock.php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app');

use App\Core\Config;
use App\Core\Database;

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Estructura qlo_htl_room_information ==\n";
$cols = $pdo->query("SHOW COLUMNS FROM qlo_htl_room_information")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  {$c['Field']} {$c['Type']} null=" . ($c['Null'] === 'YES' ? 'Y' : 'N') . " default=" . var_export($c['Default'], true) . "\n";
}

echo "\n== Room types (id_product, nombre, stock actual) ==\n";
$rows = $pdo->query("
    SELECT rt.id AS id_room_type, rt.id_product, pl.name,
           (SELECT COUNT(*) FROM qlo_htl_room_information ri WHERE ri.id_product = rt.id_product) AS stock
    FROM qlo_htl_room_type rt
    INNER JOIN qlo_product p ON p.id_product = rt.id_product
    INNER JOIN qlo_product_lang pl ON pl.id_product = rt.id_product AND pl.id_lang = 1
    WHERE p.active = 1
    ORDER BY rt.id
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  id_room_type={$r['id_room_type']} id_product={$r['id_product']} stock={$r['stock']} | {$r['name']}\n";
}

echo "\n== Detalle room_information (id, id_product, room_num, id_hotel, status) ==\n";
$rooms = $pdo->query("
    SELECT ri.id, ri.id_product, ri.room_num, ri.id_hotel, ri.id_status, ri.floor
    FROM qlo_htl_room_information ri
    ORDER BY ri.id_product, ri.room_num
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rooms as $r) {
    echo "  id={$r['id']} id_product={$r['id_product']} room_num={$r['room_num']} id_hotel={$r['id_hotel']} id_status={$r['id_status']} floor={$r['floor']}\n";
}

echo "\n== Referencias a id_room en bookings activos ==\n";
$refs = $pdo->query("
    SELECT bd.id_room, COUNT(*) AS n
    FROM qlo_htl_booking_detail bd
    WHERE bd.is_cancelled = 0 AND bd.is_refunded = 0
    GROUP BY bd.id_room
")->fetchAll(PDO::FETCH_ASSOC);
echo "  total filas booking_detail activas: " . count($refs) . "\n";
foreach ($refs as $r) {
    echo "  id_room={$r['id_room']} bookings={$r['n']}\n";
}
echo "\nOK\n";
