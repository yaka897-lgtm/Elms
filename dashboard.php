<?php
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$pdo = getDB();
$user_id = $_SESSION['user_id'];
$grade_id = $_SESSION['grade_id'];

// PERF-4: Specific columns only
$uStmt = $pdo->prepare("SELECT u.id, u.username, u.role, u.grade_id, u.student_id, u.photo, u.phone, u.school, g.name as grade_name FROM users u LEFT JOIN grades g ON u.grade_id = g.id WHERE u.id = ?");
$uStmt->execute([$user_id]);
$user = $uStmt->fetch();

if (!$user) {
    die("Error: Student profile not found. Please log in again.");
}

// PERF-1: Atomic student_id generation
if (empty($user['student_id'])) {
    $assigned = false;
    for ($i = 0; $i < 5; $i++) {
        $new_id = str_pad(random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
        try {
            $pdo->prepare("UPDATE users SET student_id = ? WHERE id = ? AND student_id IS NULL")
                ->execute([$new_id, $user_id]);
            $assigned = true;
            $user['student_id'] = $new_id;
            break;
        } catch (PDOException $e) {}
    }
}

// PERF-3: Selective settings query
$needed_keys = ['site_name', 'grace_period_days', 'enable_papers'];
$placeholders = implode(',', array_fill(0, count($needed_keys), '?'));
$sStmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
$sStmt->execute($needed_keys);
$settings = [];
while ($row = $sStmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$site_name = $settings['site_name'] ?? 'Elms';
$grace_period_days = (int)($settings['grace_period_days'] ?? 5);
$enable_papers = ($settings['enable_papers'] ?? '1') == '1';

// Mark notifications
if (isset($_GET['mark_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$user_id]);
    header("Location: dashboard.php");
    exit;
}

// Get unread notifications
$nStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC");
$nStmt->execute([$user_id]);
$notifications = $nStmt->fetchAll();

// Get all subjects
$sStmt = $pdo->prepare("SELECT id, name, description, price FROM subjects WHERE grade_id = ?");
$sStmt->execute([$grade_id]);
$subjects = $sStmt->fetchAll();

// Determine Payment Status
$pStmt = $pdo->prepare("SELECT subject_id, status, created_at FROM payments WHERE user_id = ? ORDER BY id DESC");
$pStmt->execute([$user_id]);
$payment_data = [];

$curr_month = (int)date('n');
$curr_year = (int)date('Y');
$prev_month = $curr_month === 1 ? 12 : $curr_month - 1;
$prev_year = $curr_month === 1 ? $curr_year - 1 : $curr_year;

while ($row = $pStmt->fetch()) {
    $sid = $row['subject_id'];
    $time = strtotime($row['created_at']);
    $p_month = (int)date('n', $time);
    $p_year = (int)date('Y', $time);
    
    if (!isset($payment_data[$sid])) {
        $payment_data[$sid] = ['this_month' => null, 'last_month_approved' => false];
    }
    
    if ($p_month === $curr_month && $p_year === $curr_year) {
        if ($payment_data[$sid]['this_month'] !== 'approved') {
            $payment_data[$sid]['this_month'] = $row['status'];
        }
    } elseif ($p_month === $prev_month && $p_year === $prev_year && $row['status'] === 'approved') {
        $payment_data[$sid]['last_month_approved'] = true;
    }
}

$current_day = (int)date('j');
$in_grace_period = $current_day <= $grace_period_days;

// Performance Data
$statsStmt = $pdo->prepare("
    SELECT p.title, ps.mcq_score, ps.essay_marks, ps.total_marks, ps.submitted_at 
    FROM paper_submissions ps
    JOIN papers p ON ps.paper_id = p.id
    WHERE ps.user_id = ? AND (ps.mcq_score IS NOT NULL OR ps.essay_marks IS NOT NULL)
    ORDER BY ps.submitted_at ASC
    LIMIT 10
");
$statsStmt->execute([$user_id]);
$performance_data = $statsStmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="<?= SITE_ROOT ?>css/dashboard.css?v=<?= filemtime(__DIR__ . '/css/dashboard.css') ?>">


<script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>

<main class="container py-8">
    
    <div class="grid grid-cols-2 mb-8">
        <!-- ID Card -->
        <div class="card nic-card">
            <div style="position: absolute; inset: 0; background: radial-gradient(at 0% 0%, hsla(217, 91%, 60%, 0.1) 0, transparent 50%);"></div>
            <div style="position: relative; z-index: 2; padding: 24px; height: 100%; display: flex; flex-direction: column;">
                <div class="flex-between mb-6">
                    <div>
                        <div style="font-weight: 700; font-size: 1.1rem; letter-spacing: -0.01em;"><?= strtoupper(htmlspecialchars($site_name)) ?></div>
                        <div style="font-size: 10px; color: var(--gray-400); font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em;">Student Pass</div>
                    </div>
                    <div id="qrcode" onclick="toggleQR()" style="background: #fff; padding: 4px; border-radius: var(--radius-sm); cursor: pointer;"></div>
                </div>

                <div style="display: flex; gap: 1.25rem; margin-bottom: 1.5rem;">
                    <?php if (!empty($user['photo'])): ?>
                        <img src="<?= htmlspecialchars($user['photo']) ?>" alt="Student" style="width: 70px; height: 85px; border-radius: var(--radius-md); object-fit: cover; background: var(--gray-800);">
                    <?php else: ?>
                        <div style="width: 70px; height: 85px; border-radius: var(--radius-md); background: var(--gray-800); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 700; color: var(--gray-600);">
                            <?= strtoupper(substr($user['username'], 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    
                    <div style="flex: 1;">
                        <h3 style="color: #fff; font-size: 1.25rem; margin-bottom: 2px;"><?= htmlspecialchars($user['username']) ?></h3>
                        <div style="color: var(--blue-400); font-weight: 600; font-size: 0.85rem;"><?= htmlspecialchars($user['grade_name']) ?></div>
                        
                        <div style="margin-top: 0.75rem; font-size: 0.75rem; color: var(--gray-400);">
                            <div style="display: flex; gap: 0.5rem; margin-bottom: 2px;">
                                <span style="width: 45px; flex-shrink: 0;">School</span>
                                <span style="color: var(--gray-200);"><?= htmlspecialchars($user['school'] ?? 'Not set') ?></span>
                            </div>
                            <div style="display: flex; gap: 0.5rem;">
                                <span style="width: 45px; flex-shrink: 0;">Phone</span>
                                <span style="color: var(--gray-200);"><?= htmlspecialchars($user['phone'] ?? 'Not set') ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-top: auto;">
                    <div style="font-size: 9px; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 700; margin-bottom: 2px;">Identification Number</div>
                    <div style="font-family: var(--font-mono); font-size: 1.5rem; letter-spacing: 3px; font-weight: 700; color: #fff;"><?= htmlspecialchars($user['student_id']) ?></div>
                </div>
            </div>
        </div>

        <!-- Performance -->
        <div class="card">
            <div class="flex-between mb-4">
                <h4 style="margin: 0;">Performance Analytics</h4>
                <span class="badge badge-blue">Last 10 Papers</span>
            </div>
            <div style="height: 180px;">
                <canvas id="performanceChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Notifications -->
    <?php if (count($notifications) > 0): ?>
        <div class="alert alert-info mb-8" style="justify-content: space-between;">
            <div class="flex gap-3">
                <svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                </svg>
                <div>
                    <div style="font-weight: 600;">You have <?= count($notifications) ?> unread notifications</div>
                    <div style="font-size: 0.85rem; opacity: 0.9;"><?= htmlspecialchars($notifications[0]['message']) ?></div>
                </div>
            </div>
            <a href="?mark_read=1" class="btn btn-secondary btn-sm">Clear All</a>
        </div>
    <?php endif; ?>

    <!-- New Papers (PERF-8: Join logic) -->
    <?php if ($enable_papers): 
        $newPapersStmt = $pdo->prepare("
            SELECT p.*, s.name as subject_name
            FROM papers p
            JOIN subjects s ON p.subject_id = s.id
            JOIN payments pay ON p.subject_id = pay.subject_id
                AND pay.user_id = ?
                AND pay.status = 'approved'
            LEFT JOIN paper_submissions ps ON p.id = ps.paper_id AND ps.user_id = ?
            WHERE ps.id IS NULL
              AND p.deadline > NOW()
            GROUP BY p.id
            ORDER BY p.created_at DESC
            LIMIT 3
        ");
        $newPapersStmt->execute([$user_id, $user_id]);
        $newPapers = $newPapersStmt->fetchAll();
    ?>
        <?php if (!empty($newPapers)): ?>
            <div class="mb-8">
                <div class="flex-between mb-4">
                    <h3 style="font-size: 1.1rem;">New Papers Available</h3>
                    <a href="papers.php" class="btn btn-ghost btn-sm">View all</a>
                </div>
                <div class="grid grid-cols-3">
                    <?php foreach ($newPapers as $np): ?>
                        <div class="card" style="border-left: 3px solid var(--blue-500);">
                            <div style="font-size: 10px; color: var(--blue-600); font-weight: 700; text-transform: uppercase; margin-bottom: 4px;"><?= htmlspecialchars($np['subject_name']) ?></div>
                            <h4 style="font-size: 0.95rem; margin-bottom: 8px;"><?= htmlspecialchars($np['title']) ?></h4>
                            <div style="display: flex; align-items: center; gap: 4px; font-size: 0.8rem; color: var(--text-tertiary);">
                                <svg class="icon icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                  <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                                Due <?= date('M j', strtotime($np['deadline'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Subjects -->
    <div>
        <h3 class="mb-4" style="font-size: 1.1rem;">My Learning Path</h3>
        <div class="grid grid-cols-3">
            <?php foreach ($subjects as $subject): 
                $sid = $subject['id'];
                $p_info = $payment_data[$sid] ?? ['this_month' => null, 'last_month_approved' => false];
                $status_ui = 'unpaid'; $can_access = false;

                if ($p_info['this_month'] === 'approved') { $status_ui = 'paid'; $can_access = true; }
                elseif ($p_info['this_month'] === 'pending') { $status_ui = 'pending'; if ($in_grace_period && $p_info['last_month_approved']) $can_access = true; }
                elseif ($p_info['this_month'] === 'rejected') { $status_ui = 'rejected'; if ($in_grace_period && $p_info['last_month_approved']) $can_access = true; }
                else { if ($in_grace_period && $p_info['last_month_approved']) { $status_ui = 'grace'; $can_access = true; } }
            ?>
                <div class="card subject-card">
                    <h4 style="margin-bottom: 0.5rem;"><?= htmlspecialchars($subject['name']) ?></h4>
                    <p class="text-secondary mb-6" style="font-size: 0.85rem; min-height: 2.5rem;"><?= htmlspecialchars(substr($subject['description'], 0, 90)) ?>...</p>
                    
                    <div style="margin-top: auto;">
                        <?php if ($status_ui === 'paid'): ?>
                            <span class="badge badge-green mb-4" style="width: 100%;">Paid · <?= date('F') ?></span>
                            <a href="subject.php?id=<?= $sid ?>" class="btn btn-primary btn-block">Enter Class</a>
                        <?php elseif ($status_ui === 'grace'): ?>
                            <span class="badge badge-yellow mb-4" style="width: 100%;">Grace Period</span>
                            <a href="subject.php?id=<?= $sid ?>" class="btn btn-primary btn-block mb-2">Enter Class</a>
                            <a href="payment.php?subject_id=<?= $sid ?>" class="btn btn-secondary btn-block">Pay Now</a>
                        <?php elseif ($status_ui === 'pending'): ?>
                            <span class="badge badge-blue mb-4" style="width: 100%;">Verification Pending</span>
                            <?php if ($can_access): ?>
                                <a href="subject.php?id=<?= $sid ?>" class="btn btn-primary btn-block">Enter Class</a>
                            <?php else: ?>
                                <button disabled class="btn btn-secondary btn-block">Awaiting Approval</button>
                            <?php endif; ?>
                        <?php elseif ($status_ui === 'rejected'): ?>
                            <span class="badge badge-red mb-4" style="width: 100%;">Rejected</span>
                            <a href="payment.php?subject_id=<?= $sid ?>" class="btn btn-danger btn-block">Try Again</a>
                        <?php else: ?>
                            <div style="font-weight: 700; margin-bottom: 12px; font-size: 1rem;">LKR <?= number_format($subject['price'], 2) ?></div>
                            <a href="payment.php?subject_id=<?= $sid ?>" class="btn btn-secondary btn-block">Enroll Now</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>

<div id="qrModal" onclick="toggleQR()" style="position: fixed; inset: 0; background: rgba(17,17,24,0.95); z-index: 1000; display: none; align-items: center; justify-content: center; backdrop-filter: blur(8px); cursor: pointer;">
    <div class="card" style="padding: 2.5rem; text-align: center; border: none; background: #fff;">
        <div id="qrcode_large"></div>
        <p style="color: var(--gray-900); font-weight: 700; font-size: 1.1rem; margin: 1.5rem 0 0.25rem;">STUDENT PASS</p>
        <p style="color: var(--gray-500); font-size: 0.85rem; margin: 0;">Scan to verify attendance</p>
    </div>
</div>

<script>
function toggleQR() {
    var m = document.getElementById('qrModal');
    m.style.display = (m.style.display === 'flex') ? 'none' : 'flex';
}

document.addEventListener('DOMContentLoaded', function() {
    var sid = "<?= htmlspecialchars($user['student_id']) ?>";
    new QRCode(document.getElementById("qrcode"), { text: sid, width: 44, height: 44, colorDark: "#000", colorLight: "#fff", correctLevel: QRCode.CorrectLevel.H });
    new QRCode(document.getElementById("qrcode_large"), { text: sid, width: 220, height: 220, colorDark: "#000", colorLight: "#fff", correctLevel: QRCode.CorrectLevel.H });

    var ctx = document.getElementById('performanceChart').getContext('2d');
    var data = <?= json_encode($performance_data) ?>;
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.map(function(d) { return d.title.substring(0, 8); }),
            datasets: [{
                data: data.map(function(d) { return d.total_marks; }),
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.08)',
                borderWidth: 2,
                tension: 0.4,
                fill: true,
                pointRadius: 3,
                pointBackgroundColor: '#3b82f6'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.03)' }, ticks: { font: { size: 10 } } },
                x: { grid: { display: false }, ticks: { font: { size: 10 } } }
            }
        }
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
