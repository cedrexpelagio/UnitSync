<?php
// UnitSync PDO Database Connection
require_once __DIR__ . '/config.php';

// Database credentials with local override support
$db_host = '127.0.0.1';
$db_name = 'unitsync';
$db_user = 'root';
$db_pass = '';
$db_port = '3306';

// If config.local.php exists, override variables
if (file_exists(__DIR__ . '/config.local.php')) {
    $local_config = require __DIR__ . '/config.local.php';
    if (is_array($local_config)) {
        if (!empty($local_config['db_host'])) $db_host = $local_config['db_host'];
        if (!empty($local_config['db_name'])) $db_name = $local_config['db_name'];
        if (isset($local_config['db_user'])) $db_user = $local_config['db_user'];
        if (isset($local_config['db_pass'])) $db_pass = $local_config['db_pass'];
        if (!empty($local_config['db_port'])) $db_port = $local_config['db_port'];
    }
}

$dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
    // Set MySQL session timezone to Asia/Manila (+08:00)
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}
