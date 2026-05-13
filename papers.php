<?php
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$pdo = getDB();

// Check if feature is enabled
$isEnabledStmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'enable_papers'");
$isEnabled = $isEnabledStmt->fetchColumn();
if ($isEnabled === '0') {
    header("Location: dashboard.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$grade_id = $_SESSION['grade_id'] ?? null;

if (!$grade_id) {
    die("Grade not assigned to your account. Please contact admin.");
}

$success = '';
$error = '';

// Handle Answer Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    try {
        if ($action === 'submit_mcq') {
            $paper_id = $_POST['paper_id'];
            $mcq_answers = $_POST['answers'] ?? [];
            
            $cfgStmt = $pdo->prepare("SELECT mcq_config FROM papers WHERE id = ?");
            $cfgStmt->execute([$paper_id]);
            $config = json_decode($cfgStmt->fetchColumn(), true);
            
            $score = 0;
            $total_questions = $config['num_questions'];
            foreach ($config['answers'] as $qNum => $correct) {
                if (isset($mcq_answers[$qNum]) && $mcq_answers[$qNum] === $correct) {
                    $score++;
                }
            }
            
            $percentage = round(($score / $total_questions) * 100);

            $stmt = $pdo->prepare("SELECT id FROM paper_submissions WHERE paper_id = ? AND user_id = ?");
            $stmt->execute([$paper_id, $user_id]);
            $sub = $stmt->fetch();

            if ($sub) {
                $stmt = $pdo->prepare("UPDATE paper_submissions SET mcq_answers = ?, mcq_score = ?, mcq_submitted_at = NOW() WHERE id = ?");
                $stmt->execute([json_encode($mcq_answers), $percentage, $sub['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO paper_submissions (paper_id, user_id, mcq_answers, mcq_score, mcq_submitted_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$paper_id, $user_id, json_encode($mcq_answers), $percentage]);
            }
            $success = "MCQ answers submitted! Score: $percentage%";
        } elseif ($action === 'upload_essay') {
            $paper_id = $_POST['paper_id'];
            if (isset($_FILES['answer_pdf']) && $_FILES['answer_pdf']['error'] === UPLOAD_ERR_OK) {
                // SEC-5/6 validation
                $max_bytes = 40 * 1024 * 1024;
                if ($_FILES['answer_pdf']['size'] > $max_bytes) {
                    throw new Exception("File too large (max 40MB)");
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                if ($finfo->file($_FILES['answer_pdf']['tmp_name']) !== 'application/pdf') {
                    throw new Exception("Only PDF files allowed");
                }

                $filename = 'essay_' . $user_id . '_' . bin2hex(random_bytes(4)) . '.pdf';
                $upload_dir = TenantContext::getUploadDir('submissions/');
                move_uploaded_file($_FILES['answer_pdf']['tmp_name'], $upload_dir . '/' . $filename);
                
                $stmt = $pdo->prepare("SELECT id FROM paper_submissions WHERE paper_id = ? AND user_id = ?");
                $stmt->execute([$paper_id, $user_id]);
                $sub = $stmt->fetch();

                if ($sub) {
                    $stmt = $pdo->prepare("UPDATE paper_submissions SET submission_path = ?, essay_submitted_at = NOW(), essay_status = 'submitted', status = 'submitted' WHERE id = ?");
                    $stmt->execute([$filename, $sub['id']]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO paper_submissions (paper_id, user_id, submission_path, essay_submitted_at, essay_status, status) VALUES (?, ?, ?, NOW(), 'submitted', 'submitted')");
                    $stmt->execute([$paper_id, $user_id, $filename]);
                }
                $success = "Essay paper submitted successfully!";
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/includes/header.php';

// Access logic
$pStmt = $pdo->prepare("SELECT subject_id FROM payments WHERE user_id = ? AND status = 'approved'");
$pStmt->execute([$user_id]);
$approved_subject_ids = $pStmt->fetchAll(PDO::FETCH_COLUMN);

$gpStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'grace_period_days'");
$gpStmt->execute();
$grace_period_days = (int)($gpStmt->fetchColumn() ?: 5);

if ((int)date('j') <= $grace_period_days) {
    $prev_month = (int)date('n', strtotime('last month'));
    $prev_year = (int)date('Y', strtotime('last month'));
    $pgStmt = $pdo->prepare("SELECT subject_id FROM payments WHERE user_id = ? AND status = 'approved' AND MONTH(created_at) = ? AND YEAR(created_at) = ?");
    $pgStmt->execute([$user_id, $prev_month, $prev_year]);
    $approved_subject_ids = array_unique(array_merge($approved_subject_ids, $pgStmt->fetchAll(PDO::FETCH_COLUMN)));
}

if (empty($approved_subject_ids)) {
    $papers = [];
} else {
    $placeholders = implode(',', array_fill(0, count($approved_subject_ids), '?'));
    $papersStmt = $pdo->prepare("
        SELECT p.id, p.subject_id, p.title, p.paper_type, p.deadline, p.created_at,
               p.pdf_path, p.essay_pdf_path, p.mcq_pdf_path, p.mcq_config,
               s.name as subject_name,
               ps.id as submission_id, ps.status as submission_status,
               ps.total_marks as marks, ps.marked_pdf_path,
               ps.mcq_score, ps.essay_marks, ps.mcq_submitted_at, ps.essay_submitted_at
        FROM papers p
        JOIN subjects s ON p.subject_id = s.id
        LEFT JOIN paper_submissions ps ON p.id = ps.paper_id AND ps.user_id = ?
        WHERE p.subject_id IN ($placeholders)
        ORDER BY p.created_at DESC
    ");
    $papersStmt->execute(array_merge([$user_id], $approved_subject_ids));
    $papers = $papersStmt->fetchAll();
}
?>

<link rel="stylesheet" href="<?= SITE_ROOT ?>css/learning.css?v=<?= filemtime(__DIR__ . '/css/learning.css') ?>">


<main class="container py-8">
    <div class="mb-8">
        <h1>Academic Papers</h1>
        <p class="text-secondary">Track assignments and view results.</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success mb-6"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error mb-6"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (empty($papers)): ?>
        <div class="card text-center py-12">
            <h3 class="mb-2">No Papers Found</h3>
            <p class="text-secondary mb-6">Enroll in subjects to see relevant papers.</p>
            <a href="courses.php" class="btn btn-primary">Browse Courses</a>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-2">
            <?php foreach ($papers as $p): ?>
                <div class="card paper-card">
                    <div class="flex-between mb-4">
                        <div>
                            <div style="font-size: 10px; font-weight: 700; color: var(--blue-600); text-transform: uppercase; margin-bottom: 2px;"><?= htmlspecialchars($p['subject_name']) ?></div>
                            <h4 style="font-size: 1.15rem;"><?= htmlspecialchars($p['title']) ?></h4>
                        </div>
                        <?php if ($p['submission_status'] === 'marked'): ?>
                            <div class="text-right">
                                <div class="score-badge"><?= $p['marks'] ?></div>
                                <div style="font-size: 9px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase;">Marks</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="paper-meta">
                        <span class="badge badge-gray"><?= strtoupper($p['paper_type']) ?></span>
                        <?php if ($p['mcq_submitted_at']): ?><span class="badge badge-green">MCQ: <?= $p['mcq_score'] ?>%</span><?php endif; ?>
                        <?php if ($p['essay_submitted_at']): ?><span class="badge badge-green">Essay Done</span><?php endif; ?>
                    </div>

                    <div style="font-size: 0.85rem; color: var(--text-tertiary); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 6px;">
                        <svg class="icon icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                          <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                        </svg>
                        Deadline: <?= date('M j, Y g:i A', strtotime($p['deadline'])) ?>
                    </div>

                    <div class="grid grid-cols-2 gap-2 mb-6">
                        <?php
                            $pt = $p['paper_type'];
                            $essay_url = !empty($p['essay_pdf_path']) ? $p['essay_pdf_path'] : $p['pdf_path'];
                            $mcq_url = !empty($p['mcq_pdf_path']) ? $p['mcq_pdf_path'] : ($pt === 'mcq' ? $p['pdf_path'] : null);
                        ?>
                        <?php if ($essay_url): ?>
                            <a href="<?= TenantContext::getUploadUrl('papers/' . $essay_url) ?>" target="_blank" class="btn btn-secondary btn-sm" style="justify-content: center;">
                              <svg class="icon icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7,10 12,15 17,10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                              Essay PDF
                            </a>
                        <?php endif; ?>
                        <?php if ($mcq_url): ?>
                            <a href="<?= TenantContext::getUploadUrl('papers/' . $mcq_url) ?>" target="_blank" class="btn btn-secondary btn-sm" style="justify-content: center;">
                              <svg class="icon icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7,10 12,15 17,10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                              MCQ PDF
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="mt-auto pt-4" style="border-top: 1px solid var(--border-default);">
                        <?php if ($p['submission_status'] === 'marked'): ?>
                            <?php if ($p['marked_pdf_path']): ?>
                                <a href="<?= TenantContext::getUploadUrl('marked/' . $p['marked_pdf_path']) ?>" target="_blank" class="btn btn-primary btn-block">Download Results</a>
                            <?php else: ?>
                                <button disabled class="btn btn-secondary btn-block">Marks Processing...</button>
                            <?php endif; ?>
                        <?php elseif (strtotime($p['deadline']) > time()): ?>
                            <div class="flex gap-2">
                                <?php if (($pt === 'mcq' || $pt === 'both') && !$p['mcq_submitted_at']): ?>
                                    <button class="btn btn-primary btn-block" onclick='openMcqModal(<?= json_encode($p) ?>)'>MCQ</button>
                                <?php endif; ?>
                                <?php if (($pt === 'essay' || $pt === 'both') && !$p['essay_submitted_at']): ?>
                                    <button class="btn btn-primary btn-block" onclick="openUploadModal(<?= $p['id'] ?>, '<?= htmlspecialchars($p['title']) ?>')">Upload</button>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <button disabled class="btn btn-secondary btn-block">Deadline Passed</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<!-- Modals -->
<div class="modal-backdrop" id="modal-backdrop" onclick="closeModals()"></div>

<div class="modal" id="uploadModal" style="max-width: 440px;">
    <h3 class="mb-2">Submit Essay</h3>
    <p id="paperTitle" class="text-secondary mb-6" style="font-size: 0.85rem;"></p>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_essay">
        <input type="hidden" name="paper_id" id="modalPaperId">
        <div class="form-group mb-8">
            <label>PDF Answer Sheet</label>
            <input type="file" name="answer_pdf" accept=".pdf" required>
        </div>
        <div class="flex gap-3">
            <button type="button" onclick="closeModals()" class="btn btn-secondary flex-1">Cancel</button>
            <button type="submit" class="btn btn-primary flex-1">Submit</button>
        </div>
    </form>
</div>

<div class="modal" id="mcqModal" style="max-width: 760px;">
    <h3 class="mb-2">MCQ Answer Sheet</h3>
    <p id="mcqPaperTitle" class="text-secondary mb-6" style="font-size: 0.85rem;"></p>
    <form method="POST" id="mcqForm">
        <input type="hidden" name="action" value="submit_mcq">
        <input type="hidden" name="paper_id" id="mcqPaperId">
        <div id="mcqContainer" class="mcq-container mb-8"></div>
        <div class="flex gap-3">
            <button type="button" onclick="closeModals()" class="btn btn-secondary flex-1">Cancel</button>
            <button type="button" onclick="submitMcq()" class="btn btn-primary flex-1">Submit All</button>
        </div>
    </form>
</div>

<script>
function openUploadModal(id, title) {
    document.getElementById('modalPaperId').value = id;
    document.getElementById('paperTitle').textContent = title;
    showModal('uploadModal');
}

function openMcqModal(p) {
    var cfg = JSON.parse(p.mcq_config);
    document.getElementById('mcqPaperId').value = p.id;
    document.getElementById('mcqPaperTitle').textContent = p.title;
    var cont = document.getElementById('mcqContainer');
    var html = '';
    for(var i=1; i<=cfg.num_questions; i++) {
        html += '<div class="mcq-item"><div style="font-size:10px;font-weight:700;color:var(--text-tertiary);margin-bottom:6px;">Q'+i+'</div><div class="flex gap-1">';
        for(var j=1; j<=cfg.num_options; j++) {
            html += '<label><input type="radio" name="answers['+i+']" value="'+j+'" style="display:none;"><span class="mcq-opt">'+j+'</span></label>';
        }
        html += '</div></div>';
    }
    cont.innerHTML = html;
    showModal('mcqModal');
}

function showModal(id) {
    document.getElementById('modal-backdrop').style.display = 'block';
    document.getElementById(id).style.display = 'block';
    setTimeout(() => {
        document.getElementById('modal-backdrop').classList.add('active');
        document.getElementById(id).classList.add('active');
    }, 10);
}

function closeModals() {
    document.querySelectorAll('.modal, .modal-backdrop').forEach(m => m.classList.remove('active'));
    setTimeout(() => {
        document.querySelectorAll('.modal, .modal-backdrop').forEach(m => m.style.display = 'none');
    }, 300);
}

function submitMcq() {
    var f = document.getElementById('mcqForm');
    var total = f.querySelectorAll('.mcq-item').length;
    var done = f.querySelectorAll('input[type="radio"]:checked').length;
    if (done < total && !confirm('Incomplete! Only '+done+'/'+total+' answered. Submit?')) return;
    if (confirm('Final submission? This cannot be changed.')) f.submit();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
