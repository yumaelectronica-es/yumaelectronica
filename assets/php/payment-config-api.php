<?php
/**
 * Yuma Electrónica — payment config API (MySQL-backed).
 *
 * There is only ever one row (id=1). Previously this lived in the admin's
 * own browser localStorage, so changes to the IBAN/Bizum number never
 * reached customers on other devices or the order confirmation email.
 *
 * Actions (POST, JSON body, field "action"):
 *  - get  : public, called by the checkout page
 *  - save : admin only
 */
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

define('YE_ADMIN_KEY', 'e7Li6M02IoyUUSCB');

$DEFAULTS = [
    'beneficiary' => 'Yuma Electrónica S.L.',
    'iban' => 'ES00 0000 0000 0000 0000 0000',
    'bic' => 'YUMAESMMXXX',
    'bankName' => 'Banco Yuma',
    'bizumNumber' => '622 000 000',
    'bizumBeneficiary' => 'Yuma Electrónica S.L.',
    'transferEnabled' => true,
    'bizumEnabled' => true,
];

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

function rowToConfig($row, $defaults) {
    if (!$row) return $defaults;
    return [
        'beneficiary' => $row['beneficiary'] ?? $defaults['beneficiary'],
        'iban' => $row['iban'] ?? $defaults['iban'],
        'bic' => $row['bic'] ?? $defaults['bic'],
        'bankName' => $row['bank_name'] ?? $defaults['bankName'],
        'bizumNumber' => $row['bizum_number'] ?? $defaults['bizumNumber'],
        'bizumBeneficiary' => $row['bizum_beneficiary'] ?? $defaults['bizumBeneficiary'],
        'transferEnabled' => (bool) $row['transfer_enabled'],
        'bizumEnabled' => (bool) $row['bizum_enabled'],
    ];
}

try {
    $pdo = ye_db();
    $action = $in['action'] ?? '';

    if ($action === 'get') {
        $stmt = $pdo->query("SELECT * FROM payment_config WHERE id = 1 LIMIT 1");
        $row = $stmt->fetch();
        echo json_encode(['ok' => true, 'config' => rowToConfig($row, $DEFAULTS)]);
        exit;
    }

    if ($action === 'save') {
        if (($in['adminKey'] ?? '') !== YE_ADMIN_KEY) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'forbidden']);
            exit;
        }
        $cfg = array_merge($DEFAULTS, is_array($in['config'] ?? null) ? $in['config'] : []);
        $stmt = $pdo->prepare("INSERT INTO payment_config
            (id, beneficiary, iban, bic, bank_name, bizum_number, bizum_beneficiary, transfer_enabled, bizum_enabled)
            VALUES (1, :beneficiary, :iban, :bic, :bank_name, :bizum_number, :bizum_beneficiary, :transfer_enabled, :bizum_enabled)
            ON DUPLICATE KEY UPDATE
            beneficiary = VALUES(beneficiary), iban = VALUES(iban), bic = VALUES(bic), bank_name = VALUES(bank_name),
            bizum_number = VALUES(bizum_number), bizum_beneficiary = VALUES(bizum_beneficiary),
            transfer_enabled = VALUES(transfer_enabled), bizum_enabled = VALUES(bizum_enabled)");
        $stmt->execute([
            ':beneficiary' => $cfg['beneficiary'],
            ':iban' => $cfg['iban'],
            ':bic' => $cfg['bic'],
            ':bank_name' => $cfg['bankName'],
            ':bizum_number' => $cfg['bizumNumber'],
            ':bizum_beneficiary' => $cfg['bizumBeneficiary'],
            ':transfer_enabled' => !empty($cfg['transferEnabled']) ? 1 : 0,
            ':bizum_enabled' => !empty($cfg['bizumEnabled']) ? 1 : 0,
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
