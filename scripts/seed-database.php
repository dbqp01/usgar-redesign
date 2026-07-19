<?php
declare(strict_types=1);

// scripts/seed-database.php - Utility script to populate the database with default USGAR Hotels rooms and settings

require_once __DIR__ . '/../src/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src');

use App\Core\Config;
use App\Core\Database;

Config::boot();
$db = Database::getInstance();
$pdo = $db->getConnection();

if (!$pdo) {
    echo "❌ ERROR: No se pudo conectar a la base de datos.\n";
    exit(1);
}

echo "=== Sembrador de Base de Datos para USGAR Hotels (Redesign) ===\n\n";

try {
    $pdo->beginTransaction();

    // 1. Insert/Update default hotel branch
    echo "-> Insertando hotel default (ID = 1)...\n";
    $stmt = $pdo->prepare("
        INSERT INTO qlo_htl_branch_info (id, id_category, email, active, latitude, longitude, map_formated_address, map_input_text, date_add, date_upd)
        VALUES (1, 1, 'cusco@usgarhotels.com', 1, -13.5186, -71.9782, 'San Pedro, Cusco, Perú', 'San Pedro, Cusco', NOW(), NOW())
        ON DUPLICATE KEY UPDATE active = 1, date_upd = NOW()
    ");
    $stmt->execute();

    // Insert hotel lang translation
    $stmtLang = $pdo->prepare("
        INSERT INTO qlo_htl_branch_info_lang (id, id_lang, hotel_name, short_description, description, policies)
        VALUES (1, 1, 'USGAR Hotels — San Pedro, Cusco', 'Hotel Boutique en Cusco', 'Hermoso hotel boutique en el centro de Cusco', 'Check-in: 12:00, Check-out: 10:30')
        ON DUPLICATE KEY UPDATE hotel_name = VALUES(hotel_name)
    ");
    $stmtLang->execute();

    // 2. Clean up old testing products & room types to avoid duplication on rerun
    echo "-> Limpiando datos de prueba anteriores...\n";
    $pdo->exec("DELETE FROM qlo_htl_room_type WHERE id_hotel = 1");

    // 3. Define the 4 standard room types from BRAND.md
    $roomTypes = [
        [
            'name' => 'Habitación Matrimonial Superior',
            'slug' => 'matrimonial-superior',
            'price' => 90.00,
            'adults' => 2,
            'max_guests' => 2,
        ],
        [
            'name' => 'Habitación Doble Superior',
            'slug' => 'doble-superior',
            'price' => 90.00,
            'adults' => 2,
            'max_guests' => 2,
        ],
        [
            'name' => 'Habitación Triple Estándar',
            'slug' => 'triple-estandar',
            'price' => 120.00,
            'adults' => 3,
            'max_guests' => 3,
        ],
        [
            'name' => 'Habitación Familiar Superior',
            'slug' => 'familiar-superior',
            'price' => 150.00,
            'adults' => 4,
            'max_guests' => 7,
        ],
    ];

    echo "-> Insertando tipos de habitación...\n";
    foreach ($roomTypes as $room) {
        // A. Insert into qlo_product
        $stmtProd = $pdo->prepare("
            INSERT INTO qlo_product (id_shop_default, id_tax_rules_group, price, active, date_add, date_upd, booking_product)
            VALUES (1, 1, :price, 1, NOW(), NOW(), 1)
        ");
        $stmtProd->execute([':price' => $room['price']]);
        $idProduct = (int)$pdo->lastInsertId();

        // B. Insert translation in qlo_product_lang
        $stmtProdLang = $pdo->prepare("
            INSERT INTO qlo_product_lang (id_product, id_shop, id_lang, name, link_rewrite)
            VALUES (:id_product, 1, 1, :name, :slug)
        ");
        $stmtProdLang->execute([
            ':id_product' => $idProduct,
            ':name' => $room['name'],
            ':slug' => $room['slug']
        ]);

        // C. Insert in qlo_product_shop
        $stmtProdShop = $pdo->prepare("
            INSERT INTO qlo_product_shop (id_product, id_shop, id_tax_rules_group, price, active, date_add, date_upd)
            VALUES (:id_product, 1, 1, :price, 1, NOW(), NOW())
        ");
        $stmtProdShop->execute([
            ':id_product' => $idProduct,
            ':price' => $room['price']
        ]);

        // D. Insert in qlo_htl_room_type
        $stmtRoomType = $pdo->prepare("
            INSERT INTO qlo_htl_room_type (id_product, id_hotel, adults, children, max_adults, max_children, max_guests, min_los, max_los, date_add, date_upd)
            VALUES (:id_product, 1, :adults, 0, :max_adults, 0, :max_guests, 1, 30, NOW(), NOW())
        ");
        $stmtRoomType->execute([
            ':id_product' => $idProduct,
            ':adults' => $room['adults'],
            ':max_adults' => $room['adults'],
            ':max_guests' => $room['max_guests']
        ]);

        echo "   + Insertada: '{$room['name']}' con precio \${$room['price']} USD (Product ID: {$idProduct})\n";
    }

    $pdo->commit();
    echo "\n✅ BASE DE DATOS SEMBRADA EXITOSAMENTE.\n";

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n❌ ERROR durante el sembrado: " . $e->getMessage() . "\n";
}
