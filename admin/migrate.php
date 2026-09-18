<?php
/**
 * Admin Web-Based Migration & Schema Sync Tool
 *
 * Designed for shared hosting environments (InfinityFree / cPanel) where CLI is unavailable.
 * Strictly protected: Requires Super Admin Authentication and CSRF validation via POST.
 */

include '../includes/header.php';
include '../config/db.php';
require_once '../config/migrate.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}

$is_super_admin = !empty($_SESSION['is_super_admin']);
if (!$is_super_admin) {
    http_response_code(403);
    die("Access denied. Database schema synchronization is restricted to Super Administrators only.");
}

$migration_results = null;
$error = "";

// Ensure CSRF token exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Only execute on POST with valid CSRF token (prevents accidental refreshes or GET execution)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_migration'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Security token mismatch. Please reload the page and try again.";
    } else {
        try {
            $migration_results = run_migrations($conn);
            if (!$migration_results['success']) {
                $error = "One or more schema operations failed. Please review the details below.";
            }
        } catch (Throwable $e) {
            error_log("[Migration Error] " . $e->getMessage());
            $error = "An unexpected error occurred during database migration. Check error logs.";
        }
    }
}
?>

<div class="container py-4" style="max-width: 800px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="bi bi-database-gear text-primary me-2"></i>Database Schema Synchronization
            </h3>
            <p class="text-muted mb-0 small">
                Apply required database schema updates and performance indexes.
            </p>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Dashboard
        </a>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-3 mb-4 d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div><?php echo htmlspecialchars($error); ?></div>
        </div>
    <?php endif; ?>

    <?php if ($migration_results !== null): ?>
        <?php if (!empty($migration_results['success'])): ?>
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-success text-white py-3 rounded-top-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-check-circle-fill me-2"></i>Migration Completed Successfully</h5>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-3">The following migration steps were verified / updated:</p>
                    <ul class="list-group list-group-flush border rounded-3 mb-3 small">
                        <?php foreach ($migration_results['messages'] as $msg): ?>
                            <li class="list-group-item d-flex align-items-center gap-2 py-2.5">
                                <i class="bi bi-check2 text-success fw-bold"></i>
                                <span><?php echo htmlspecialchars($msg); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="d-flex gap-2">
                        <a href="dashboard.php" class="btn btn-primary fw-semibold px-4">
                            <i class="bi bi-speedometer2 me-1"></i> Go to Dashboard
                        </a>
                        <a href="migrate.php" class="btn btn-outline-secondary px-3">
                            <i class="bi bi-arrow-clockwise me-1"></i> Refresh Status
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-danger text-white py-3 rounded-top-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-x-circle-fill me-2"></i>Migration Encountered Errors</h5>
                </div>
                <div class="card-body p-4">
                    <div class="alert alert-danger border-0 rounded-3 mb-3 small">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        One or more required schema operations failed. Technical details have been logged securely to the server error log.
                    </div>
                    <?php if (!empty($migration_results['errors'])): ?>
                        <h6 class="fw-bold text-danger small mb-2">Failed Operations:</h6>
                        <ul class="list-group list-group-flush border rounded-3 mb-3 small">
                            <?php foreach ($migration_results['errors'] as $errMsg): ?>
                                <li class="list-group-item d-flex align-items-center gap-2 py-2.5 text-danger">
                                    <i class="bi bi-x-circle-fill text-danger fw-bold"></i>
                                    <span><?php echo htmlspecialchars($errMsg); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if (!empty($migration_results['messages'])): ?>
                        <h6 class="fw-bold text-muted small mb-2">Partial Steps Completed:</h6>
                        <ul class="list-group list-group-flush border rounded-3 mb-3 small">
                            <?php foreach ($migration_results['messages'] as $msg): ?>
                                <li class="list-group-item d-flex align-items-center gap-2 py-2.5">
                                    <i class="bi bi-info-circle text-secondary"></i>
                                    <span><?php echo htmlspecialchars($msg); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <div class="d-flex gap-2">
                        <a href="migrate.php" class="btn btn-danger fw-semibold px-4">
                            <i class="bi bi-arrow-repeat me-1"></i> Retry Migration
                        </a>
                        <a href="dashboard.php" class="btn btn-outline-secondary px-3">
                            Dashboard
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <div class="alert alert-info border-0 rounded-3 mb-4 small">
                    <i class="bi bi-info-circle-fill me-1"></i>
                    <strong>When to run:</strong> Run this once after uploading the site files to InfinityFree or whenever the database schema is updated. It will apply required database schema updates and performance indexes idempotently without affecting existing exams or students.
                </div>

                <form method="POST" action="migrate.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="execute_migration" value="1">

                    <div class="p-3 bg-light rounded-3 border mb-4">
                        <h6 class="fw-bold text-dark mb-2"><i class="bi bi-shield-check text-success me-1"></i> Pre-Flight Checks:</h6>
                        <ul class="text-muted small mb-0 ps-3">
                            <li>Admin session active: <strong>Yes (Logged in as Super Admin)</strong></li>
                            <li>Execution protocol: <strong>POST Only (CSRF Protected)</strong></li>
                            <li>Connection Status: <strong>Connected to Database</strong></li>
                        </ul>
                    </div>

                    <button type="submit" class="btn btn-primary fw-bold px-4 py-2 shadow-sm">
                        <i class="bi bi-play-fill me-1"></i> Run Schema Migration
                    </button>
                    <a href="dashboard.php" class="btn btn-outline-secondary px-4 py-2 ms-2">
                        Cancel
                    </a>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
