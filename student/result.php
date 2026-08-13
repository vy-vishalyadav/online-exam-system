<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: ../index.php");
    exit;
}

$student_id = (int)$_SESSION['student_id'];
$submission_review = null;
$error_msg = "";

// Handle POST exam submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exam_id'])) {
    $exam_id = (int)$_POST['exam_id'];
    $user_answers = $_POST['answer'] ?? [];

    $q_exam = mysqli_query($conn, "SELECT * FROM exams WHERE id = $exam_id LIMIT 1");
    $exam = ($q_exam ? mysqli_fetch_assoc($q_exam) : null);

    if ($exam) {
        $questions_res = mysqli_query($conn, "SELECT * FROM questions WHERE exam_id = $exam_id ORDER BY id ASC");
        $questions = [];
        $total_questions = 0;
        $correct_count = 0;
        $review_items = [];

        if ($questions_res) {
            while ($q = mysqli_fetch_assoc($questions_res)) {
                $total_questions++;
                $q_id = $q['id'];
                $user_ans = isset($user_answers[$q_id]) ? strtoupper($user_answers[$q_id]) : null;
                $correct_ans = strtoupper($q['correct_option']);
                $is_correct = ($user_ans === $correct_ans);

                if ($is_correct) {
                    $correct_count++;
                }

                $review_items[] = [
                    'question' => $q['question_text'],
                    'option_a' => $q['option_a'],
                    'option_b' => $q['option_b'],
                    'option_c' => $q['option_c'],
                    'option_d' => $q['option_d'],
                    'user_ans' => $user_ans,
                    'correct_ans' => $correct_ans,
                    'is_correct' => $is_correct
                ];
            }
        }

        $score_percentage = ($total_questions > 0) ? round(($correct_count / $total_questions) * 100) : 0;

        // Save result in database
        $insert_result = "INSERT INTO results (student_id, exam_id, score, attempted_at) VALUES ($student_id, $exam_id, $score_percentage, NOW())";
        mysqli_query($conn, $insert_result);

        $submission_review = [
            'exam_title' => $exam['title'],
            'total' => $total_questions,
            'correct' => $correct_count,
            'wrong' => $total_questions - $correct_count,
            'score' => $score_percentage,
            'passed' => $score_percentage >= 50,
            'items' => $review_items
        ];
    } else {
        $error_msg = "Invalid exam submission.";
    }
}

