<?php
/**
 * Yuma Electrónica — shared MySQL connection (PDO).
 */
function ye_db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    require_once __DIR__ . '/config-path.php';
    $configFile = ye_config_path('db-config.php');
    if (!is_file($configFile)) {
        throw new RuntimeException('db-config.php missing');
    }
    $cfg = require $configFile;
    $dsn = 'mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}
