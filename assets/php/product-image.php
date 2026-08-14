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

// Re-encode to plain baseline JPEG (flattened onto white, since JPEG has
// no alpha channel) using GD. Our source files are indexed/palette PNGs;
// JPEG is the most universally-accepted format for product feed images
// and sidesteps any validator strictness around PNG variants entirely.
// Falls back to serving the raw file if GD is unavailable or fails.
if (function_exists('imagecreatefromstring')) {
    $data = file_get_contents($file);
    $img = @imagecreatefromstring($data);
    if ($img !== false) {
        imagepalettetotruecolor($img);
        $width = imagesx($img);
        $height = imagesy($img);
        $flat = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefill($flat, 0, 0, $white);
        imagealphablending($flat, true);
        imagecopy($flat, $img, 0, 0, 0, 0, $width, $height);
        imagedestroy($img);
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=604800');
        imagejpeg($flat, null, 90);
        imagedestroy($flat);
        exit;
    }
}

$mimes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];
header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('Cache-Control: public, max-age=604800');
readfile($file);