// Fetch all past results for this student
$past_results = mysqli_query($conn, "SELECT r.*, e.title AS exam_title
                                     FROM results r
                                     JOIN exams e ON r.exam_id = e.id
                                     WHERE r.student_id = $student_id
                                     ORDER BY r.attempted_at DESC");
?>

<?php if ($submission_review): ?>
    <!-- Instant Submission Scorecard Banner -->
    <div class="card shadow-lg border-0 rounded-4 mb-5 overflow-hidden">
        <div class="card-header p-4 text-center text-white <?php echo $submission_review['passed'] ? 'bg-success' : 'bg-danger'; ?>">
            <div class="mb-2">
                <i class="bi <?php echo $submission_review['passed'] ? 'bi-trophy-fill' : 'bi-exclamation-octagon-fill'; ?> fs-1"></i>
            </div>
            <h2 class="fw-extrabold mb-1"><?php echo $submission_review['passed'] ? 'Congratulations! Exam Passed 🎉' : 'Exam Completed'; ?></h2>
            <p class="mb-0 text-white opacity-75">Result summary for <strong><?php echo htmlspecialchars($submission_review['exam_title']); ?></strong></p>
        </div>

        <div class="card-body p-4 p-md-5">
            <div class="row g-4 text-center justify-content-center mb-4">
                <div class="col-6 col-md-3">
                    <div class="bg-light p-3 rounded-4 border">
                        <small class="text-muted fw-semibold">Your Score</small>
                        <h2 class="fw-extrabold <?php echo $submission_review['passed'] ? 'text-success' : 'text-danger'; ?> mb-0">
                            <?php echo $submission_review['score']; ?>%
                        </h2>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="bg-light p-3 rounded-4 border">
                        <small class="text-muted fw-semibold">Correct Answers</small>
                        <h2 class="fw-extrabold text-success mb-0">
                            <?php echo $submission_review['correct']; ?> / <?php echo $submission_review['total']; ?>
                        </h2>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="bg-light p-3 rounded-4 border">
                        <small class="text-muted fw-semibold">Wrong / Unanswered</small>
                        <h2 class="fw-extrabold text-danger mb-0">
                            <?php echo $submission_review['wrong']; ?>
                        </h2>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="bg-light p-3 rounded-4 border">
                        <small class="text-muted fw-semibold">Result Status</small>
                        <div class="mt-1">
                            <?php if ($submission_review['passed']): ?>
                                <span class="badge bg-success fs-6 px-3 py-2 rounded-pill">PASSED</span>
                            <?php else: ?>
                                <span class="badge bg-danger fs-6 px-3 py-2 rounded-pill">FAILED</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detailed Question Breakdown -->
            <h5 class="fw-bold mb-3 text-dark"><i class="bi bi-list-check me-2 text-primary"></i> Answer Breakdown</h5>
            
            <div class="accordion mb-4" id="reviewAccordion">
                <?php foreach ($submission_review['items'] as $idx => $item): 
                    $num = $idx + 1;
                    $opt_map = ['A' => $item['option_a'], 'B' => $item['option_b'], 'C' => $item['option_c'], 'D' => $item['option_d']];
                ?>
                    <div class="accordion-item border rounded-3 mb-2 overflow-hidden">
                        <h2 class="accordion-header">
                            <button class="accordion-button <?php echo $item['is_correct'] ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $num; ?>">
                                <div class="d-flex align-items-center gap-2 w-100 me-3">
                                    <span class="fw-bold">Q<?php echo $num; ?>:</span>
                                    <span class="text-truncate flex-grow-1 text-dark fw-semibold"><?php echo htmlspecialchars($item['question']); ?></span>
                                    <?php if ($item['is_correct']): ?>
                                        <span class="badge bg-success rounded-pill px-3 py-1">Correct</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger rounded-pill px-3 py-1">Incorrect</span>
                                    <?php endif; ?>
                                </div>
                            </button>
                        </h2>
                        <div id="collapse<?php echo $num; ?>" class="accordion-collapse collapse show" data-bs-parent="#reviewAccordion">
                            <div class="accordion-body bg-white">
                                <p class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($item['question']); ?></p>
                                <div class="small mb-2">
                                    <strong>Your Answer:</strong> 
                                    <?php if ($item['user_ans']): ?>
                                        <span class="<?php echo $item['is_correct'] ? 'text-success fw-bold' : 'text-danger fw-bold'; ?>">
                                            Option <?php echo $item['user_ans']; ?>: <?php echo htmlspecialchars($opt_map[$item['user_ans']] ?? ''); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted fst-italic">Not answered</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$item['is_correct']): ?>
                                    <div class="small text-success fw-bold">
                                        <i class="bi bi-check-circle-fill me-1"></i> Correct Answer: Option <?php echo $item['correct_ans']; ?>: <?php echo htmlspecialchars($opt_map[$item['correct_ans']] ?? ''); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="text-center">
                <a href="dashboard.php" class="btn btn-primary btn-lg px-5 rounded-pill shadow-sm fw-bold">
                    <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Past Results History Table -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-clock-history text-primary me-2"></i> My Exam History</h4>
        <small class="text-muted">A record of all your exam attempts.</small>
    </div>
    <a href="dashboard.php" class="btn btn-outline-primary fw-semibold">
        <i class="bi bi-arrow-left"></i> Dashboard
    </a>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table custom-table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Exam Title</th>
                        <th>Score</th>
                        <th>Status</th>
                        <th class="pe-4 text-end">Attempted On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($past_results && mysqli_num_rows($past_results) > 0):
                        $i = 1;
                        while ($r = mysqli_fetch_assoc($past_results)):
                            $passed = $r['score'] >= 50;
                    ?>
                        <tr>
                            <td class="ps-4 fw-bold"><?php echo $i++; ?></td>
                            <td><strong class="text-dark"><?php echo htmlspecialchars($r['exam_title']); ?></strong></td>
                            <td>
                                <span class="fw-extrabold fs-6 <?php echo $passed ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $r['score']; ?>%
                                </span>
                            </td>
                            <td>
                                <?php if ($passed): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 fw-bold">
                                        <i class="bi bi-check-circle-fill me-1"></i> Passed
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1 fw-bold">
                                        <i class="bi bi-x-circle-fill me-1"></i> Failed
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="pe-4 text-end text-muted small"><?php echo date('d M Y, h:i A', strtotime($r['attempted_at'])); ?></td>
                        </tr>
                    <?php
                        endwhile;
                    else:
                    ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i> You have not attempted any exam yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
