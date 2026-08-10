<?php
/**
 * Yuma Electrónica — order email sender.
 *
 * Called via fetch() from the checkout page (order confirmation) and from
 * the admin panel (order status updates). There is no backend/database
 * behind this site, so this script trusts the order data it is given by
 * the browser — it only sends mail, it never stores anything.
 */

header('Content-Type: application/json; charset=utf-8');

// Only accept requests coming from our own site.
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

// Very small per-IP rate limit (file-based, no database available).
function rateLimited() {
    $dir = sys_get_temp_dir() . '/yuma_mail_rl';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $key = $dir . '/' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '.txt';
    $now = time();
    $hits = [];
    if (is_file($key)) {
        $hits = array_filter(explode(',', trim(file_get_contents($key))), function ($t) use ($now) {
            return $t !== '' && ($now - (int) $t) < 3600;
        });
    }
    if (count($hits) >= 30) return true; // 30 emails/hour/IP max
    $hits[] = (string) $now;
    file_put_contents($key, implode(',', $hits));
    return false;
}
if (rateLimited()) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
}

$type = $data['type'] ?? '';
$email = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$orderNumber = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($data['orderNumber'] ?? ''));

if (!$email || !$orderNumber || !in_array($type, ['confirmation', 'status'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function money($n) { return number_format((float) $n, 2, ',', '.') . '&nbsp;€'; }

$shopName = 'Yuma Electrónica';
$shopEmail = 'soporte@yumaelectronica.es';
$shopPhone = '+34 639 42 59 32';
$trackingUrl = 'https://www.yumaelectronica.es/estado-pedido.html';

$shippingName = h($data['shippingName'] ?? '');
$itemsRows = '';
if (!empty($data['items']) && is_array($data['items'])) {
    foreach ($data['items'] as $item) {
        $itemsRows .= '<tr>'
            . '<td style="padding:8px 0;border-bottom:1px solid #eee;">' . h($item['name'] ?? '') . ' × ' . h($item['qty'] ?? 1) . '</td>'
            . '<td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;">' . money(($item['unitPrice'] ?? 0) * ($item['qty'] ?? 1)) . '</td>'
            . '</tr>';
    }
}

if ($type === 'confirmation') {
    $subject = 'Confirmación de tu pedido ' . $orderNumber . ' — ' . $shopName;
    $intro = '<p style="margin:0 0 16px;">¡Gracias por tu compra, ' . $shippingName . '! Hemos recibido tu pedido y lo estamos procesando.</p>';
} else {
    $statusLabel = h($data['statusLabel'] ?? '');
    $subject = 'Actualización de tu pedido ' . $orderNumber . ': ' . $statusLabel . ' — ' . $shopName;
    $intro = '<p style="margin:0 0 16px;">Hola ' . $shippingName . ', el estado de tu pedido <strong>' . h($orderNumber) . '</strong> ha cambiado a:</p>'
        . '<p style="margin:0 0 16px;font-size:18px;font-weight:700;color:#39b54a;">' . $statusLabel . '</p>';
}

$total = money($data['total'] ?? 0);

$body = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#1f2a24;">'
    . '<div style="background:#39b54a;padding:20px 24px;border-radius:12px 12px 0 0;">'
    . '<span style="color:#fff;font-size:20px;font-weight:800;">' . $shopName . '</span>'
    . '</div>'
    . '<div style="border:1px solid #eee;border-top:0;padding:24px;border-radius:0 0 12px 12px;">'
    . $intro
    . '<p style="margin:0 0 4px;color:#666;font-size:13px;">Número de pedido</p>'
    . '<p style="margin:0 0 16px;font-weight:700;">' . h($orderNumber) . '</p>'
    . ($itemsRows ? '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">' . $itemsRows . '</table>' : '')
    . '<p style="margin:0 0 16px;text-align:right;font-size:16px;font-weight:700;">Total: ' . $total . '</p>'
    . '<a href="' . $trackingUrl . '" style="display:inline-block;background:#39b54a;color:#fff;text-decoration:none;padding:10px 20px;border-radius:8px;font-weight:700;">Seguir mi pedido</a>'
    . '<p style="margin:24px 0 0;font-size:13px;color:#666;">¿Dudas? Escríbenos a <a href="mailto:' . $shopEmail . '">' . $shopEmail . '</a> o llama al ' . $shopPhone . '.</p>'
    . '</div>'
    . '</div>';

$headers = "MIME-Version: 1.0\r\n"
    . "Content-Type: text/html; charset=UTF-8\r\n"
    . "From: {$shopName} <{$shopEmail}>\r\n"
    . "Reply-To: {$shopEmail}\r\n";

$sentToCustomer = @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);

// Notify the shop team of new orders too — with no real backend, this is
// how the store finds out about orders placed on a customer's own device.
if ($type === 'confirmation') {
    $adminSubject = 'Nuevo pedido ' . $orderNumber . ' (' . $total . ')';
    $adminBody = '<p>Nuevo pedido recibido.</p>'
        . '<p><strong>Cliente:</strong> ' . $shippingName . ' (' . h($email) . ')</p>'
        . '<p><strong>Pedido:</strong> ' . h($orderNumber) . '</p>'
        . ($itemsRows ? '<table style="width:100%;border-collapse:collapse;">' . $itemsRows . '</table>' : '')
        . '<p><strong>Total:</strong> ' . $total . '</p>';
    @mail($shopEmail, '=?UTF-8?B?' . base64_encode($adminSubject) . '?=', $adminBody, $headers);
}

echo json_encode(['ok' => (bool) $sentToCustomer]);
