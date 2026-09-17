<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}

$is_super_admin = !empty($_SESSION['is_super_admin']) || ((int)$_SESSION['admin_id'] === 1 || strtolower($_SESSION['admin_username'] ?? '') === 'admin');
if (!$is_super_admin) {
    $_SESSION['flash_error'] = "Access denied. Only the Super Administrator can manage administrator accounts.";
    safe_redirect("dashboard.php");
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error   = "";
$success = "";

// ── Handle Add Admin POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_admin') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please try again.";
    } else {
        $username         = trim($_POST['username'] ?? '');
        $password         = trim($_POST['password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');

        if (empty($username) || empty($password)) {
            $error = "Username and Password are required.";
        } elseif (strlen($username) < 3 || strlen($username) > 50) {
            $error = "Username must be between 3 and 50 characters.";
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            $error = "Username can only contain letters, numbers, dots, hyphens, and underscores.";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            // Check if username already exists
            $check_stmt = mysqli_prepare($conn, "SELECT id FROM admin WHERE username = ? LIMIT 1");
            mysqli_stmt_bind_param($check_stmt, "s", $username);
            mysqli_stmt_execute($check_stmt);
            $check_res = mysqli_stmt_get_result($check_stmt);

            if ($check_res && mysqli_num_rows($check_res) > 0) {
                $error = "An administrator with the username '" . htmlspecialchars($username) . "' already exists.";
                mysqli_stmt_close($check_stmt);
            } else {
                mysqli_stmt_close($check_stmt);
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = mysqli_prepare($conn, "INSERT INTO admin (username, password) VALUES (?, ?)");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "ss", $username, $hash);
                    if (mysqli_stmt_execute($stmt)) {
                        mysqli_stmt_close($stmt);
                        $_SESSION['flash_success'] = "Admin account '" . htmlspecialchars($username) . "' created successfully!";
                        safe_redirect("manage-admins.php");
                    } else {
                        $error = "Error adding admin: " . mysqli_error($conn);
                        mysqli_stmt_close($stmt);
                    }
                } else {
                    $error = "Database query error. Please try again.";
                }
            }
        }
    }
}

// ── Handle Change Password POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please try again.";
    } else {
        $target_id        = (int)($_POST['admin_id'] ?? 0);
        $new_password     = trim($_POST['new_password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');

        if ($target_id <= 0) {
            $error = "Invalid admin selected.";
        } elseif (strlen($new_password) < 6) {
            $error = "New password must be at least 6 characters.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } else {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "UPDATE admin SET password = ? WHERE id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "si", $hash, $target_id);
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    $_SESSION['flash_success'] = "Password updated successfully!";
                    safe_redirect("manage-admins.php");
                } else {
                    $error = "Error updating password: " . mysqli_error($conn);
                    mysqli_stmt_close($stmt);
                }
            } else {
                $error = "Database error. Please try again.";
            }
        }
    }
}

// ── Handle Delete Admin POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_admin') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please try again.";
    } else {
        $delete_id = (int)($_POST['admin_id'] ?? 0);

        if ($delete_id <= 0) {
            $error = "Invalid admin account selected.";
        } elseif ($delete_id === 1) {
            $error = "The primary root Super Administrator account (ID: 1) is permanent and cannot be deleted.";
        } elseif ($delete_id === (int)$_SESSION['admin_id']) {
            $error = "You cannot delete your own active administrator account!";
        } else {
            // Ensure at least 1 admin remains in the system
            $count_res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM admin");
            $total_admins = $count_res ? (int)mysqli_fetch_assoc($count_res)['total'] : 0;

            if ($total_admins <= 1) {
                $error = "Cannot delete the only administrator account. The system requires at least one admin.";
            } else {
                $stmt = mysqli_prepare($conn, "DELETE FROM admin WHERE id = ?");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "i", $delete_id);
                    if (mysqli_stmt_execute($stmt)) {
                        mysqli_stmt_close($stmt);
                        $_SESSION['flash_success'] = "Admin account removed successfully!";
                        safe_redirect("manage-admins.php");
                    } else {
                        $error = "Error deleting admin: " . mysqli_error($conn);
                        mysqli_stmt_close($stmt);
                    }
                } else {
                    $error = "Database error. Please try again.";
                }
            }
        }
    }
}

