<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/header.php';

$pdo = getDB();

// Check if feature is enabled
$isEnabledStmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'enable_papers'");
$isEnabled = $isEnabledStmt->fetchColumn();
if ($isEnabled === '0') {
    header("Location: index.php");
    exit;
}

// Fetch Subjects and their LATEST paper that has marked submissions
$subject_rankings = [];
$active_subjects = $pdo->query("
    SELECT s.id as subject_id, s.name as subject_name, g.name as grade_name, 
           p.id as paper_id, p.title as paper_title
    FROM subjects s 
    JOIN papers p ON p.subject_id = s.id 
    JOIN grades g ON s.grade_id = g.id
    WHERE EXISTS (
        SELECT 1 FROM paper_submissions ps WHERE ps.paper_id = p.id AND ps.status = 'marked'
    )
    AND p.id = (
        SELECT p2.id FROM papers p2 
        JOIN paper_submissions ps2 ON ps2.paper_id = p2.id 
        WHERE p2.subject_id = s.id AND ps2.status = 'marked'
        ORDER BY p2.created_at DESC LIMIT 1
    )
")->fetchAll();

foreach ($active_subjects as $sub_info) {
    $stmt = $pdo->prepare("
        SELECT u.username, u.student_id, ps.total_marks as marks, ps.submitted_at
        FROM paper_submissions ps
        JOIN users u ON ps.user_id = u.id
        WHERE ps.paper_id = ? AND ps.status = 'marked'
        ORDER BY ps.total_marks DESC, ps.submitted_at ASC
        LIMIT 10
    ");
    $stmt->execute([$sub_info['paper_id']]);
    $subject_rankings[] = [
        'subject' => $sub_info['subject_name'],
        'grade' => $sub_info['grade_name'],
        'paper_title' => $sub_info['paper_title'],
        'students' => $stmt->fetchAll()
    ];
}
?>

<link rel="stylesheet" href="<?= SITE_ROOT ?>css/learning.css?v=<?= filemtime(__DIR__ . '/css/learning.css') ?>">


<main class="container py-12">
    <div class="mb-12 text-center">
        <div class="icon-wrap icon-wrap-lg mb-4" style="margin: 0 auto;">
            <svg class="icon icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
              <path d="M6 9H4.5a2.5 2.5 0 010-5H6"/><path d="M18 9h1.5a2.5 2.5 0 000-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17a2 2 0 01-2 2H8"/><path d="M14 14.66V17a2 2 0 002 2h0"/><path d="M6 2h12v7a6 6 0 01-12 0z"/>
            </svg>
        </div>
        <h1 class="mb-2">Academic Rankings</h1>
        <p class="text-secondary">Celebrating excellence across our learning community.</p>
    </div>

    <div class="grid grid-cols-2">
        <?php foreach ($subject_rankings as $sr): ?>
            <div class="card" style="padding: 0; overflow: hidden;">
                <div style="padding: 1.5rem; background: var(--surface-subtle); border-bottom: 1px solid var(--border-default);">
                    <div class="flex-between">
                        <div>
                            <div class="badge badge-blue mb-1"><?= htmlspecialchars($sr['grade']) ?></div>
                            <h3 style="margin: 0;"><?= htmlspecialchars($sr['subject']) ?></h3>
                        </div>
                        <div class="text-right">
                            <div style="font-size: 10px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase;">Latest Paper</div>
                            <div style="font-size: 0.85rem; font-weight: 600; color: var(--blue-600);"><?= htmlspecialchars($sr['paper_title']) ?></div>
                        </div>
                    </div>
                </div>

                <div style="padding: 1rem;">
                    <?php if (empty($sr['students'])): ?>
                        <p class="text-secondary text-center py-8">No graded submissions yet.</p>
                    <?php else: ?>
                        <div class="rank-card-list">
                            <?php foreach ($sr['students'] as $index => $student): $rank = $index + 1; ?>
                                <div class="rank-row" data-rank="<?= $rank ?>">
                                    <div class="rank-number"><?= $rank ?></div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="font-weight: 600; font-size: 0.95rem;"><?= htmlspecialchars($student['username']) ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-tertiary);">ID: <?= htmlspecialchars($student['student_id']) ?></div>
                                    </div>
                                    <div class="rank-marks"><?= $student['marks'] ?>%</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($subject_rankings)): ?>
        <div class="card text-center py-20">
            <h3 class="mb-2">No Rankings Available</h3>
            <p class="text-secondary">Performance leaderboards will appear once papers are graded.</p>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
