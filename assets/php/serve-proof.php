<?php
/**
 * Yuma Electrónica — serves a payment proof file stored outside the
 * Git-deployed webroot (see upload-proof.php). Gated by the same admin
 * key already used throughout the admin panel's client-side JS.
 */
define('YE_ADMIN_KEY', 'e7Li6M02IoyUUSCB');

$path = basename($_GET['path'] ?? ''); // basename() blocks path traversal
$key = $_GET['key'] ?? '';

if ($key !== YE_ADMIN_KEY || !$path) {
    http_response_code(403);
    exit('Forbidden');
}

$file = dirname(__DIR__, 3) . '/uploads/proofs/' . $path;
if (!is_file($file)) {
    http_response_code(404);
    exit('Not found');
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mimes = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';
$disposition = isset($_GET['download']) ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: ' . $disposition . '; filename="' . $path . '"');
header('X-Content-Type-Options: nosniff');
readfile($file);
