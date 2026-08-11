<?php
/**
 * Yuma Electrónica — orders API (MySQL-backed).
 *
 * Actions (POST, JSON body, field "action"):
 *  - create        : public, called at checkout
 *  - lookup        : public, requires orderNumber + email (order tracking page)
 *  - list          : admin only (adminKey), returns all orders
 *  - update-status : admin only (adminKey), sets statusOverride for one order
 */
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

// Reuses the same password shown in the admin panel's own source — this
// site has no user accounts/roles backend, so this mirrors the security
// model already in place there (obscure URL + client-side password).
define('YE_ADMIN_KEY', 'e7Li6M02IoyUUSCB');

$allowedHost = 'yumaelectronica.es';
$origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
if ($origin && strpos(parse_url($origin, PHP_URL_HOST) ?? '', $allowedHost) === false) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
}

function requireAdmin($in) {
    if (($in['adminKey'] ?? '') !== YE_ADMIN_KEY) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
}

// Maps a DB row back to the same object shape the front-end already
// expects (matching what used to be stored in localStorage).
function rowToOrder($row) {
    return [
        'orderNumber' => $row['order_number'],
        'email' => $row['email'],
        'phone' => $row['phone'],
        'date' => $row['order_date'] ? str_replace(' ', 'T', $row['order_date']) . 'Z' : null,
        'items' => json_decode($row['items_json'] ?? '[]', true) ?: [],
        'total' => (float) $row['total'],
        'productsSubtotal' => (float) $row['products_subtotal'],
        'warrantySubtotal' => (float) $row['warranty_subtotal'],
        'removalSubtotal' => (float) $row['removal_subtotal'],
        'installationSubtotal' => (float) $row['installation_subtotal'],
        'couponCode' => $row['coupon_code'],
        'couponDiscountAmount' => (float) $row['coupon_discount'],
        'shippingMethod' => $row['shipping_method'],
        'shippingCost' => (float) $row['shipping_cost'],
        'taxRegion' => $row['tax_region'],
        'baseExTax' => (float) $row['base_ex_tax'],
        'payment' => $row['payment_method'],
        'paymentDetails' => json_decode($row['payment_details_json'] ?? '{}', true) ?: [],
        'isCompany' => (bool) $row['is_company'],
        'companyName' => $row['company_name'],
        'companyTaxId' => $row['company_tax_id'],
        'shippingName' => $row['shipping_name'],
        'contactName' => $row['contact_name'],
        'shippingAddress' => $row['shipping_address'],
        'postalCode' => $row['postal_code'],
        'city' => $row['city'],
        'province' => $row['province'],
        'billingDifferent' => (bool) $row['billing_different'],
        'billingAddress' => $row['billing_address'],
        'billingPostalCode' => $row['billing_postal_code'],
        'billingCity' => $row['billing_city'],
        'billingProvince' => $row['billing_province'],
        'notes' => $row['notes'],
        'statusOverride' => $row['status_override'] !== null ? (int) $row['status_override'] : null,
        'paymentProofName' => $row['payment_proof_name'],
        'paymentProofAt' => $row['payment_proof_at'],
    ];
}

