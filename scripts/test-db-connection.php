<?php
declare(strict_types=1);

// scripts/test-db-connection.php - Utility script to verify MySQL connection and table structures in the redesigned app

require_once __DIR__ . '/../src/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src');

use App\Core\Config;
use App\Core\Database;
use App\Services\QloAppService;

echo "=== USGAR Redesign - probador de conexión de base de datos ===\n";

Config::boot();
$db = Database::getInstance();
$pdo = $db->getConnection();

if (!$pdo) {
    echo "❌ ERROR: No se pudo conectar a la base de datos.\n";
    echo "Por favor verifica las credenciales DB_* en tu archivo .env\n";
    exit(1);
}

echo "✅ Conexión establecida con éxito.\n\n";

try {
    echo "1. Buscando hoteles activos (qlo_htl_branch_info)...\n";
    $stmt = $pdo->query("
        SELECT b.id, bl.hotel_name, b.active 
        FROM qlo_htl_branch_info b
        LEFT JOIN qlo_htl_branch_info_lang bl ON bl.id = b.id AND bl.id_lang = 1
    ");
    $hotels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($hotels) === 0) {
        echo "   ⚠️ Advertencia: No se encontraron hoteles en qlo_htl_branch_info.\n";
    } else {
        foreach ($hotels as $hotel) {
            echo "   - [ID: {$hotel['id']}] Name: {$hotel['hotel_name']} (Active: {$hotel['active']})\n";
        }
    }
} catch (PDOException $e) {
    echo "   ❌ ERROR al leer qlo_htl_branch_info: " . $e->getMessage() . "\n";
}

echo "\n";

try {
    echo "2. Buscando habitaciones utilizando QloAppService...\n";
    $qloApp = new QloAppService($pdo);
    $rooms = $qloApp->getAvailableRooms(date('Y-m-d'), date('Y-m-d', strtotime('+1 day')), 1);
    
    if (count($rooms) === 0) {
        echo "   ⚠️ Advertencia: No se encontraron habitaciones activas disponibles para el hotel ID 1.\n";
    } else {
        foreach ($rooms as $room) {
            echo "   - [ID: {$room['id_room_type']}] Name: {$room['room_name']} | Price: \${$room['price']} USD | Available: {$room['available_qty']}\n";
        }
    }
} catch (Exception $e) {
    echo "   ❌ ERROR al leer habitaciones: " . $e->getMessage() . "\n";
}

echo "\n=== Prueba completada ===\n";
