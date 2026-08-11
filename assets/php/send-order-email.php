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

require __DIR__ . '/phpmailer/Exception.php';
require __DIR__ . '/phpmailer/PHPMailer.php';
require __DIR__ . '/phpmailer/SMTP.php';
require __DIR__ . '/invoice-pdf.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Sends via authenticated SMTP (assets/php/mail-config.php, uploaded
 * manually to the server — never committed to Git) when available, since
 * that is delivered far more reliably to Gmail/Outlook/Yahoo than PHP's
 * raw mail(). Falls back to mail() (without the PDF attachment) if the
 * config file isn't there yet.
 */
function sendMail($to, $fromName, $fromEmail, $subject, $htmlBody, $attachment = null) {
    $configFile = __DIR__ . '/mail-config.php';
    if (is_file($configFile)) {
        $cfg = require $configFile;
        $mailer = new PHPMailer(true);
        try {
            $mailer->isSMTP();
            $mailer->Host = $cfg['host'];
            $mailer->Port = $cfg['port'];
            $mailer->SMTPSecure = $cfg['encryption'];
            $mailer->SMTPAuth = true;
            $mailer->Username = $cfg['username'];
            $mailer->Password = $cfg['password'];
            $mailer->CharSet = 'UTF-8';
            $mailer->setFrom($cfg['username'], $fromName);
            $mailer->addReplyTo($fromEmail);
            $mailer->addAddress($to);
            $mailer->isHTML(true);
            $mailer->Subject = $subject;
            $mailer->Body = $htmlBody;
            if ($attachment) {
                $mailer->addStringAttachment($attachment['content'], $attachment['name'], 'base64', $attachment['type']);
            }
            return $mailer->send();
        } catch (PHPMailerException $e) {
            return false;
        }
    }
    $headers = "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "From: {$fromName} <{$fromEmail}>\r\n"
        . "Reply-To: {$fromEmail}\r\n";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headers);
}

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
$order = json_decode($raw, true);
if (!is_array($order)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
}

