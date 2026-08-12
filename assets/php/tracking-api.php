<?php
/**
 * Yuma Electrónica — visits + abandoned-cart tracking API (MySQL-backed).
 *
 * Actions (POST, JSON body, field "action"):
 *  - log-visit   : public, called on every page load {path, title, visitorId}
 *  - save-cart   : public, called whenever a non-empty cart changes
 *  - clear-cart  : public, called when a cart empties or an order is placed
 *  - list-visits : admin only
 *  - list-carts  : admin only
 */
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

define('YE_ADMIN_KEY', 'e7Li6M02IoyUUSCB');
define('VISITS_MAX_ROWS', 500);

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

try {
    $pdo = ye_db();
    $action = $in['action'] ?? '';

    if ($action === 'log-visit') {
        $path = substr(trim((string) ($in['path'] ?? '')), 0, 255);
        $title = substr(trim((string) ($in['title'] ?? '')), 0, 255);
        $visitorId = substr(trim((string) ($in['visitorId'] ?? '')), 0, 40);
        if (!$path) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'missing_fields']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO visits (path, title, visitor_id) VALUES (?,?,?)");
        $stmt->execute([$path, $title ?: null, $visitorId ?: null]);
        // Cheap housekeeping so this table doesn't grow forever: occasionally
        // trim to the most recent rows instead of running a DELETE every time.
        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM visits WHERE id NOT IN (SELECT id FROM (SELECT id FROM visits ORDER BY id DESC LIMIT " . VISITS_MAX_ROWS . ") t)");
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'list-visits') {
        requireAdmin($in);
        $stmt = $pdo->query("SELECT path, title, visitor_id, created_at FROM visits ORDER BY id DESC LIMIT 150");
        $rows = array_map(function ($r) {
            return ['path' => $r['path'], 'title' => $r['title'], 'visitorId' => $r['visitor_id'], 'at' => str_replace(' ', 'T', $r['created_at']) . 'Z'];
        }, $stmt->fetchAll());
        echo json_encode(['ok' => true, 'visits' => $rows]);
        exit;
    }

    if ($action === 'save-cart') {
        $visitorId = substr(trim((string) ($in['visitorId'] ?? '')), 0, 40);
        $items = is_array($in['items'] ?? null) ? $in['items'] : [];
        $total = (float) ($in['total'] ?? 0);
        $email = trim((string) ($in['email'] ?? ''));
        if (!$visitorId || !$items) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'missing_fields']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO abandoned_carts (visitor_id, items_json, total, email)
            VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE items_json = VALUES(items_json), total = VALUES(total), email = VALUES(email)");
        $stmt->execute([$visitorId, json_encode($items), $total, $email ?: null]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'clear-cart') {
        $visitorId = substr(trim((string) ($in['visitorId'] ?? '')), 0, 40);
        if (!$visitorId) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'missing_fields']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM abandoned_carts WHERE visitor_id = ?");
        $stmt->execute([$visitorId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'list-carts') {
        requireAdmin($in);
        $stmt = $pdo->query("SELECT visitor_id, items_json, total, email, updated_at FROM abandoned_carts ORDER BY updated_at DESC LIMIT 100");
        $rows = array_map(function ($r) {
            return [
                'visitorId' => $r['visitor_id'],
                'items' => json_decode($r['items_json'] ?? '[]', true) ?: [],
                'total' => (float) $r['total'],
                'email' => $r['email'],
                'updatedAt' => str_replace(' ', 'T', $r['updated_at']) . 'Z',
            ];
        }, $stmt->fetchAll());
        echo json_encode(['ok' => true, 'carts' => $rows]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
