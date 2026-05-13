<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/csrf.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$tenant_id = TenantContext::get()['id'];
$pdo = getDB();

$user_id = $_SESSION['user_id'];
$class_id = $_GET['class_id'] ?? null;
$token = $_GET['token'] ?? null;

if (!$class_id || !$token) {
    die("Invalid request parameters.");
}

// 1. Validate Token (SEC-7)
$secret = defined('ELMS_HMAC_SECRET') ? ELMS_HMAC_SECRET : getenv('ELMS_HMAC_SECRET');
if (!$secret) {
    throw new RuntimeException('ELMS_HMAC_SECRET is not configured.');
}
$expected_token = hash_hmac('sha256', $user_id . '_' . $class_id . '_' . date('Y-m-d'), $secret);
if (!hash_equals($expected_token, $token)) {
    die("Invalid or expired join token. Please go back to the dashboard and try again.");
}

try {
    // 2. Fetch Class Details
    $stmt = $pdo->prepare("SELECT subject_id, zoom_link, class_type, start_time FROM classes WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$class_id, $tenant_id]);
    $class = $stmt->fetch();

    if (!$class || empty($class['zoom_link']) || $class['class_type'] === 'physical') {
        die("This class is not available for online joining.");
    }

    $subject_id = $class['subject_id'];

    // 3. Verify Payment Status
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
            $this_month_approved = true; break;
        } elseif ($p_month === $prev_month && $p_year === $prev_year && $row['status'] === 'approved') {
            $last_month_approved = true;
        }
    }

    if (!$this_month_approved && !((int)date('j') <= $grace_period_days && $last_month_approved)) {
        header("Location: payment.php?subject_id=" . $subject_id);
        exit;
    }

    // 4. Mark Attendance & Redirect
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_join'])) {
        csrf_verify();
        $aStmt = $pdo->prepare("INSERT IGNORE INTO attendance (tenant_id, class_id, user_id, method) VALUES (?, ?, ?, 'link_click')");
        $aStmt->execute([$tenant_id, $class_id, $user_id]);
        header("Location: " . $class['zoom_link']);
        exit;
    }

    $subjectStmt = $pdo->prepare("SELECT name FROM subjects WHERE id = ?");
    $subjectStmt->execute([$subject_id]);
    $subject_name = $subjectStmt->fetchColumn();

} catch (Exception $e) {
    die("An error occurred while processing your request.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Live Class - <?= htmlspecialchars($subject_name) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
    <link rel="stylesheet" href="css/learning.css?v=<?= filemtime(__DIR__ . '/css/learning.css') ?>">

</head>
<body class="join-card-container">

<div class="card join-card">
    <div class="icon-wrap icon-wrap-lg mb-6" style="margin: 0 auto; background: var(--blue-50); color: var(--blue-600);">
        <svg class="icon icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
          <polygon points="23,7 16,12 23,17"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
        </svg>
    </div>
    
    <h1 style="font-size: 1.75rem; margin-bottom: 0.5rem;">Live Class Entry</h1>
    <p class="text-secondary mb-10">Attendance will be recorded automatically when you join the virtual classroom.</p>
    
    <div class="info-box">
        <div class="mb-6">
            <div class="info-label">Subject</div>
            <div class="info-value"><?= htmlspecialchars($subject_name) ?></div>
        </div>
        
        <div>
            <div class="info-label">Scheduled Time</div>
            <div class="info-value" style="color: var(--blue-600);"><?= date('F j, Y • g:i A', strtotime($class['start_time'])) ?></div>
        </div>
    </div>
    
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="confirm_join" value="1">
        <div class="flex gap-3">
            <a href="subject.php?id=<?= $subject_id ?>" class="btn btn-secondary flex-1">Go Back</a>
            <button type="submit" class="btn btn-primary flex-1">
                Enter Class
            </button>
        </div>
    </form>
</div>

</body>
</html>
