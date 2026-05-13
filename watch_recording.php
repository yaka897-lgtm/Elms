<?php
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['id']) || !isset($_GET['subject_id'])) {
    header("Location: dashboard.php");
    exit;
}

$pdo = getDB();
$rec_id     = (int)$_GET['id'];
$subject_id = (int)$_GET['subject_id'];
$user_id    = $_SESSION['user_id'];

// Verify access
$sStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'grace_period_days'");
$sStmt->execute();
$grace_period_days = (int)($sStmt->fetchColumn() ?: 5);

$pStmt = $pdo->prepare("SELECT status, created_at FROM payments WHERE user_id = ? AND subject_id = ? ORDER BY id DESC");
$pStmt->execute([$user_id, $subject_id]);

$curr_month = (int)date('n');
$curr_year  = (int)date('Y');
$prev_month = $curr_month === 1 ? 12 : $curr_month - 1;
$prev_year  = $curr_month === 1 ? $curr_year - 1 : $curr_year;

$this_month_approved = false;
$last_month_approved = false;

while ($row = $pStmt->fetch()) {
    $time = strtotime($row['created_at']);
    $p_month = (int)date('n', $time);
    $p_year = (int)date('Y', $time);
    if ($p_month === $curr_month && $p_year === $curr_year && $row['status'] === 'approved') {
        $this_month_approved = true; break;
    } elseif ($p_month === $prev_month && $p_year === $prev_year && $row['status'] === 'approved') {
        $last_month_approved = true;
    }
}

if (!$this_month_approved && !((int)date('j') <= $grace_period_days && $last_month_approved)) {
    header("Location: payment.php?subject_id=" . $subject_id);
    exit;
}

// Get recording
$stmt = $pdo->prepare("SELECT r.*, s.name as subject_name FROM recordings r JOIN subjects s ON r.subject_id = s.id WHERE r.id = ? AND r.subject_id = ?");
$stmt->execute([$rec_id, $subject_id]);
$recording = $stmt->fetch();

if (!$recording) {
    header("Location: subject.php?id=" . $subject_id);
    exit;
}

// Get sidebar list
$allStmt = $pdo->prepare("SELECT id, title, youtube_id FROM recordings WHERE subject_id = ? ORDER BY id DESC");
$allStmt->execute([$subject_id]);
$all_recordings = $allStmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="<?= SITE_ROOT ?>css/learning.css?v=<?= filemtime(__DIR__ . '/css/learning.css') ?>">


<main class="container py-8">
    <div class="mb-6">
        <a href="subject.php?id=<?= $subject_id ?>" class="btn btn-ghost btn-sm" style="padding-left: 0;">
            <svg class="icon icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
              <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12,19 5,12 12,5"/>
            </svg>
            Back to <?= htmlspecialchars($recording['subject_name']) ?>
        </a>
    </div>

    <div class="grid watch-grid" style="grid-template-columns: 1fr 320px; gap: 2.5rem; align-items: start;">
        <!-- Player -->
        <div>
            <div class="video-container">
                <iframe src="https://www.youtube.com/embed/<?= htmlspecialchars($recording['youtube_id']) ?>?autoplay=1&rel=0&modestbranding=1" allowfullscreen></iframe>
            </div>
            
            <div class="card mb-6">
                <div class="badge badge-blue mb-2"><?= htmlspecialchars($recording['subject_name']) ?></div>
                <h1 style="font-size: 1.75rem;"><?= htmlspecialchars($recording['title']) ?></h1>
            </div>

            <?php if (!empty($recording['notes_pdf'])): ?>
                <div class="notes-card">
                    <div class="icon-wrap">
                        <svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7,10 12,15 17,10"/><line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                    </div>
                    <div style="flex: 1;">
                        <div style="font-weight: 700; color: var(--text-primary); font-size: 0.95rem;">Study Materials Available</div>
                        <div style="font-size: 0.82rem; color: var(--text-secondary);">Download the companion notes for this session.</div>
                    </div>
                    <a href="<?= htmlspecialchars($recording['notes_pdf']) ?>" download class="btn btn-primary btn-sm">Download PDF</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <aside>
            <h4 style="font-size: 0.7rem; font-weight: 800; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 1rem;">Course Recordings</h4>
            <div class="flex flex-col gap-1">
                <?php foreach ($all_recordings as $r): $isActive = $r['id'] == $rec_id; ?>
                    <a href="watch_recording.php?id=<?= $r['id'] ?>&subject_id=<?= $subject_id ?>" class="rec-sidebar-item <?= $isActive ? 'active' : '' ?>">
                        <div class="rec-thumb">
                            <img src="https://img.youtube.com/vi/<?= htmlspecialchars($r['youtube_id']) ?>/mqdefault.jpg">
                            <?php if ($isActive): ?><div class="rec-playing-tag">Playing</div><?php endif; ?>
                        </div>
                        <div style="flex: 1; min-width: 0; display: flex; align-items: center;">
                            <div style="font-size: 0.85rem; font-weight: 600; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; <?= $isActive ? 'color: var(--blue-600);' : '' ?>">
                                <?= htmlspecialchars($r['title']) ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
