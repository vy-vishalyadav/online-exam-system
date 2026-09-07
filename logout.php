<?php
// Start output buffering
if (!ob_get_level()) {
    ob_start();
}

// Ensure session is started before destroying
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers to prevent browser from caching logged-in state
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Unset all session variables
$_SESSION = array();

// If a session cookie was used, clear it completely
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session on server
session_unset();
session_destroy();

// Redirect to login page
header("Location: index.php?msg=logged_out");
exit;
?>
