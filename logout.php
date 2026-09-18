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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Logging out...</title>
    <meta http-equiv="refresh" content="1;url=index.php?msg=logged_out">
</head>
<body style="background:#f8fafc; font-family:sans-serif; display:flex; align-items:center; justify-content:center; height:100vh; margin:0;">
    <div style="text-align:center; color:#64748b;">
        <p>Logging out securely...</p>
    </div>
    <script>
    try {
        Object.keys(localStorage).forEach(function(k) {
            if (k.indexOf('exam_draft_') === 0) {
                localStorage.removeItem(k);
            }
        });
    } catch(e) {}
    window.location.replace("index.php?msg=logged_out");
    </script>
</body>
</html>
