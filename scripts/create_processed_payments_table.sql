-- =============================================================================
-- USGAR Hotels - Script DDL para Idempotencia de Pagos (Hostinger MySQL)
-- Ejecutar en Hostinger phpMyAdmin o Consola MySQL
-- Base de datos: u941268346_QloApp / USGAR
-- =============================================================================

CREATE TABLE IF NOT EXISTS `processed_payments` (
    `payment_id` VARCHAR(64) NOT NULL,
    `cart_id` VARCHAR(64) NOT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'approved',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`payment_id`),
    INDEX `idx_cart_id` (`cart_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
