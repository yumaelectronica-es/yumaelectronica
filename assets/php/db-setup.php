<?php
/**
 * Yuma Electrónica — one-time database setup. Creates the orders table
 * if it doesn't exist yet. Safe to run more than once (IF NOT EXISTS).
 * Delete this file once the table has been created.
 */
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

try {
    $pdo = ye_db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_number VARCHAR(32) NOT NULL UNIQUE,
        email VARCHAR(190) NOT NULL,
        phone VARCHAR(40) NULL,
        order_date DATETIME NOT NULL,
        items_json TEXT NOT NULL,
        total DECIMAL(10,2) NOT NULL DEFAULT 0,
        products_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
        warranty_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
        removal_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
        installation_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
        coupon_code VARCHAR(40) NULL,
        coupon_discount DECIMAL(10,2) NOT NULL DEFAULT 0,
        shipping_method VARCHAR(20) NULL,
        shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
        tax_region VARCHAR(20) NULL,
        base_ex_tax DECIMAL(10,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(20) NULL,
        payment_details_json TEXT NULL,
        is_company TINYINT(1) NOT NULL DEFAULT 0,
        company_name VARCHAR(190) NULL,
        company_tax_id VARCHAR(40) NULL,
        shipping_name VARCHAR(190) NULL,
        contact_name VARCHAR(190) NULL,
        shipping_address VARCHAR(255) NULL,
        postal_code VARCHAR(10) NULL,
        city VARCHAR(100) NULL,
        province VARCHAR(100) NULL,
        billing_different TINYINT(1) NOT NULL DEFAULT 0,
        billing_address VARCHAR(255) NULL,
        billing_postal_code VARCHAR(10) NULL,
        billing_city VARCHAR(100) NULL,
        billing_province VARCHAR(100) NULL,
        notes TEXT NULL,
        status_override TINYINT NULL,
        payment_proof_name VARCHAR(255) NULL,
        payment_proof_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (email),
        INDEX (order_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Idempotent column additions for the payment-proof approval workflow
    // (orders table already existed before this feature was added).
    $existingCols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('payment_proof_status', $existingCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN payment_proof_status VARCHAR(20) NULL AFTER payment_proof_at");
    }
    if (!in_array('payment_proof_rejection_reason', $existingCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN payment_proof_rejection_reason VARCHAR(255) NULL AFTER payment_proof_status");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS coupons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(40) NOT NULL UNIQUE,
        type VARCHAR(10) NOT NULL DEFAULT 'percent',
        value DECIMAL(10,2) NOT NULL,
        label VARCHAR(190) NULL,
        min_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo json_encode(['ok' => true, 'message' => 'orders/coupons tables ready']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
