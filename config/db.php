<?php
/**
 * Database Connection & Global Configuration
 *
 * Lightweight, high-performance database bootstrapper.
 * Supports environment variables (Priority 1) and config.local.php (Priority 2).
 * Schema migrations are decoupled into config/migrate.php and admin/migrate.php.
 */

// Error handling: Suppress public display in production; log securely to server error log
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$host   = null;
$user   = null;
$pass   = null;
$dbname = null;

// Priority 1: Check environment variables (e.g., AWS, Docker, FastCGI params)
$env_host = getenv('DB_HOST');
$env_user = getenv('DB_USER');
$env_pass = getenv('DB_PASS');
$env_name = getenv('DB_NAME');

if (!empty($env_host) && !empty($env_user) && !empty($env_name)) {
    $host   = $env_host;
    $user   = $env_user;
    $pass   = ($env_pass !== false) ? $env_pass : '';
    $dbname = $env_name;
} else {
    // Priority 2: Check local configuration file (config.local.php)
    $local_config = __DIR__ . '/config.local.php';
    if (file_exists($local_config)) {
        $cfg = include $local_config;
        if (is_array($cfg)) {
            $host   = $cfg['db_host'] ?? null;
            $user   = $cfg['db_user'] ?? null;
            $pass   = $cfg['db_pass'] ?? '';
            $dbname = $cfg['db_name'] ?? null;
        }
    }
}

// Priority 3: Fail safely if configuration is missing (No hardcoded credentials!)
if (empty($host) || empty($user) || empty($dbname)) {
    error_log("[Exam System] Database configuration missing. Please set environment variables or create config/config.local.php.");
    http_response_code(500);
    die("System Configuration Error: Database settings are not configured. Please contact the administrator.");
}

// Connect to MySQL/MariaDB
$conn = @mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) {
    error_log("[Exam System] Database connection failed: " . mysqli_connect_error());
    http_response_code(500);
    die("Database service is currently unavailable. Please try again shortly.");
}

// Force UTF-8 (utf8mb4) to ensure math formulas and unicode symbols are never mangled
mysqli_set_charset($conn, "utf8mb4");

// Align timezone for PHP and MySQL (Asia/Kolkata +05:30)
date_default_timezone_set('Asia/Kolkata');
@mysqli_query($conn, "SET time_zone = '+05:30'");