// Flash messages
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Fetch all admins
$admins_res = mysqli_query($conn, "SELECT * FROM admin ORDER BY id ASC");
$all_admins = [];
if ($admins_res) {
    while ($row = mysqli_fetch_assoc($admins_res)) {
        $all_admins[] = $row;
    }
}
$total_admin_count = count($all_admins);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-shield-lock-fill text-primary me-2"></i> System Administrators</h4>
        <small class="text-muted">Manage multi-admin credentials, add instructors, and control administrative access.</small>
    </div>
    <div class="d-flex gap-2">
        <a href="dashboard.php" class="btn btn-outline-secondary fw-semibold">
            <i class="bi bi-arrow-left me-1"></i> Dashboard
        </a>
        <button class="btn btn-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#addAdminModal">
            <i class="bi bi-person-plus-fill me-1"></i> Add New Admin
        </button>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4 rounded-3 shadow-sm border-0" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 rounded-3 shadow-sm border-0" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i> <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-sm-6">
        <div class="stat-card bg-white border-0 shadow-sm rounded-4 p-4 position-relative overflow-hidden" style="border-top: 4px solid #4f46e5 !important;">
            <h6 class="mb-1 fw-semibold text-muted small text-uppercase ls-1">Total Administrators</h6>
            <h2 class="fw-extrabold mb-0 text-dark"><?php echo $total_admin_count; ?></h2>
            <i class="bi bi-shield-check stat-icon" style="color:#4f46e5; opacity:0.12;"></i>
        </div>
    </div>
    <div class="col-md-6 col-sm-6">
        <div class="stat-card bg-white border-0 shadow-sm rounded-4 p-4 position-relative overflow-hidden" style="border-top: 4px solid #10b981 !important;">
            <h6 class="mb-1 fw-semibold text-muted small text-uppercase ls-1">Current Active Session</h6>
            <h2 class="fw-extrabold mb-0 text-dark"><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'admin'); ?></h2>
            <i class="bi bi-person-badge-fill stat-icon" style="color:#10b981; opacity:0.12;"></i>
        </div>
    </div>
</div>

<!-- Admin Accounts Table Card -->
<div class="card shadow-sm border-0 rounded-4 mb-5">
    <!-- Top Horizontal Scrollbar Slider -->
    <div class="table-scroll-top-container d-none" id="adminTableScrollTop">
        <div class="table-scroll-top-inner" id="adminTableScrollTopInner"></div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" id="adminTableResponsive">
            <table class="table custom-table table-sticky-actions align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Created At</th>
                        <th class="text-center pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (!empty($all_admins)):
                        $i = 1;
                        foreach ($all_admins as $adm):
                            $is_current_user = ((int)$adm['id'] === (int)$_SESSION['admin_id']);
                    ?>
                        <tr>
                            <td class="ps-4 fw-bold text-muted"><?php echo $i++; ?></td>
                            <td>
                                <span class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($adm['username']); ?></span>
                                <?php if ($is_current_user): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1 ms-2 small">
                                        <i class="bi bi-check-circle-fill me-1"></i>You (Active)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1">
                                    <i class="bi bi-shield-shaded me-1"></i>Administrator
                                </span>
                            </td>
                            <td>
                                <span class="text-muted small">
                                    <i class="bi bi-calendar-event me-1"></i>
                                    <?php echo !empty($adm['created_at']) ? date('d M Y, h:i A', strtotime($adm['created_at'])) : 'System Default'; ?>
                                </span>
                            </td>
                            <td class="text-center pe-4">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#changePasswordModal<?php echo $adm['id']; ?>" title="Change Password">
                                        <i class="bi bi-key-fill me-1"></i> Password
                                    </button>
                                    <?php if (!$is_current_user && $total_admin_count > 1): ?>
                                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteAdminModal<?php echo $adm['id']; ?>" title="Delete Admin">
                                            <i class="bi bi-trash-fill"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline-secondary disabled" title="<?php echo $is_current_user ? 'Cannot delete your own active session' : 'Cannot delete the only admin'; ?>" disabled>
                                            <i class="bi bi-trash-fill"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php
                        endforeach;
                    else:
                    ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                <i class="bi bi-shield-slash fs-3 d-block mb-2"></i> No administrator accounts found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modals for Administrators (Placed outside table to prevent backdrop stacking trap) -->
