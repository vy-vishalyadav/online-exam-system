<?php
/**
 * Online Examination System - Database Configuration Example
 *
 * INSTRUCTIONS:
 * 1. Copy this file to "config.local.php" in the same directory:
 *    cp config/config.example.php config/config.local.php
 * 2. Update the credentials below for your environment (Localhost, InfinityFree, or cPanel).
 * 3. Never commit "config.local.php" to public version control.
 *
 * NOTE FOR AWS / DOCKER:
 * If environment variables (DB_HOST, DB_USER, DB_PASS, DB_NAME) are set,
 * they automatically take precedence over this file.
 */

return [
    // Database host (e.g., 'localhost' for XAMPP/cPanel, or 'sqlXXX.infinityfree.com' for InfinityFree)
    'db_host' => 'localhost',

    // Database user account (e.g., 'root' for XAMPP, or your hosting MySQL username)
    'db_user' => 'root',

    // Database password
    'db_pass' => '',

    // Database name (e.g., 'online_exam_db' or your hosting database name)
    'db_name' => 'online_exam_db',
];
