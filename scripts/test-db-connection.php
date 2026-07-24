<?php
declare(strict_types=1);

// test-db-connection.php - Utility script to verify MySQL connection and table structures

require_once __DIR__ . '/../public/api/db.php';
require_once __DIR__ . '/../public/api/qloapp/QloAppReader.php';

echo "=== probador de conexion de base de datos ===\n";

$pdo = getDbConnection();

if (!$pdo) {
    echo " ERROR: No se pudo conectar a la base de datos.\n";
    echo "Por favor verifica las credenciales DB_* en tu archivo .env\n";
    exit(1);
}

echo " Conexion establecida con exito.\n\n";

try {
    echo "1. Buscando hoteles activos (qlo_htl_branch_info)...\n";
    $stmt = $pdo->query("
        SELECT b.id, bl.hotel_name, b.active 
        FROM qlo_htl_branch_info b
        LEFT JOIN qlo_htl_branch_info_lang bl ON bl.id = b.id AND bl.id_lang = 1
    ");
    $hotels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($hotels) === 0) {
        echo "   ️ Advertencia: No se encontraron hoteles en qlo_htl_branch_info.\n";
    } else {
        foreach ($hotels as $hotel) {
            echo "   - [ID: {$hotel['id']}] Name: {$hotel['hotel_name']} (Active: {$hotel['active']})\n";
        }
    }
} catch (PDOException $e) {
    echo "    ERROR al leer qlo_htl_branch_info: " . $e->getMessage() . "\n";
}

echo "\n";

try {
    echo "2. Buscando tipos de habitacion activos (qlo_htl_room_type)...\n";
    $reader = new QloAppReader();
    $rooms = $reader->getAvailableRooms(date('Y-m-d'), date('Y-m-d', strtotime('+1 day')), 1);
    
    if ($rooms === null) {
        echo "    ERROR: getAvailableRooms retorno null.\n";
    } elseif (count($rooms) === 0) {
        echo "   ️ Advertencia: No se encontraron habitaciones activas para el hotel ID 1.\n";
    } else {
        foreach ($rooms as $room) {
            echo "   - [ID: {$room['id_room_type']}] Name: {$room['room_name']} | Price: \${$room['price']} USD\n";
        }
    }
} catch (Exception $e) {
    echo "    ERROR al leer habitaciones: " . $e->getMessage() . "\n";
}

echo "\n=== Prueba completada ===\n";