<?php if (!empty($all_admins)): ?>
    <?php foreach ($all_admins as $adm): 
        $is_current_user = ((int)$adm['id'] === (int)$_SESSION['admin_id']);
    ?>
        <!-- Change Password Modal -->
        <div class="modal fade text-start" id="changePasswordModal<?php echo $adm['id']; ?>" tabindex="-1" aria-labelledby="changePasswordModalLabel<?php echo $adm['id']; ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                    <form method="POST" action="manage-admins.php">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="change_password">
                        <input type="hidden" name="admin_id" value="<?php echo $adm['id']; ?>">
                        <div class="modal-header bg-light">
                            <h5 class="modal-title fw-bold text-dark" id="changePasswordModalLabel<?php echo $adm['id']; ?>">
                                <i class="bi bi-key-fill text-primary me-2"></i>Change Password — <?php echo htmlspecialchars($adm['username']); ?>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">New Password <span class="text-danger">*</span></label>
                                <input type="password" name="new_password" class="form-control" placeholder="Minimum 6 characters" required minlength="6">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Confirm New Password <span class="text-danger">*</span></label>
                                <input type="password" name="confirm_password" class="form-control" placeholder="Repeat new password" required minlength="6">
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary fw-semibold">Update Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <?php if (!$is_current_user && $total_admin_count > 1): ?>
        <div class="modal fade text-start" id="deleteAdminModal<?php echo $adm['id']; ?>" tabindex="-1" aria-labelledby="deleteAdminModalLabel<?php echo $adm['id']; ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                    <form method="POST" action="manage-admins.php">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="delete_admin">
                        <input type="hidden" name="admin_id" value="<?php echo $adm['id']; ?>">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title fw-bold" id="deleteAdminModalLabel<?php echo $adm['id']; ?>">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Removal
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-4">
                            <p class="mb-0">Are you sure you want to permanently delete administrator account <strong><?php echo htmlspecialchars($adm['username']); ?></strong>?</p>
                            <div class="alert alert-warning mt-3 mb-0 small">
                                <i class="bi bi-info-circle me-1"></i> This action cannot be undone.
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger fw-semibold">Yes, Delete Account</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Add Admin Modal -->
<div class="modal fade" id="addAdminModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="manage-admins.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="add_admin">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2"></i>Add New Administrator</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Admin Username <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-person-fill text-muted"></i></span>
                            <input type="text" name="username" class="form-control" placeholder="e.g. prof_sharma or exam_officer" required maxlength="50" pattern="[a-zA-Z0-9_.-]+" title="Only letters, numbers, dots, hyphens, and underscores allowed">
                        </div>
                        <div class="form-text">Unique login identifier for the new administrator.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-key-fill text-muted"></i></span>
                            <input type="password" name="password" class="form-control" placeholder="Minimum 6 characters" required minlength="6">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-check2-circle text-muted"></i></span>
                            <input type="password" name="confirm_password" class="form-control" placeholder="Repeat password" required minlength="6">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-check2-circle me-1"></i> Create Admin Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const topScroll = document.getElementById('adminTableScrollTop');
    const tableCont = document.getElementById('adminTableResponsive');
    if (topScroll && tableCont) {
        const topInner = document.getElementById('adminTableScrollTopInner');
        const table = tableCont.querySelector('table');

        function updateScrollWidth() {
            if (!table) return;
            const scrollW = table.scrollWidth;
            const clientW = tableCont.clientWidth;
            if (scrollW > clientW + 5) {
                topScroll.classList.remove('d-none');
                if (topInner) topInner.style.width = scrollW + 'px';
            } else {
                topScroll.classList.add('d-none');
            }
        }

        let isSyncing = false;
        topScroll.addEventListener('scroll', function() {
            if (!isSyncing) {
                isSyncing = true;
                tableCont.scrollLeft = topScroll.scrollLeft;
                requestAnimationFrame(function() { isSyncing = false; });
            }
        });
        tableCont.addEventListener('scroll', function() {
            if (!isSyncing) {
                isSyncing = true;
                topScroll.scrollLeft = tableCont.scrollLeft;
                requestAnimationFrame(function() { isSyncing = false; });
            }
        });

        window.addEventListener('resize', updateScrollWidth);
        updateScrollWidth();
        setTimeout(updateScrollWidth, 300);
    }
});
</script>

<?php include '../includes/footer.php'; ?>
