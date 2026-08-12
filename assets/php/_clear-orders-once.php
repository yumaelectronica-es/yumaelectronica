<?php
/**
 * TEMPORARY one-off cleanup script — deletes ALL rows from the orders
 * table. Not a permanent API action (a standing bulk-delete endpoint
 * gated only by the same admin key already exposed in the admin panel's
 * client-side JS would be too risky to leave in place). Run once via
 * curl, then delete this file.
 */
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

define('YE_ADMIN_KEY', 'e7Li6M02IoyUUSCB');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in) || ($in['adminKey'] ?? '') !== YE_ADMIN_KEY) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

try {
    $pdo = ye_db();
    $countBefore = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $pdo->exec("DELETE FROM orders");
    echo json_encode(['ok' => true, 'deleted' => $countBefore]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
