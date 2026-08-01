-- =============================================================================
-- USGAR Hotels - Script DDL para Idempotencia de Pagos (Hostinger MySQL)
-- Ejecutar en Hostinger phpMyAdmin o Consola MySQL
-- Base de datos: u941268346_QloApp / USGAR
-- Debe coincidir con ProvisionalBookingRepository::ensureTablesExist()
-- =============================================================================

CREATE TABLE IF NOT EXISTS `processed_payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `payment_id` VARCHAR(64) UNIQUE NOT NULL,
    `cart_id` VARCHAR(64) NOT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'approved',
    `processed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
