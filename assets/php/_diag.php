<?php
/**
 * TEMPORARY diagnostic — reports where the app is looking for the
 * credential files, and whether it finds them there. No secrets are
 * printed. Delete this file once the config-file location is confirmed.
 */
header('Content-Type: application/json; charset=utf-8');

$secretsDir = dirname(__DIR__, 3) . '/ye-secrets';

echo json_encode([
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? null,
    'this_dir' => __DIR__,
    'computed_secrets_dir' => $secretsDir,
    'secrets_dir_exists' => is_dir($secretsDir),
    'secrets_dir_listing' => is_dir($secretsDir) ? array_values(array_diff(scandir($secretsDir), ['.', '..'])) : null,
    'db_config_found_outside' => is_file($secretsDir . '/db-config.php'),
    'mail_config_found_outside' => is_file($secretsDir . '/mail-config.php'),
    'db_config_found_old_location' => is_file(__DIR__ . '/db-config.php'),
    'mail_config_found_old_location' => is_file(__DIR__ . '/mail-config.php'),
], JSON_PRETTY_PRINT);
