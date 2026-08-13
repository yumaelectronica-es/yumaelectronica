<?php
/**
 * Yuma Electrónica — serves product images for the Google Merchant Center
 * feed via a dynamic (non-static-asset) response.
 *
 * Hostinger's CDN auto-converts static image requests to WebP whenever the
 * client's Accept header allows it (even Google's crawler), regardless of
 * the requested file's own extension — Merchant Center rejects WebP for
 * image_link. PHP responses are treated as "DYNAMIC" by the CDN and don't
 * go through that image-optimization pipeline, so this proxy sidesteps it.
 */
$path = $_GET['p'] ?? '';
$path = str_replace('\\', '/', $path);
// Only allow simple relative paths under assets/img/products/, no traversal.
if (!preg_match('#^[A-Za-z0-9_\-\./]+\.(png|jpe?g|gif)$#', $path) || strpos($path, '..') !== false) {
    http_response_code(400);
    exit('Bad request');
}

$file = __DIR__ . '/../img/products/' . $path;
if (!is_file($file)) {
    http_response_code(404);
    exit('Not found');
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mimes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];

header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('Cache-Control: public, max-age=604800');
readfile($file);
