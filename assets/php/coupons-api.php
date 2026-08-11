<?php
/**
 * Yuma Electrónica — coupons API (MySQL-backed).
 *
 * Actions (POST, JSON body, field "action"):
 *  - validate : public, called at checkout {code, subtotal}
 *  - list     : admin only, returns all coupons
 *  - create   : admin only
 *  - toggle   : admin only, enable/disable a coupon
 *  - delete   : admin only
 */
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

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

$in = json_decode(file_get_contents('php://input'), true);
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

function rowToCoupon($row) {
    return [
        'id' => (int) $row['id'],
        'code' => $row['code'],
        'type' => $row['type'],
        'value' => (float) $row['value'],
        'label' => $row['label'],
        'minSubtotal' => (float) $row['min_subtotal'],
        'active' => (bool) $row['active'],
        'createdAt' => $row['created_at'],
    ];
}

try {
    $pdo = ye_db();
    $action = $in['action'] ?? '';

    if ($action === 'validate') {
        $code = strtoupper(trim($in['code'] ?? ''));
        $subtotal = (float) ($in['subtotal'] ?? 0);
        if (!$code) {
            echo json_encode(['ok' => true, 'valid' => false, 'error' => 'Introduce un código.']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM coupons WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        if (!$row || !$row['active']) {
            echo json_encode(['ok' => true, 'valid' => false, 'error' => 'Este código no es válido.']);
            exit;
        }
        if ($subtotal < (float) $row['min_subtotal']) {
            echo json_encode(['ok' => true, 'valid' => false, 'error' => 'Este código requiere un pedido mínimo de ' . number_format((float) $row['min_subtotal'], 2, ',', '.') . ' €.']);
            exit;
        }
        echo json_encode(['ok' => true, 'valid' => true, 'coupon' => rowToCoupon($row)]);
        exit;
    }

    if ($action === 'list') {
        requireAdmin($in);
        $stmt = $pdo->query("SELECT * FROM coupons ORDER BY created_at DESC");
        echo json_encode(['ok' => true, 'coupons' => array_map('rowToCoupon', $stmt->fetchAll())]);
        exit;
    }

    if ($action === 'create') {
        requireAdmin($in);
        $code = strtoupper(trim($in['code'] ?? ''));
        $type = ($in['type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $value = (float) ($in['value'] ?? 0);
        $label = trim((string) ($in['label'] ?? ''));
        $minSubtotal = (float) ($in['minSubtotal'] ?? 0);
        if (!$code || $value <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'missing_fields']);
            exit;
        }
        if ($type === 'percent' && $value > 100) $value = 100;
        try {
            $stmt = $pdo->prepare("INSERT INTO coupons (code, type, value, label, min_subtotal, active) VALUES (?,?,?,?,?,1)");
            $stmt->execute([$code, $type, $value, $label ?: null, $minSubtotal]);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'duplicate_code']);
            exit;
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'toggle') {
        requireAdmin($in);
        $id = (int) ($in['id'] ?? 0);
        $active = !empty($in['active']) ? 1 : 0;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'missing_fields']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE coupons SET active = ? WHERE id = ?");
        $stmt->execute([$active, $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        requireAdmin($in);
        $id = (int) ($in['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'missing_fields']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM coupons WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
