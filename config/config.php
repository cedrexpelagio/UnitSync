<?php
// UnitSync Main Configuration
// Set PHP error reporting for local development
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Timezone: Asia/Manila as required by RULES.md
date_default_timezone_set('Asia/Manila');

// Base URL definition
if (!defined('BASE_URL')) {
    if (isset($_SERVER['HTTP_HOST'])) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        // Find if running under /Backend_ni_Ced/UnitSync or /UnitSync
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/Backend_ni_Ced/UnitSync') !== false || strpos($scriptDir, '/Backend_ni_Ced/UnitSync') !== false) {
            define('BASE_URL', $protocol . $_SERVER['HTTP_HOST'] . '/Backend_ni_Ced/UnitSync');
        } else {
            define('BASE_URL', $protocol . $_SERVER['HTTP_HOST'] . '/UnitSync');
        }
    } else {
        define('BASE_URL', 'http://localhost/UnitSync');
    }
}

// Session security configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

// Inactivity timeout in seconds (30 minutes)
if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', 1800);
}
