<?php
/**
 * Yuma Electrónica — account creation welcome email.
 *
 * Called via fetch() right after a new account is saved (client-side,
 * localStorage-based accounts — there is no server-side user table). This
 * only sends a confirmation email; it never stores anything.
 */

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/phpmailer/Exception.php';
require __DIR__ . '/phpmailer/PHPMailer.php';
require __DIR__ . '/phpmailer/SMTP.php';
require __DIR__ . '/config-path.php';
use PHPMailer\PHPMailer\PHPMailer;

function sendMail($to, $fromName, $fromEmail, $subject, $htmlBody) {
    $configFile = ye_config_path('mail-config.php');
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
            return $mailer->send();
        } catch (Throwable $e) {
            @file_put_contents(__DIR__ . '/debug-log.txt', date('Y-m-d H:i:s') . ' | ACCOUNT MAIL ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
            return false;
        }
    }
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: {$fromName} <{$fromEmail}>\r\n";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headers);
}

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

function rateLimited() {
    $dir = sys_get_temp_dir() . '/yuma_mail_rl';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $key = $dir . '/account_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '.txt';
    $now = time();
    $hits = [];
    if (is_file($key)) {
        $hits = array_filter(explode(',', trim(file_get_contents($key))), function ($t) use ($now) {
            return $t !== '' && ($now - (int) $t) < 3600;
        });
    }
    if (count($hits) >= 10) return true;
    $hits[] = (string) $now;
    file_put_contents($key, implode(',', $hits));
    return false;
}
if (rateLimited()) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);
$email = filter_var(trim((string) ($in['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$fullName = trim((string) ($in['fullName'] ?? ''));
if (!$email) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

$shopName = 'Yuma Electrónica';
$shopEmail = 'soporte@yumaelectronica.es';
$siteUrl = 'https://www.yumaelectronica.es';
$logoUrl = $siteUrl . '/assets/img/logo.png';

$subject = '¡Bienvenido/a a Yuma Electrónica!';
$greeting = $fullName ? 'Hola ' . h($fullName) . ',' : 'Hola,';

$body = '<div style="background:#f4f6f5;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;">'
. '<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.06);">'
    . '<div style="background:linear-gradient(135deg,#39b54a,#2e9a3d);padding:22px 28px;text-align:center;">'
        . '<img src="' . h($logoUrl) . '" alt="Yuma Electrónica" height="28" style="height:28px;">'
    . '</div>'
    . '<div style="padding:28px;">'
        . '<h1 style="margin:0 0 8px;font-size:20px;color:#1f2a24;">' . $greeting . '</h1>'
        . '<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#555;">Tu cuenta en <strong>Yuma Electrónica</strong> se ha creado correctamente con el email <strong>' . h($email) . '</strong>.</p>'
        . '<p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#555;">Desde tu cuenta puedes revisar tus pedidos, actualizar tus datos de contacto y de envío, y hacer seguimiento de tus compras en cualquier momento.</p>'
        . '<div style="text-align:center;margin:26px 0 10px;">'
            . '<a href="' . $siteUrl . '/mi-cuenta.html" style="display:inline-block;background:#39b54a;color:#fff;text-decoration:none;padding:12px 28px;border-radius:999px;font-weight:700;font-size:14px;">Ir a mi cuenta</a>'
        . '</div>'
        . '<p style="text-align:center;margin:20px 0 0;font-size:12px;color:#999;">¿Dudas? Escríbenos a <a href="mailto:' . $shopEmail . '" style="color:#39b54a;">' . $shopEmail . '</a></p>'
    . '</div>'
    . '<div style="background:#16171a;padding:24px 28px;text-align:center;">'
        . '<p style="margin:0 0 6px;font-size:14px;font-weight:800;color:#ffffff;">Yuma Electrónica</p>'
        . '<p style="margin:0 0 4px;font-size:11.5px;color:#9aa0a6;">Yuma Electrónica S.L. · CIF B30290647</p>'
        . '<p style="margin:0 0 4px;font-size:11.5px;color:#9aa0a6;">C. Libertador Simón Bolívar 2, Sur, 14013, Córdoba, España</p>'
    . '</div>'
. '</div>'
. '</div>';

$sent = sendMail($email, $shopName, $shopEmail, $subject, $body);
echo json_encode(['ok' => (bool) $sent]);
