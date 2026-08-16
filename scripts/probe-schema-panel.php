<?php
// Probe read-only: schema de qlo_htl_booking_detail y qlo_htl_room_disable_dates
require __DIR__ . '/../vendor/autoload.php';

$db = App\Core\Database::getInstance()->getConnection();
if (!$db) { echo "BD NO CONECTADA\n"; exit(1); }

foreach (['qlo_htl_booking_detail', 'qlo_htl_room_disable_dates', 'qlo_htl_room_information'] as $t) {
    echo "=== {$t} ===\n";
    $stmt = $db->prepare("SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
    $stmt->execute([$t]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $flag = $c['IS_NULLABLE'] === 'NO' && $c['COLUMN_DEFAULT'] === null && !str_contains($c['EXTRA'] ?? '', 'auto_increment') ? '  <-- NOT NULL SIN DEFAULT' : '';
        echo "  {$c['COLUMN_NAME']} {$c['COLUMN_TYPE']} null={$c['IS_NULLABLE']} default=" . var_export($c['COLUMN_DEFAULT'], true) . $flag . "\n";
    }
    echo "\n";
}

// Muestra: cómo se ven las filas reales (el patrón de confirmOrder)
echo "=== booking_detail fila 221 (patrón confirmOrder) ===\n";
$r = $db->query("SELECT * FROM qlo_htl_booking_detail WHERE id = 221")->fetch(PDO::FETCH_ASSOC);
echo $r ? json_encode($r) : 'no row';
echo "\n";
