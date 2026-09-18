<?php
/**
 * Database Connection & Global Configuration
 *
 * Lightweight, high-performance database bootstrapper.
 * Schema migrations have been extracted to config/migrate.php.
 */

// Allow environment variables for production/AWS deployments
$env_host = getenv('DB_HOST');
$env_user = getenv('DB_USER');
$env_pass = getenv('DB_PASS');
$env_name = getenv('DB_NAME');

if (!empty($env_host) && !empty($env_user) && !empty($env_name)) {
    $host   = $env_host;
    $user   = $env_user;
    $pass   = $env_pass !== false ? $env_pass : "";
    $dbname = $env_name;
} else {
    // Auto-detect environment (Localhost vs External Cloud)
    $is_local = (php_sapi_name() === 'cli')
        || (isset($_SERVER['SERVER_NAME']) && in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1', '::1']))
        || (isset($_SERVER['HTTP_HOST']) && preg_match('/^(localhost|127\.0\.0\.1|192\.168\.\d+\.\d+|10\.\d+\.\d+\.\d+)(:\d+)?$/', $_SERVER['HTTP_HOST']));

    if ($is_local) {
        // Local XAMPP Settings
        $host   = "localhost";
        $user   = "root";
        $pass   = "";
        $dbname = "online_exam_db";
    } else {
        // External Hosting Default (InfinityFree / cPanel fallback)
        $host   = "sql309.infinityfree.com";
        $user   = "if0_42825922";
        $pass   = "exampasswd123";
        $dbname = "if0_42825922_exam";
    }
}

$conn = mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Force UTF-8 (utf8mb4) to ensure math formulas and unicode symbols are never mangled
mysqli_set_charset($conn, "utf8mb4");

// Align timezone for PHP and MySQL (Asia/Kolkata +05:30)
date_default_timezone_set('Asia/Kolkata');
@mysqli_query($conn, "SET time_zone = '+05:30'");
