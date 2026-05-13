<?php
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['id'])) {
    header("Location: dashboard.php");
    exit;
}

$pdo = getDB();
$subject_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

// Check access
$sStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'grace_period_days'");
$sStmt->execute();
$grace_period_days = (int)($sStmt->fetchColumn() ?: 5);

$pStmt = $pdo->prepare("SELECT status, created_at FROM payments WHERE user_id = ? AND subject_id = ? ORDER BY id DESC");
$pStmt->execute([$user_id, $subject_id]);

$curr_month = (int)date('n');
$curr_year = (int)date('Y');
$prev_month = $curr_month === 1 ? 12 : $curr_month - 1;
$prev_year = $curr_month === 1 ? $curr_year - 1 : $curr_year;

$this_month_approved = false;
$last_month_approved = false;

while ($row = $pStmt->fetch()) {
    $time = strtotime($row['created_at']);
    $p_month = (int)date('n', $time);
    $p_year = (int)date('Y', $time);
    
    if ($p_month === $curr_month && $p_year === $curr_year && $row['status'] === 'approved') {
        $this_month_approved = true;
        break;
    } elseif ($p_month === $prev_month && $p_year === $prev_year && $row['status'] === 'approved') {
        $last_month_approved = true;
    }
}

$current_day = (int)date('j');
$in_grace_period = $current_day <= $grace_period_days;

if (!$this_month_approved && !($in_grace_period && $last_month_approved)) {
    header("Location: payment.php?subject_id=" . $subject_id);
    exit;
}

// Get subject details
$stmt = $pdo->prepare("SELECT s.id, s.name, s.description, g.name as grade_name FROM subjects s JOIN grades g ON s.grade_id = g.id WHERE s.id = ?");
$stmt->execute([$subject_id]);
$subject = $stmt->fetch();

require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="<?= SITE_ROOT ?>css/learning.css?v=<?= filemtime(__DIR__ . '/css/learning.css') ?>">