$type = $order['type'] ?? '';
$email = filter_var(trim($order['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$orderNumber = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($order['orderNumber'] ?? ''));
$order['orderNumber'] = $orderNumber;

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
$siteUrl = 'https://www.yumaelectronica.es';
$logoUrl = $siteUrl . '/assets/img/logo.png';
$trackingUrl = $siteUrl . '/estado-pedido.html';

$shippingName = h($order['shippingName'] ?? '');

// Itemized rows, with product thumbnail when available.
$itemsRows = '';
if (!empty($order['items']) && is_array($order['items'])) {
    foreach ($order['items'] as $item) {
        $img = !empty($item['image']) ? $siteUrl . '/' . ltrim($item['image'], '/') : '';
        $qty = (int) ($item['qty'] ?? 1);
        $unit = (float) ($item['unitPrice'] ?? 0);
        $itemsRows .= '<tr>'
            . '<td style="padding:12px 0;border-bottom:1px solid #eee;width:56px;">'
                . ($img ? '<img src="' . h($img) . '" width="48" height="48" style="width:48px;height:48px;object-fit:contain;border:1px solid #eee;border-radius:8px;background:#fafafa;" alt="">' : '')
            . '</td>'
            . '<td style="padding:12px 8px;border-bottom:1px solid #eee;font-size:13px;color:#1f2a24;">' . h($item['name'] ?? '') . '<br><span style="color:#888;font-size:12px;">Cantidad: ' . $qty . ' × ' . money($unit) . '</span></td>'
            . '<td style="padding:12px 0;border-bottom:1px solid #eee;text-align:right;font-size:13px;font-weight:700;color:#1f2a24;white-space:nowrap;">' . money($unit * $qty) . '</td>'
            . '</tr>';
    }
}

// Totals breakdown rows.
$totalsRows = '';
function totalsRow($label, $amount, $bold = false) {
    $weight = $bold ? '700' : '400';
    return '<tr><td style="padding:3px 0;font-size:13px;color:#555;font-weight:' . $weight . ';">' . h($label) . '</td>'
        . '<td style="padding:3px 0;font-size:13px;text-align:right;font-weight:' . $weight . ';color:#1f2a24;">' . money($amount) . '</td></tr>';
}
if (!empty($order['warrantySubtotal'])) $totalsRows .= totalsRow('Garantía ampliada', $order['warrantySubtotal']);
if (!empty($order['removalSubtotal'])) $totalsRows .= totalsRow('Retirada de equipo antiguo', $order['removalSubtotal']);
if (!empty($order['installationSubtotal'])) $totalsRows .= totalsRow('Instalación', $order['installationSubtotal']);
if (!empty($order['couponDiscountAmount'])) $totalsRows .= totalsRow('Descuento (' . ($order['couponCode'] ?? '') . ')', -$order['couponDiscountAmount']);
$totalsRows .= totalsRow(($order['shippingCost'] ?? 0) > 0 ? 'Envío' : 'Envío (gratis)', $order['shippingCost'] ?? 0);

$total = (float) ($order['total'] ?? 0);

// Shipping / billing address block.
$shipAddr = h(($order['shippingAddress'] ?? '') . ', ' . ($order['postalCode'] ?? '') . ' ' . ($order['city'] ?? '') . ', ' . ($order['province'] ?? ''));

// Payment instructions (mirrors what was already shown at checkout).
$pd = $order['paymentDetails'] ?? [];
if (($order['payment'] ?? '') === 'bizum') {
    $paymentBlock = '<p style="margin:0 0 4px;font-size:13px;"><strong>Bizum</strong> al número <strong>' . h($pd['bizumNumber'] ?? '') . '</strong></p>'
        . '<p style="margin:0;font-size:13px;color:#555;">Titular: ' . h($pd['bizumBeneficiary'] ?? '') . '</p>';
} else {
    $paymentBlock = '<p style="margin:0 0 4px;font-size:13px;"><strong>Transferencia bancaria</strong></p>'
        . '<p style="margin:0;font-size:13px;color:#555;">IBAN: ' . h($pd['iban'] ?? '') . '<br>'
        . 'BIC/SWIFT: ' . h($pd['bic'] ?? '') . ' · Banco: ' . h($pd['bankName'] ?? '') . '<br>'
        . 'Titular: ' . h($pd['beneficiary'] ?? '') . '</p>';
}
$paymentBlock .= '<p style="margin:8px 0 0;font-size:12px;color:#888;">Indica el número de pedido <strong>' . h($orderNumber) . '</strong> como concepto.</p>';

if ($type === 'confirmation') {
    $subject = 'Confirmación de tu pedido ' . $orderNumber . ' — ' . $shopName;
    $introTitle = '¡Gracias por tu compra' . ($shippingName ? ', ' . $shippingName : '') . '!';
    $introText = 'Hemos recibido tu pedido y adjuntamos tu factura proforma. Te avisaremos por email en cada paso del proceso.';
} else {
    $statusLabel = h($order['statusLabel'] ?? '');
    $subject = 'Actualización de tu pedido ' . $orderNumber . ': ' . $statusLabel . ' — ' . $shopName;
    $introTitle = 'Tu pedido ha avanzado';
    $introText = 'El estado de tu pedido <strong>' . h($orderNumber) . '</strong> ha cambiado a:';
}

// --- Professional HTML email, branded header (logo on green) + footer ---
$body = '<div style="background:#f4f6f5;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;">'
. '<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.06);">'

    // Header
    . '<div style="background:linear-gradient(135deg,#39b54a,#2e9a3d);padding:22px 28px;text-align:center;">'
        . '<img src="' . h($logoUrl) . '" alt="Yuma Electrónica" height="28" style="height:28px;">'
    . '</div>'

    // Body
    . '<div style="padding:28px 28px 8px;">'
        . '<h1 style="margin:0 0 8px;font-size:20px;color:#1f2a24;">' . h($introTitle) . '</h1>'
        . '<p style="margin:0 0 20px;font-size:14px;line-height:1.5;color:#555;">' . ($type === 'confirmation' ? h($introText) : $introText) . '</p>'
        . ($type !== 'confirmation'
            ? '<p style="margin:0 0 20px;display:inline-block;background:#eafaed;color:#218838;font-weight:700;font-size:15px;padding:8px 16px;border-radius:999px;">' . $statusLabel . '</p>'
            : '')

        . '<table role="presentation" style="width:100%;border-collapse:collapse;margin-bottom:6px;">'
            . '<tr><td style="font-size:12px;color:#888;padding-bottom:2px;">Número de pedido</td></tr>'
            . '<tr><td style="font-size:15px;font-weight:700;color:#1f2a24;padding-bottom:16px;">' . h($orderNumber) . '</td></tr>'
        . '</table>'

        . ($itemsRows ? '<table role="presentation" style="width:100%;border-collapse:collapse;margin-bottom:8px;">' . $itemsRows . '</table>' : '')

        . '<table role="presentation" style="width:100%;border-collapse:collapse;margin:10px 0 4px;">'
            . $totalsRows
            . '<tr><td colspan="2" style="border-top:1px solid #eee;padding-top:8px;"></td></tr>'
            . '<tr><td style="padding:4px 0;font-size:16px;font-weight:800;color:#1f2a24;">Total</td>'
            . '<td style="padding:4px 0;font-size:16px;font-weight:800;text-align:right;color:#39b54a;">' . money($total) . '</td></tr>'
        . '</table>'

        . '<div style="margin-top:20px;padding:16px;background:#f8f9fa;border-radius:10px;">'
            . '<p style="margin:0 0 10px;font-size:12px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.03em;">Enviar a</p>'
            . '<p style="margin:0;font-size:13px;color:#1f2a24;">' . $shippingName . '<br>' . $shipAddr . '</p>'
        . '</div>'

        . '<div style="margin-top:12px;padding:16px;background:#f8f9fa;border-radius:10px;">'
            . '<p style="margin:0 0 10px;font-size:12px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.03em;">Forma de pago</p>'
            . $paymentBlock
        . '</div>'

        . ($type === 'confirmation'
            ? '<p style="margin:20px 0 0;font-size:13px;color:#555;">📎 Adjuntamos tu <strong>factura proforma</strong> en PDF con todos los detalles de tu compra.</p>'
            : '')

        . '<div style="text-align:center;margin:26px 0 10px;">'
            . '<a href="' . h($trackingUrl) . '" style="display:inline-block;background:#39b54a;color:#fff;text-decoration:none;padding:12px 28px;border-radius:999px;font-weight:700;font-size:14px;">Seguir mi pedido</a>'
        . '</div>'
        . '<p style="text-align:center;margin:0 0 8px;font-size:12px;color:#999;">¿Dudas? Escríbenos a <a href="mailto:' . $shopEmail . '" style="color:#39b54a;">' . $shopEmail . '</a> o llama al ' . $shopPhone . '</p>'
    . '</div>'

    // Footer
    . '<div style="background:#16171a;padding:24px 28px;text-align:center;">'
        . '<p style="margin:0 0 6px;font-size:14px;font-weight:800;color:#ffffff;">Yuma Electrónica</p>'
        . '<p style="margin:0 0 4px;font-size:11.5px;color:#9aa0a6;">Yuma Electrónica S.L. · CIF B30290647</p>'
        . '<p style="margin:0 0 4px;font-size:11.5px;color:#9aa0a6;">C. Libertador Simón Bolívar 2, Sur, 14013, Córdoba, España</p>'
        . '<p style="margin:12px 0 0;font-size:11px;color:#6b7075;">Este es un correo automático relacionado con tu pedido en www.yumaelectronica.es</p>'
    . '</div>'

. '</div>'
. '</div>';

// Build the proforma invoice PDF for confirmation emails.
$attachment = null;
if ($type === 'confirmation') {
    try {
        $pdfContent = buildProformaInvoicePdf($order);
        $attachment = [
            'content' => base64_encode($pdfContent),
            'name' => 'Factura-proforma-' . $orderNumber . '.pdf',
            'type' => 'application/pdf'
        ];
    } catch (Exception $e) {
        $attachment = null; // Don't block the email if PDF generation fails.
    }
}

$sentToCustomer = sendMail($email, $shopName, $shopEmail, $subject, $body, $attachment);

// Notify the shop team of new orders too — with no real backend, this is
// how the store finds out about orders placed on a customer's own device.
if ($type === 'confirmation') {
    $adminSubject = 'Nuevo pedido ' . $orderNumber . ' (' . money($total) . ')';
    $adminBody = '<p>Nuevo pedido recibido.</p>'
        . '<p><strong>Cliente:</strong> ' . $shippingName . ' (' . h($email) . ')</p>'
        . '<p><strong>Pedido:</strong> ' . h($orderNumber) . '</p>'
        . ($itemsRows ? '<table style="width:100%;border-collapse:collapse;">' . $itemsRows . '</table>' : '')
        . '<p><strong>Total:</strong> ' . money($total) . '</p>';
    sendMail($shopEmail, $shopName, $shopEmail, $adminSubject, $adminBody, $attachment);
}

echo json_encode(['ok' => (bool) $sentToCustomer]);
