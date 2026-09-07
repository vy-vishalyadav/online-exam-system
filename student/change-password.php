<?php
include '../includes/header.php';
include '../config/db.php';

// Must be logged in as student
if (!isset($_SESSION['student_id'])) {
    header("Location: ../index.php?msg=unauthorized");
    exit;
}

$student_id = (int)$_SESSION['student_id'];
$error      = '';
$success    = '';

// ---------- CSRF token ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ---------- Handle POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    $token_ok = isset($_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);

    if (!$token_ok) {
        $error = 'Invalid request. Please try again.';
    } else {
        $current_pw  = $_POST['current_password']  ?? '';
        $new_pw      = $_POST['new_password']       ?? '';
        $confirm_pw  = $_POST['confirm_password']   ?? '';

        // --- Basic validation ---
        if (empty($current_pw) || empty($new_pw) || empty($confirm_pw)) {
            $error = 'All fields are required.';
        } elseif (strlen($new_pw) < 6) {
            $error = 'New password must be at least 6 characters.';
        } elseif ($new_pw !== $confirm_pw) {
            $error = 'New password and confirm password do not match.';
        } else {
            // --- Fetch current password from DB ---
            $stmt = mysqli_prepare($conn, "SELECT password FROM students WHERE id = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, 'i', $student_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row    = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);

            if (!$row) {
                $error = 'Account not found. Please log in again.';
            } else {
                $db_pw = $row['password'];

                // Support both plain-text seed ("student") and bcrypt hashes
                $current_ok = password_verify($current_pw, $db_pw)
                           || ($current_pw === $db_pw);

                if (!$current_ok) {
                    $error = 'Current password is incorrect.';
                } elseif ($new_pw === $current_pw || $new_pw === 'student') {
                    // Warn if reusing default or same password (soft block)
                    $error = 'New password cannot be the same as your current password.';
                } else {
                    // --- Hash & update ---
                    $hashed = password_hash($new_pw, PASSWORD_BCRYPT);
                    $upd    = mysqli_prepare($conn, "UPDATE students SET password = ? WHERE id = ?");
                    mysqli_stmt_bind_param($upd, 'si', $hashed, $student_id);

                    if (mysqli_stmt_execute($upd)) {
                        // Rotate CSRF token after success
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        $success = 'Password updated successfully!';
                    } else {
                        $error = 'Database error. Please try again.';
                    }
                    mysqli_stmt_close($upd);
                }
            }
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">

        <div class="mb-4">
            <h4 class="fw-bold mb-1">
                <i class="bi bi-key-fill text-primary me-2"></i>Change Password
            </h4>
            <p class="text-muted small mb-0">
                Update your login password. Minimum 6 characters.
            </p>
        </div>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success d-flex align-items-center gap-2" role="alert">
                <i class="bi bi-check-circle-fill fs-5"></i>
                <div><?php echo htmlspecialchars($success); ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
                <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <form method="POST" action="" autocomplete="off">
                    <input type="hidden" name="csrf_token"
                           value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <!-- Current Password -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary">
                            Current Password
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">
                                <i class="bi bi-lock text-muted"></i>
                            </span>
                            <input type="password" name="current_password"
                                   class="form-control" placeholder="Enter current password"
                                   required autocomplete="current-password">
                        </div>
                    </div>

                    <!-- New Password -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary">
                            New Password
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">
                                <i class="bi bi-key text-muted"></i>
                            </span>
                            <input type="password" name="new_password"
                                   class="form-control" placeholder="Minimum 6 characters"
                                   required autocomplete="new-password" minlength="6"
                                   id="newPwInput">
                            <button class="btn btn-outline-secondary" type="button"
                                    onclick="togglePw('newPwInput', this)"
                                    title="Show/hide password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Confirm New Password -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold text-secondary">
                            Confirm New Password
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">
                                <i class="bi bi-key-fill text-muted"></i>
                            </span>
                            <input type="password" name="confirm_password"
                                   class="form-control" placeholder="Repeat new password"
                                   required autocomplete="new-password" minlength="6"
                                   id="confirmPwInput">
                            <button class="btn btn-outline-secondary" type="button"
                                    onclick="togglePw('confirmPwInput', this)"
                                    title="Show/hide password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <!-- Live match indicator -->
                        <div id="matchHint" class="form-text mt-1"></div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-sm">
                        <i class="bi bi-check-lg me-1"></i> Update Password
                    </button>

                    <a href="dashboard.php"
                       class="btn btn-outline-secondary w-100 fw-bold py-2 mt-2">
                        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
                    </a>
                </form>
            </div>
        </div>

        <!-- Info box -->
        <div class="alert alert-info d-flex gap-2 mt-3 rounded-3" role="alert">
            <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
            <div class="small">
                <strong>Tip:</strong> Choose a unique password — do not reuse
                <code>student</code> or any easy-to-guess word.
            </div>
        </div>

    </div>
</div>

<script>
function togglePw(inputId, btn) {
    var input = document.getElementById(inputId);
    var icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

// Live confirm-match hint
var newPw     = document.getElementById('newPwInput');
var confirmPw = document.getElementById('confirmPwInput');
var hint      = document.getElementById('matchHint');

function checkMatch() {
    if (!confirmPw.value) { hint.textContent = ''; return; }
    if (newPw.value === confirmPw.value) {
        hint.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Passwords match</span>';
    } else {
        hint.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>Passwords do not match</span>';
    }
}
newPw.addEventListener('input', checkMatch);
confirmPw.addEventListener('input', checkMatch);
</script>

<?php include '../includes/footer.php'; ?>
