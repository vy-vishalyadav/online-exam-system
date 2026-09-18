<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}

// Filters
$filter_exam    = isset($_GET['exam_id'])    ? (int)$_GET['exam_id']    : 0;
$filter_student = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$filter_type    = isset($_GET['type'])       ? trim($_GET['type'])       : '';

$filtered_student_name = '';
if ($filter_student > 0) {
    $s_stmt = mysqli_prepare($conn, "SELECT name FROM students WHERE id=? LIMIT 1");
    if ($s_stmt) {
        mysqli_stmt_bind_param($s_stmt, "i", $filter_student);
        mysqli_stmt_execute($s_stmt);
        $s_res = mysqli_stmt_get_result($s_stmt);
        if ($s_row = mysqli_fetch_assoc($s_res)) {
            $filtered_student_name = $s_row['name'];
        }
        mysqli_stmt_close($s_stmt);
    }
}

// Build query
$where   = [];
$params  = [];
$types   = '';

if ($filter_exam)    { $where[] = 'v.exam_id=?';        $params[] = $filter_exam;    $types .= 'i'; }
if ($filter_student) { $where[] = 'v.student_id=?';     $params[] = $filter_student; $types .= 'i'; }
if ($filter_type)    { $where[] = 'v.violation_type=?'; $params[] = $filter_type;    $types .= 's'; }

$sql = "SELECT v.*, s.name AS student_name, s.email AS student_email, c.name AS class_name, e.title AS exam_title
        FROM exam_violations v
        JOIN students s ON v.student_id = s.id
        LEFT JOIN classes c ON s.class_id = c.id
        JOIN exams e    ON v.exam_id    = e.id"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . " ORDER BY v.occurred_at DESC LIMIT 500";

