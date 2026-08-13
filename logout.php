<?php
session_start();

// Unset all session variables
$_SESSION = array();

// If a session cookie was used, clear it from browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_unset();
session_destroy();

// Redirect to login page
header("Location: index.php?msg=logged_out");
exit;
?>