<main class="container py-8">
    
    <div class="mb-8">
        <a href="dashboard.php" class="btn btn-ghost btn-sm mb-4" style="padding-left: 0;">
            <svg class="icon icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
              <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12,19 5,12 12,5"/>
            </svg>
            Back to Dashboard
        </a>
        
        <div class="flex-between">
            <div>
                <div style="font-size: 11px; font-weight: 700; color: var(--blue-600); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;"><?= htmlspecialchars($subject['grade_name']) ?></div>
                <h1><?= htmlspecialchars($subject['name']) ?></h1>
            </div>
            <div class="badge badge-green">Access Granted</div>
        </div>
    </div>

    <!-- Tab Switcher -->
    <div class="nav-tabs">
        <button class="nav-tab active" onclick="switchTab(event, 'classes')">Live Classes</button>
        <button class="nav-tab" onclick="switchTab(event, 'recordings')">Recordings</button>
    </div>

    <!-- Live Classes -->
    <div id="tab-classes" class="tab-content active">
        <?php
        $cStmt = $pdo->prepare("SELECT * FROM classes WHERE subject_id = ? AND start_time >= NOW() ORDER BY start_time ASC");
        $cStmt->execute([$subject_id]);
        $classes = $cStmt->fetchAll();
        ?>

        <?php if (empty($classes)): ?>
            <div class="card text-center py-12">
                <div class="icon-wrap icon-wrap-md mb-4" style="margin: 0 auto;">
                    <svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                      <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                </div>
                <h3 class="mb-2">No Live Classes Scheduled</h3>
                <p class="text-secondary">Please check back later for the next live session.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-2">
                <?php foreach ($classes as $cls): ?>
                    <div class="card class-card" data-start="<?= htmlspecialchars($cls['start_time']) ?>">
                        <?php if (!empty($cls['image'])): ?>
                            <img src="<?= htmlspecialchars($cls['image']) ?>" class="class-image" style="width: 100px; height: 100px; object-fit: cover; border-radius: var(--radius-lg); flex-shrink: 0;">
                        <?php else: ?>
                            <div class="class-image" style="width: 100px; height: 100px; background: var(--blue-50); border-radius: var(--radius-lg); flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: var(--blue-500);">
                                <svg class="icon icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                  <polygon points="23,7 16,12 23,17"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
                                </svg>
                            </div>
                        <?php endif; ?>
                        
                        <div style="flex: 1; min-width: 0; display: flex; flex-direction: column;">
                            <h4 style="font-size: 1.1rem; margin-bottom: 4px;"><?= htmlspecialchars($cls['title']) ?></h4>
                            <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1.25rem; display: flex; align-items: center; gap: 4px;">
                                <svg class="icon icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                  <circle cx="12" cy="12" r="10"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                                </svg>
                                <?= date('M j, Y • g:i A', strtotime($cls['start_time'])) ?>
                            </div>
                            
                            <div class="mt-auto flex gap-2">
                                <?php if ($cls['class_type'] !== 'physical' && !empty($cls['zoom_link'])): 
                                    $secret = defined('ELMS_HMAC_SECRET') ? ELMS_HMAC_SECRET : getenv('ELMS_HMAC_SECRET');
                                    $token = hash_hmac('sha256', $user_id . '_' . $cls['id'] . '_' . date('Y-m-d'), $secret);
                                ?>
                                    <a href="join_class.php?class_id=<?= $cls['id'] ?>&token=<?= $token ?>" target="_blank" class="btn btn-primary btn-sm flex-1 join-btn">Enter Online Class</a>
                                <?php else: ?>
                                    <div class="badge badge-gray flex-1" style="justify-content: center;">Physical Only</div>
                                <?php endif; ?>
                                
                                <?php if (!empty($cls['notes_pdf'])): ?>
                                    <a href="<?= htmlspecialchars($cls['notes_pdf']) ?>" download class="btn btn-secondary btn-sm btn-icon" title="Download Notes">
                                        <svg class="icon icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7,10 12,15 17,10"/><line x1="12" y1="15" x2="12" y2="3"/>
                                        </svg>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Recordings -->
    <div id="tab-recordings" class="tab-content">
        <?php
        $rStmt = $pdo->prepare("SELECT * FROM recordings WHERE subject_id = ? ORDER BY id DESC");
        $rStmt->execute([$subject_id]);
        $recordings = $rStmt->fetchAll();
        ?>

        <?php if (empty($recordings)): ?>
            <div class="card text-center py-12">
                <div class="icon-wrap icon-wrap-md mb-4" style="margin: 0 auto;">
                    <svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                      <polygon points="23,7 16,12 23,17"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
                    </svg>
                </div>
                <h3 class="mb-2">No Recordings Available</h3>
                <p class="text-secondary">Class recordings will appear here after the live sessions.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-3">
                <?php foreach ($recordings as $rec): ?>
                    <a href="watch_recording.php?id=<?= $rec['id'] ?>&subject_id=<?= $subject_id ?>" class="card" style="padding: 10px; display: flex; flex-direction: column; text-decoration: none; color: inherit;">
                        <div style="position: relative; border-radius: var(--radius-md); overflow: hidden; aspect-ratio: 16/9; background: #000; margin-bottom: 12px;">
                            <img src="https://img.youtube.com/vi/<?= htmlspecialchars($rec['youtube_id']) ?>/mqdefault.jpg" style="width: 100%; height: 100%; object-fit: cover; opacity: 0.8;" alt="">
                            <div style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;">
                                <div style="width: 44px; height: 44px; background: #fff; color: #000; border-radius: 50%; display: flex; align-items: center; justify-content: center; transform: scale(1); transition: transform 0.2s;">
                                    <svg class="icon icon-md" viewBox="0 0 24 24" fill="currentColor"><polygon points="5,3 19,12 5,21"/></svg>
                                </div>
                            </div>
                        </div>
                        <h4 style="font-size: 1rem; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($rec['title']) ?></h4>
                        <div style="font-size: 0.75rem; color: var(--text-tertiary);">Click to watch recording</div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</main>

<script>
function switchTab(e, id) {
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab-' + id).classList.add('active');
    e.currentTarget.classList.add('active');
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-start]').forEach(function(card) {
        var start = new Date(card.dataset.start.replace(/-/g, '/'));
        var now = new Date();
        var diff = (start - now) / 60000; // minutes
        if (diff >= -120 && diff <= 30) {
            var btn = card.querySelector('.join-btn');
            if (btn) {
                btn.classList.add('btn-pulse');
                btn.textContent = "🔴 Join Live Now";
            }
        }
    });
});
</script>

<?php require_once __DIR__ . '/includes/header.php'; ?>
