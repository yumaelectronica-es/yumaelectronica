<?php
/**
 * Yuma Electrónica — payment proof file upload.
 *
 * Accepts multipart/form-data (not JSON, since it carries a real file):
 * fields orderNumber, email, and file field "proof". Stored OUTSIDE the
 * Git-deployed public_html tree (same reasoning as ye-secrets for
 * credentials — Hostinger's Git deploy resets public_html to match the
 * repo on every deploy, which would otherwise wipe uploaded files), under
 * a random, unguessable name (this project has no real server-side admin
 * session, only an obscure URL + client-side password, so unguessable
 * filenames are the same pragmatic security model used everywhere else
 * here) and served back via serve-proof.php.
 */
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

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

$orderNumber = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($_POST['orderNumber'] ?? ''));
$email = strtolower(trim($_POST['email'] ?? ''));
if (!$orderNumber || !$email) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

if (empty($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'no_file']);
    exit;
}

$file = $_FILES['proof'];
$maxSize = 10 * 1024 * 1024; // 10MB
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'file_too_large']);
    exit;
}

$allowedTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
    'application/pdf' => 'pdf',
];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
if (!isset($allowedTypes[$mime])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_type']);
    exit;
}
$ext = $allowedTypes[$mime];

try {
    $pdo = ye_db();
    $check = $pdo->prepare("SELECT id FROM orders WHERE order_number = ? AND LOWER(email) = ? LIMIT 1");
    $check->execute([$orderNumber, $email]);
    if (!$check->fetch()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }

    $uploadsDir = dirname(__DIR__, 3) . '/uploads/proofs';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
    }

    $storedName = bin2hex(random_bytes(20)) . '.' . $ext;
    $destination = $uploadsDir . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'upload_failed']);
        exit;
    }

    $originalName = basename($file['name']);
    $stmt = $pdo->prepare("UPDATE orders SET payment_proof_name = ?, payment_proof_path = ?, payment_proof_at = NOW(),
        payment_proof_status = 'pending', payment_proof_rejection_reason = NULL
        WHERE order_number = ? AND LOWER(email) = ?");
    $stmt->execute([$originalName, $storedName, $orderNumber, $email]);

    echo json_encode(['ok' => true, 'path' => $storedName, 'name' => $originalName]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