$stmt = mysqli_prepare($conn, $sql);
$violations = [];
if ($stmt) {
    if ($params) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $violations[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// Track latest violation ID for live delta updates
$max_initial_id = !empty($violations) ? max(array_column($violations, 'id')) : 0;

// Summary: top recent offenders
$summary_sql = "SELECT v.student_id, s.name, s.email, e.title AS exam_title, v.exam_id,
                       COUNT(*) AS total,
                       SUM(v.violation_type IN ('tab_switch','fullscreen_exit','exit_exam')) AS serious,
                       MAX(v.occurred_at) AS latest_violation
                FROM exam_violations v
                JOIN students s ON v.student_id=s.id
                JOIN exams e ON v.exam_id=e.id
                GROUP BY v.student_id, v.exam_id
                ORDER BY latest_violation DESC, serious DESC, total DESC LIMIT 100";
$summary_res  = mysqli_query($conn, $summary_sql);
$summaries    = [];
if ($summary_res) {
    while ($r = mysqli_fetch_assoc($summary_res)) $summaries[] = $r;
}

// Exams list for filter
$exams_res = mysqli_query($conn, "SELECT id, title FROM exams ORDER BY id DESC");
$exams_list = [];
if ($exams_res) while ($r = mysqli_fetch_assoc($exams_res)) $exams_list[] = $r;
?>

<style>
@keyframes highlightNewRow {
    0%   { background-color: rgba(239, 68, 68, 0.22); }
    50%  { background-color: rgba(239, 68, 68, 0.15); }
    100% { background-color: transparent; }
}
.row-new-violation {
    animation: highlightNewRow 3.5s ease-out;
}
.spin-animation {
    display: inline-block;
    animation: spin 0.8s linear infinite;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}
</style>

<!-- Header with Live Controls -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
            <h4 class="fw-bold mb-0"><i class="bi bi-shield-exclamation text-danger me-2"></i>Anti-Cheat Violations Log</h4>
            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1.5 small fw-semibold d-inline-flex align-items-center gap-1.5" id="liveStatusBadge">
                <span class="spinner-grow spinner-grow-sm text-success" style="width: 0.55rem; height: 0.55rem;" id="liveStatusDot"></span>
                <span>Live Monitoring: <strong id="syncStateText">Active</strong> (<span id="syncTime"><?php echo date('h:i:s A'); ?></span>)</span>
            </span>
        </div>
        <small class="text-muted">Real-time audit trail of suspicious activity during active exams.</small>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold" id="toggleSoundBtn" onclick="toggleSoundAlert()" title="Toggle audio alert on new violations">
            <i class="bi bi-volume-up me-1" id="soundIcon"></i><span id="soundLabel">Sound: On</span>
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold" id="toggleSyncBtn" onclick="toggleAutoSync()">
            <i class="bi bi-pause-fill me-1" id="syncIcon"></i><span id="syncLabel">Pause</span>
        </button>
        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold" id="manualSyncBtn" onclick="fetchViolationsFeed(true)">
            <i class="bi bi-arrow-clockwise me-1" id="refreshIcon"></i>Sync Now
        </button>
        <a href="dashboard.php" class="btn btn-sm btn-outline-secondary fw-semibold rounded-pill px-3">
            <i class="bi bi-arrow-left me-1"></i> Dashboard
        </a>
    </div>
</div>

<!-- Floating Toast Alert Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1090;" id="violationToastContainer"></div>

<!-- Top Offenders Summary -->
<div class="card border-0 shadow-sm rounded-4 mb-4" id="flaggedStudentsCard" style="<?php echo empty($summaries) ? 'display: none;' : ''; ?>">
    <div class="card-header bg-danger-subtle border-0 rounded-top-4 py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <h6 class="fw-bold text-danger mb-0"><i class="bi bi-flag-fill me-2"></i>Flagged Students (Recent Offenders)</h6>
            <span class="badge bg-danger text-white rounded-pill px-2.5 py-1" id="offendersTotalBadge"><?php echo count($summaries); ?> total</span>
        </div>
        <small class="text-muted fw-semibold" id="offendersCountSummary">Showing top <?php echo min(3, count($summaries)); ?> recent</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table custom-table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Student</th>
                        <th>Exam</th>
                        <th>Serious Violations<br><small class="text-muted fw-normal">(tab-switch / fullscreen exit)</small></th>
                        <th>Total Events</th>
                        <th class="pe-4 text-end">Filter</th>
                    </tr>
                </thead>
                <tbody id="offendersTableBody">
                    <?php foreach ($summaries as $idx => $s):
                        $risk = $s['serious'] >= 3 ? 'danger' : ($s['serious'] >= 1 ? 'warning' : 'secondary');
                        $is_extra = ($idx >= 3);
                    ?>
                    <tr class="offender-row <?php echo $is_extra ? 'd-none offender-extra' : ''; ?>" data-index="<?php echo $idx; ?>">
                        <td class="ps-4">
                            <div class="fw-semibold text-dark"><?php echo htmlspecialchars($s['name']); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($s['email']); ?></small>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark"><?php echo htmlspecialchars($s['exam_title']); ?></div>
                            <?php if (!empty($s['latest_violation'])): ?>
                                <small class="text-muted"><i class="bi bi-clock me-1"></i><?php echo date('d M, h:i A', strtotime($s['latest_violation'])); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $risk; ?> rounded-pill px-3 py-2 fs-6">
                                <?php echo $s['serious']; ?>
                            </span>
                        </td>
                        <td>
                            <span class="fw-semibold text-dark"><?php echo $s['total']; ?></span>
                        </td>
                        <td class="pe-4 text-end">
                            <a href="?student_id=<?php echo $s['student_id']; ?>&exam_id=<?php echo $s['exam_id']; ?>"
                               class="btn btn-sm btn-outline-danger fw-semibold">
                                <i class="bi bi-search me-1"></i> View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white border-0 py-3 rounded-bottom-4 d-flex justify-content-between align-items-center flex-wrap gap-2 border-top <?php echo (count($summaries) <= 3) ? 'd-none' : ''; ?>" id="offendersFooter">
        <div class="text-muted small">
            Showing <strong id="offendersVisibleCount" class="text-dark"><?php echo min(3, count($summaries)); ?></strong> of <strong class="text-dark" id="offendersFooterCount"><?php echo count($summaries); ?></strong> flagged students
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-danger fw-semibold px-3" id="btnNextThree" onclick="showNextOffenders(3)">
                <i class="bi bi-chevron-down me-1"></i> Next 3
            </button>
            <button type="button" class="btn btn-sm btn-danger fw-semibold px-3" id="btnMaximizeAll" onclick="maximizeOffenders()">
                <i class="bi bi-arrows-fullscreen me-1"></i> Maximize All (<span id="btnMaximizeCount"><?php echo count($summaries); ?></span>)
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary fw-semibold px-3 d-none" id="btnMinimizeOffenders" onclick="minimizeOffenders()">
                <i class="bi bi-chevron-up me-1"></i> Top 3 Only
            </button>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end" id="violationsFilterForm">
            <?php if ($filter_student > 0): ?>
                <input type="hidden" name="student_id" value="<?php echo $filter_student; ?>">
                <div class="col-12 mb-1">
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2 rounded-pill">
                        <i class="bi bi-person-fill-exclamation me-1"></i> Filtering for Student: <strong><?php echo htmlspecialchars($filtered_student_name ?: "ID #$filter_student"); ?></strong>
                        <a href="violations.php<?php echo $filter_exam ? '?exam_id='.$filter_exam : ''; ?>" class="text-danger ms-2 text-decoration-none fw-bold" title="Clear student filter">&times; Clear Student</a>
                    </span>
                </div>
            <?php endif; ?>
            <div class="col-md-4">
                <label class="form-label small fw-semibold text-muted mb-1">Filter by Exam</label>
                <select name="exam_id" class="form-select form-select-sm" id="filterExamSelect">
                    <option value="">All Exams</option>
                    <?php foreach ($exams_list as $ex): ?>
                        <option value="<?php echo $ex['id']; ?>" <?php echo ($filter_exam == $ex['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ex['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1">Filter by Type</label>
                <select name="type" class="form-select form-select-sm" id="filterTypeSelect">
                    <option value="">All Types</option>
                    <option value="tab_switch"      <?php echo ($filter_type==='tab_switch')      ? 'selected':''; ?>>Tab Switch</option>
                    <option value="fullscreen_exit" <?php echo ($filter_type==='fullscreen_exit') ? 'selected':''; ?>>Fullscreen Exit</option>
                    <option value="exit_exam"       <?php echo ($filter_type==='exit_exam')       ? 'selected':''; ?>>Exited Exam</option>
                    <option value="blocked_key"     <?php echo ($filter_type==='blocked_key')     ? 'selected':''; ?>>Blocked Action</option>
                </select>
            </div>
            <div class="col-md-auto">
                <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4">
                    <i class="bi bi-funnel me-1"></i> Apply
                </button>
                <?php if ($filter_exam || $filter_student || !empty($filter_type)): ?>
                    <a href="violations.php" class="btn btn-outline-secondary btn-sm fw-semibold px-3 ms-2">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Full Log Table -->
<div class="card border-0 shadow-sm rounded-4">
    <div class="card-header bg-white border-0 rounded-top-4 py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0"><i class="bi bi-list-ul me-2 text-muted"></i>All Events
            <span class="badge bg-secondary rounded-pill ms-2" id="allEventsCount"><?php echo count($violations); ?></span>
        </h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table custom-table align-middle mb-0 small">
                <thead>
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Student</th>
                        <th>Exam</th>
                        <th>Type</th>
                        <th>Detail</th>
                        <th>IP Address</th>
                        <th class="pe-4 text-end">Occurred At</th>
                    </tr>
                </thead>
                <tbody id="violationsTableBody">
                    <?php if (empty($violations)): ?>
                        <tr class="empty-state-row">
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-shield-check fs-2 d-block mb-2 text-success"></i>
                                No violations recorded. All students are behaving!
                            </td>
                        </tr>
                    <?php else:
                        $i = 1;
                        foreach ($violations as $v):
                            $badge = match($v['violation_type']) {
                                'tab_switch'      => ['bg-danger',  'bi-box-arrow-up-right', 'Tab Switch'],
                                'fullscreen_exit' => ['bg-warning text-dark', 'bi-fullscreen-exit', 'Fullscreen Exit'],
                                'exit_exam'       => ['bg-danger',  'bi-door-open-fill',     'Exited Exam'],
                                'blocked_key'     => ['bg-secondary', 'bi-slash-circle',     'Blocked Action'],
                                default           => ['bg-dark', 'bi-question', $v['violation_type']],
                            };
                    ?>
                        <tr data-id="<?php echo (int)$v['id']; ?>">
                            <td class="ps-4 text-muted row-idx"><?php echo $i++; ?></td>
                            <td>
                                <a href="student-profile.php?id=<?php echo $v['student_id']; ?>" class="fw-semibold text-dark text-decoration-none">
                                    <?php echo htmlspecialchars($v['student_name']); ?>
                                    <i class="bi bi-box-arrow-up-right ms-1 small text-muted"></i>
                                </a>
                                <?php if (!empty($v['class_name'])): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-0 ms-1 small">
                                        <?php echo htmlspecialchars($v['class_name']); ?>
                                    </span>
                                <?php endif; ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($v['student_email']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($v['exam_title']); ?></td>
                            <td>
                                <span class="badge <?php echo $badge[0]; ?> rounded-pill px-2 py-1">
                                    <i class="bi <?php echo $badge[1]; ?> me-1"></i><?php echo $badge[2]; ?>
                                </span>
                            </td>
                            <td class="text-muted"><?php echo htmlspecialchars($v['detail'] ?? '—'); ?></td>
                            <td class="font-monospace text-muted"><?php echo htmlspecialchars($v['ip_address'] ?? '—'); ?></td>
                            <td class="pe-4 text-end text-muted font-monospace"><?php echo date('d M Y, h:i:s A', strtotime($v['occurred_at'])); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// ── State & Config ────────────────────────────────────────────────────────────
let visibleOffendersCount = Math.min(3, <?php echo count($summaries); ?>);
let totalOffendersCount   = <?php echo count($summaries); ?>;
let lastKnownId           = <?php echo (int)$max_initial_id; ?>;
let isAutoSyncEnabled     = true;
let isSyncing             = false;
let soundAlertEnabled     = localStorage.getItem('violation_sound_enabled') !== '0';
let pollTimer             = null;
const POLL_INTERVAL_MS    = 4000;

const activeFilters = {
    exam_id:    <?php echo (int)$filter_exam; ?>,
    student_id: <?php echo (int)$filter_student; ?>,
    type:       <?php echo json_encode($filter_type); ?>
};

// ── Audio Alert Synthesizer (Native Web Audio API chime) ──────────────────────
function playAlertChime() {
    if (!soundAlertEnabled) return;
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();
        if (ctx.state === 'suspended') {
            ctx.resume();
        }
        const osc  = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = 'sine';
        osc.frequency.setValueAtTime(540, ctx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(820, ctx.currentTime + 0.12);

        gain.gain.setValueAtTime(0.18, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.28);

        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.start();
        osc.stop(ctx.currentTime + 0.28);
    } catch(e) {}
}

function toggleSoundAlert() {
    soundAlertEnabled = !soundAlertEnabled;
    try { localStorage.setItem('violation_sound_enabled', soundAlertEnabled ? '1' : '0'); } catch(e) {}
    const icon  = document.getElementById('soundIcon');
    const label = document.getElementById('soundLabel');
    const btn   = document.getElementById('toggleSoundBtn');
    if (soundAlertEnabled) {
        icon.className = 'bi bi-volume-up me-1';
        label.textContent = 'Sound: On';
        btn.classList.remove('btn-outline-danger');
        btn.classList.add('btn-outline-secondary');
        playAlertChime();
    } else {
        icon.className = 'bi bi-volume-mute me-1';
        label.textContent = 'Sound: Off';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-outline-danger');
    }
}

// Set sound toggle state on initial load
if (!soundAlertEnabled) {
    const icon  = document.getElementById('soundIcon');
    const label = document.getElementById('soundLabel');
    const btn   = document.getElementById('toggleSoundBtn');
    if (icon && label && btn) {
        icon.className = 'bi bi-volume-mute me-1';
        label.textContent = 'Sound: Off';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-outline-danger');
    }
}

// ── Polling Controls ──────────────────────────────────────────────────────────
function startPolling() {
    stopPolling();
    if (isAutoSyncEnabled && document.visibilityState === 'visible') {
        pollTimer = setInterval(() => fetchViolationsFeed(false), POLL_INTERVAL_MS);
    }
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

// ── Auto-Sync Toggle Control ──────────────────────────────────────────────────
function toggleAutoSync() {
    isAutoSyncEnabled = !isAutoSyncEnabled;
    const icon      = document.getElementById('syncIcon');
    const label     = document.getElementById('syncLabel');
    const stateText = document.getElementById('syncStateText');
    const dot       = document.getElementById('liveStatusDot');
    const badge     = document.getElementById('liveStatusBadge');

    if (isAutoSyncEnabled) {
        icon.className = 'bi bi-pause-fill me-1';
        label.textContent = 'Pause';
        stateText.textContent = 'Active';
        dot.className = 'spinner-grow spinner-grow-sm text-success';
        badge.className = 'badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1.5 small fw-semibold d-inline-flex align-items-center gap-1.5';
        startPolling();
        fetchViolationsFeed(true);
    } else {
        icon.className = 'bi bi-play-fill me-1';
        label.textContent = 'Resume';
        stateText.textContent = 'Paused';
        dot.className = 'spinner-grow spinner-grow-sm text-secondary';
        badge.className = 'badge bg-light text-muted border rounded-pill px-3 py-1.5 small fw-semibold d-inline-flex align-items-center gap-1.5';
        stopPolling();
    }
}

// ── Floating Toast Alert ──────────────────────────────────────────────────────
function showViolationToast(studentName, violationType, examTitle, detail) {
    const container = document.getElementById('violationToastContainer');
    if (!container) return;

    const toastId = 'toast_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
    const toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center text-bg-danger border-0 shadow-lg mb-2';
    toastEl.id = toastId;
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('aria-atomic', 'true');
    toastEl.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1 text-warning"></i> New Integrity Alert!</div>
                <div class="small"><strong>${escapeHtml(studentName)}</strong> committed <strong>${escapeHtml(violationType)}</strong> in <em>${escapeHtml(examTitle)}</em></div>
                ${detail ? `<div class="text-white-50 small mt-1">${escapeHtml(detail)}</div>` : ''}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    `;
    container.appendChild(toastEl);
    if (typeof bootstrap !== 'undefined' && bootstrap.Toast) {
        const bsToast = new bootstrap.Toast(toastEl, { delay: 7000 });
        bsToast.show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    } else {
        setTimeout(() => toastEl.remove(), 7000);
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// ── Re-index Table Row Numbers ─────────────────────────────────────────────────
function reindexViolationsTable() {
    const rows = document.querySelectorAll('#violationsTableBody tr:not(.empty-state-row)');
    let idx = 1;
    rows.forEach(r => {
        const numCell = r.querySelector('.row-idx');
        if (numCell) {
            numCell.textContent = idx++;
        }
    });
}

// ── Dynamic AJAX Fetch & Real-Time Reconciliation ─────────────────────────────
async function fetchViolationsFeed(isManual = false) {
    if (isSyncing) return;
    if (!isAutoSyncEnabled && !isManual) return;

    isSyncing = true;
    const refreshIcon = document.getElementById('refreshIcon');
    if (refreshIcon) refreshIcon.classList.add('spin-animation');

    const params = new URLSearchParams();
    if (lastKnownId > 0) params.append('after_id', lastKnownId);
    if (activeFilters.exam_id) params.append('exam_id', activeFilters.exam_id);
    if (activeFilters.student_id) params.append('student_id', activeFilters.student_id);
    if (activeFilters.type) params.append('type', activeFilters.type);

    try {
        const res = await fetch('ajax_violations_feed.php?' + params.toString(), {
            cache: 'no-store'
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();

        if (data.ok) {
            // Update sync timestamp
            const syncTime = document.getElementById('syncTime');
            if (syncTime) syncTime.textContent = data.synced_at || 'just now';

            // Check for new events
            if (data.events && data.events.length > 0) {
                const tbody = document.getElementById('violationsTableBody');
                // Remove empty state row if present
                const emptyRow = tbody.querySelector('.empty-state-row');
                if (emptyRow) emptyRow.remove();

                let hasSeriousNew = false;
                let maxDeliveredId = lastKnownId;

                // data.events are ordered ASC by id: iterate from oldest to newest so newest ends up at top
                for (let i = 0; i < data.events.length; i++) {
                    const ev = data.events[i];
                    if (ev.id > maxDeliveredId) {
                        maxDeliveredId = ev.id;
                    }
                    if (ev.is_serious) {
                        hasSeriousNew = true;
                        showViolationToast(ev.student_name, ev.badge_label, ev.exam_title, ev.detail);
                    }

                    const tr = document.createElement('tr');
                    tr.className = 'row-new-violation';
                    tr.setAttribute('data-id', ev.id);
                    tr.innerHTML = `
                        <td class="ps-4 text-muted row-idx">#</td>
                        <td>
                            <a href="student-profile.php?id=${ev.student_id}" class="fw-semibold text-dark text-decoration-none">
                                ${escapeHtml(ev.student_name)}
                                <i class="bi bi-box-arrow-up-right ms-1 small text-muted"></i>
                            </a>
                            ${ev.class_name ? `<span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-0 ms-1 small">${escapeHtml(ev.class_name)}</span>` : ''}
                            <br><small class="text-muted">${escapeHtml(ev.student_email)}</small>
                        </td>
                        <td>${escapeHtml(ev.exam_title)}</td>
                        <td>
                            <span class="badge ${ev.badge_bg} rounded-pill px-2 py-1">
                                <i class="bi ${ev.badge_icon} me-1"></i>${escapeHtml(ev.badge_label)}
                            </span>
                        </td>
                        <td class="text-muted">${escapeHtml(ev.detail)}</td>
                        <td class="font-monospace text-muted">${escapeHtml(ev.ip_address)}</td>
                        <td class="pe-4 text-end text-muted font-monospace">${escapeHtml(ev.occurred_at)}</td>
                    `;
                    tbody.insertBefore(tr, tbody.firstChild);
                }

                // Advance cursor only to highest delivered ID to guarantee zero missing events in bursts
                if (maxDeliveredId > lastKnownId) {
                    lastKnownId = maxDeliveredId;
                }

                if (hasSeriousNew) {
                    playAlertChime();
                }

                reindexViolationsTable();
            }

            // Update Total Events Badge
            const allEventsCount = document.getElementById('allEventsCount');
            if (allEventsCount) {
                allEventsCount.textContent = data.total_count;
            }

            // Update Top Offenders Summary
            if (data.summaries) {
                updateOffendersData(data.summaries);
            }
        }
    } catch(err) {
        console.warn('Violations live sync error:', err);
    } finally {
        isSyncing = false;
        if (refreshIcon) refreshIcon.classList.remove('spin-animation');
    }
}

// ── Update Offenders Summary Table & Controls ─────────────────────────────────
function updateOffendersData(summaries) {
    const card        = document.getElementById('flaggedStudentsCard');
    const tbody       = document.getElementById('offendersTableBody');
    const totalBadge  = document.getElementById('offendersTotalBadge');
    const footer      = document.getElementById('offendersFooter');
    const footerCount = document.getElementById('offendersFooterCount');
    const maxBtnCount = document.getElementById('btnMaximizeCount');

    totalOffendersCount = summaries.length;
    if (totalBadge) totalBadge.textContent = `${totalOffendersCount} total`;
    if (footerCount) footerCount.textContent = totalOffendersCount;
    if (maxBtnCount) maxBtnCount.textContent = totalOffendersCount;

    if (totalOffendersCount === 0) {
        if (card) card.style.display = 'none';
        return;
    } else {
        if (card) card.style.display = '';
    }

    if (tbody) {
        tbody.innerHTML = summaries.map((s, idx) => {
            const isExtra = (idx >= visibleOffendersCount);
            return `
                <tr class="offender-row ${isExtra ? 'd-none offender-extra' : ''}" data-index="${idx}">
                    <td class="ps-4">
                        <div class="fw-semibold text-dark">${escapeHtml(s.name)}</div>
                        <small class="text-muted">${escapeHtml(s.email)}</small>
                    </td>
                    <td>
                        <div class="fw-semibold text-dark">${escapeHtml(s.exam_title)}</div>
                        ${s.latest_time ? `<small class="text-muted"><i class="bi bi-clock me-1"></i>${escapeHtml(s.latest_time)}</small>` : ''}
                    </td>
                    <td>
                        <span class="badge bg-${s.risk_badge} rounded-pill px-3 py-2 fs-6">
                            ${s.serious}
                        </span>
                    </td>
                    <td>
                        <span class="fw-semibold text-dark">${s.total}</span>
                    </td>
                    <td class="pe-4 text-end">
                        <a href="?student_id=${s.student_id}&exam_id=${s.exam_id}" class="btn btn-sm btn-outline-danger fw-semibold">
                            <i class="bi bi-search me-1"></i> View
                        </a>
                    </td>
                </tr>
            `;
        }).join('');
    }

    if (footer) {
        if (totalOffendersCount <= 3) {
            footer.classList.add('d-none');
        } else {
            footer.classList.remove('d-none');
        }
    }

    updateOffendersUI();
}

function updateOffendersUI() {
    const rows = document.querySelectorAll('.offender-row');
    rows.forEach((row, idx) => {
        if (idx < visibleOffendersCount) {
            row.classList.remove('d-none');
        } else {
            row.classList.add('d-none');
        }
    });

    const countElem = document.getElementById('offendersVisibleCount');
    if (countElem) countElem.textContent = Math.min(visibleOffendersCount, totalOffendersCount);

    const summaryElem = document.getElementById('offendersCountSummary');
    if (summaryElem) {
        if (visibleOffendersCount >= totalOffendersCount) {
            summaryElem.textContent = `Showing all ${totalOffendersCount}`;
        } else {
            summaryElem.textContent = `Showing top ${Math.min(visibleOffendersCount, totalOffendersCount)} recent`;
        }
    }

    const btnNext = document.getElementById('btnNextThree');
    const btnMax  = document.getElementById('btnMaximizeAll');
    const btnMin  = document.getElementById('btnMinimizeOffenders');

    if (btnNext && btnMax && btnMin) {
        if (visibleOffendersCount >= totalOffendersCount) {
            btnNext.classList.add('d-none');
            btnMax.classList.add('d-none');
            btnMin.classList.remove('d-none');
        } else if (visibleOffendersCount > 3) {
            btnNext.classList.remove('d-none');
            btnMax.classList.remove('d-none');
            btnMin.classList.remove('d-none');
        } else {
            btnNext.classList.remove('d-none');
            btnMax.classList.remove('d-none');
            btnMin.classList.add('d-none');
        }
    }
}

function showNextOffenders(step = 3) {
    visibleOffendersCount = Math.min(totalOffendersCount, visibleOffendersCount + step);
    updateOffendersUI();
}

function maximizeOffenders() {
    visibleOffendersCount = totalOffendersCount;
    updateOffendersUI();
}

function minimizeOffenders() {
    visibleOffendersCount = Math.min(3, totalOffendersCount);
    updateOffendersUI();
    const card = document.getElementById('flaggedStudentsCard');
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Polling Lifecycle ─────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    reindexViolationsTable();
    startPolling();
});

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        if (isAutoSyncEnabled) {
            fetchViolationsFeed(false);
            startPolling();
        }
    } else {
        // Tab hidden or minimized: stop background polling to save server and DB capacity
        stopPolling();
    }
});
</script>

<?php include '../includes/footer.php'; ?>
