<?php
/**
 * Yuma Electrónica — contact form email sender.
 *
 * Called via fetch() from contacto.html. Forwards the message to the shop's
 * support inbox, with the visitor's address set as Reply-To so support can
 * answer directly from their own mail client.
 */

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/phpmailer/Exception.php';
require __DIR__ . '/phpmailer/PHPMailer.php';
require __DIR__ . '/phpmailer/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;

function sendMail($to, $fromName, $fromEmail, $replyToEmail, $replyToName, $subject, $htmlBody) {
    require_once __DIR__ . '/config-path.php';
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
            $mailer->addReplyTo($replyToEmail, $replyToName);
            $mailer->addAddress($to);
            $mailer->isHTML(true);
            $mailer->Subject = $subject;
            $mailer->Body = $htmlBody;
            return $mailer->send();
        } catch (Throwable $e) {
            @file_put_contents(__DIR__ . '/debug-log.txt', date('Y-m-d H:i:s') . ' | CONTACT SMTP ERROR: ' . $e->getMessage() . ' | mailer error info: ' . $mailer->ErrorInfo . "\n", FILE_APPEND);
            return false;
        }
    }
    $headers = "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "From: {$fromName} <{$fromEmail}>\r\n"
        . "Reply-To: {$replyToName} <{$replyToEmail}>\r\n";
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

// Very small per-IP rate limit (file-based, no database needed for this).
function rateLimited() {
    $dir = sys_get_temp_dir() . '/yuma_mail_rl';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $key = $dir . '/contact_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '.txt';
    $now = time();
    $hits = [];
    if (is_file($key)) {
        $hits = array_filter(explode(',', trim(file_get_contents($key))), function ($t) use ($now) {
            return $t !== '' && ($now - (int) $t) < 3600;
        });
    }
    if (count($hits) >= 10) return true; // 10 messages/hour/IP max
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
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
}

$name = trim((string) ($in['name'] ?? ''));
$email = filter_var(trim((string) ($in['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$message = trim((string) ($in['message'] ?? ''));
$subjectKey = (string) ($in['subject'] ?? 'otro');

$subjectLabels = [
    'pedido' => 'Estado de un pedido',
    'producto' => 'Pregunta sobre un producto',
    'devolucion' => 'Devolución o garantía',
    'otro' => 'Otro',
];
$subjectLabel = $subjectLabels[$subjectKey] ?? 'Otro';

if (!$name || !$email || !$message) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

$shopName = 'Yuma Electrónica';
$shopEmail = 'soporte@yumaelectronica.es';

$subject = 'Nuevo mensaje de contacto: ' . $subjectLabel . ' — ' . h($name);
$body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2a24;max-width:600px;">'
    . '<h2 style="margin:0 0 12px;">Nuevo mensaje desde el formulario de contacto</h2>'
    . '<p style="margin:0 0 4px;"><strong>Nombre:</strong> ' . h($name) . '</p>'
    . '<p style="margin:0 0 4px;"><strong>Email:</strong> ' . h($email) . '</p>'
    . '<p style="margin:0 0 12px;"><strong>Asunto:</strong> ' . h($subjectLabel) . '</p>'
    . '<p style="margin:0 0 6px;"><strong>Mensaje:</strong></p>'
    . '<p style="white-space:pre-wrap;background:#f8f9fa;border-radius:8px;padding:12px;">' . h($message) . '</p>'
    . '</div>';

$sent = sendMail($shopEmail, $shopName, $shopEmail, $email, $name, $subject, $body);
echo json_encode(['ok' => (bool) $sent]);