try {
    $pdo = ye_db();
    $action = $in['action'] ?? '';

    if ($action === 'create') {
        $orderNumber = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($in['orderNumber'] ?? ''));
        $email = filter_var(trim($in['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$orderNumber || !$email) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'missing_fields']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO orders
            (order_number, email, phone, order_date, items_json, total, products_subtotal,
             warranty_subtotal, removal_subtotal, installation_subtotal, coupon_code, coupon_discount,
             shipping_method, shipping_cost, tax_region, base_ex_tax, payment_method, payment_details_json,
             is_company, company_name, company_tax_id, shipping_name, contact_name, shipping_address,
             postal_code, city, province, billing_different, billing_address, billing_postal_code,
             billing_city, billing_province, notes)
            VALUES (:order_number,:email,:phone,:order_date,:items_json,:total,:products_subtotal,
             :warranty_subtotal,:removal_subtotal,:installation_subtotal,:coupon_code,:coupon_discount,
             :shipping_method,:shipping_cost,:tax_region,:base_ex_tax,:payment_method,:payment_details_json,
             :is_company,:company_name,:company_tax_id,:shipping_name,:contact_name,:shipping_address,
             :postal_code,:city,:province,:billing_different,:billing_address,:billing_postal_code,
             :billing_city,:billing_province,:notes)");
        $stmt->execute([
            ':order_number' => $orderNumber,
            ':email' => $email,
            ':phone' => $in['phone'] ?? null,
            ':order_date' => date('Y-m-d H:i:s', !empty($in['date']) ? strtotime($in['date']) : time()),
            ':items_json' => json_encode($in['items'] ?? []),
            ':total' => $in['total'] ?? 0,
            ':products_subtotal' => $in['productsSubtotal'] ?? 0,
            ':warranty_subtotal' => $in['warrantySubtotal'] ?? 0,
            ':removal_subtotal' => $in['removalSubtotal'] ?? 0,
            ':installation_subtotal' => $in['installationSubtotal'] ?? 0,
            ':coupon_code' => $in['couponCode'] ?? null,
            ':coupon_discount' => $in['couponDiscountAmount'] ?? 0,
            ':shipping_method' => $in['shippingMethod'] ?? null,
            ':shipping_cost' => $in['shippingCost'] ?? 0,
            ':tax_region' => $in['taxRegion'] ?? null,
            ':base_ex_tax' => $in['baseExTax'] ?? 0,
            ':payment_method' => $in['payment'] ?? null,
            ':payment_details_json' => json_encode($in['paymentDetails'] ?? []),
            ':is_company' => !empty($in['isCompany']) ? 1 : 0,
            ':company_name' => $in['companyName'] ?? null,
            ':company_tax_id' => $in['companyTaxId'] ?? null,
            ':shipping_name' => $in['shippingName'] ?? null,
            ':contact_name' => $in['contactName'] ?? null,
            ':shipping_address' => $in['shippingAddress'] ?? null,
            ':postal_code' => $in['postalCode'] ?? null,
            ':city' => $in['city'] ?? null,
            ':province' => $in['province'] ?? null,
            ':billing_different' => !empty($in['billingDifferent']) ? 1 : 0,
            ':billing_address' => $in['billingAddress'] ?? null,
            ':billing_postal_code' => $in['billingPostalCode'] ?? null,
            ':billing_city' => $in['billingCity'] ?? null,
            ':billing_province' => $in['billingProvince'] ?? null,
            ':notes' => $in['notes'] ?? null,
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'lookup') {
        $orderNumber = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($in['orderNumber'] ?? ''));
        $email = strtolower(trim($in['email'] ?? ''));
        if (!$orderNumber || !$email) {
            echo json_encode(['ok' => true, 'order' => null]);
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = ? AND LOWER(email) = ? LIMIT 1");
        $stmt->execute([$orderNumber, $email]);
        $row = $stmt->fetch();
        echo json_encode(['ok' => true, 'order' => $row ? rowToOrder($row) : null]);
        exit;
    }

    if ($action === 'list') {
        requireAdmin($in);
        $stmt = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC LIMIT 500");
        $orders = array_map('rowToOrder', $stmt->fetchAll());
        echo json_encode(['ok' => true, 'orders' => $orders]);
        exit;
    }

    if ($action === 'update-status') {
        requireAdmin($in);
        $orderNumber = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($in['orderNumber'] ?? ''));
        $statusIndex = isset($in['statusIndex']) ? (int) $in['statusIndex'] : null;
        if (!$orderNumber || $statusIndex === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'missing_fields']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE orders SET status_override = ? WHERE order_number = ?");
        $stmt->execute([$statusIndex, $orderNumber]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'save-proof') {
        $orderNumber = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($in['orderNumber'] ?? ''));
        $email = strtolower(trim($in['email'] ?? ''));
        if (!$orderNumber || !$email) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'missing_fields']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE orders SET payment_proof_name = ?, payment_proof_at = NOW()
            WHERE order_number = ? AND LOWER(email) = ?");
        $stmt->execute([$in['paymentProofName'] ?? '', $orderNumber, $email]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
