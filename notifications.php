<?php
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$u_id = $_SESSION['user_id'];
$pdo = getDB();

$showHistory = isset($_GET['history']);

if ($showHistory) {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
} else {
    // Note: 'is_cleared' might be a custom field in some versions, defaulting to simple read check if missing
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
}
$stmt->execute([$u_id]);
$allNotifs = $stmt->fetchAll();

// Mark as read when viewing
$pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$u_id]);

require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="<?= SITE_ROOT ?>css/dashboard.css?v=<?= filemtime(__DIR__ . '/css/dashboard.css') ?>">


<main class="container py-12">
    <div class="flex-between mb-10">
        <div>
            <h1><?= $showHistory ? 'Notification History' : 'Notifications' ?></h1>
            <p class="text-secondary"><?= $showHistory ? 'Viewing all past updates.' : 'Keep track of your latest academic updates.' ?></p>
        </div>
        <a href="?<?= $showHistory ? '' : 'history=1' ?>" class="btn btn-secondary">
            <?= $showHistory ? 'View Recent' : 'View History' ?>
        </a>
    </div>

    <?php if (empty($allNotifs)): ?>
        <div class="card text-center py-20">
            <div class="icon-wrap icon-wrap-lg mb-6" style="margin: 0 auto;">
                <svg class="icon icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/>
                </svg>
            </div>
            <h3 class="mb-2">No Notifications</h3>
            <p class="text-secondary">You're all caught up! New updates will appear here.</p>
        </div>
    <?php else: ?>
        <div class="flex flex-col gap-3">
            <?php foreach ($allNotifs as $n): 
                $isAnnouncement = ($n['type'] ?? '') === 'announcement';
                $isRead = (bool)$n['is_read'];
            ?>
                <div class="notif-row <?= !$isRead ? 'unread' : '' ?>">
                    <div class="notif-icon">
                        <?php if ($isAnnouncement): ?>
                            <svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                              <path d="M11 5L6 9H2V15H6L11 19V5Z"/><path d="M15.54 8.46C16.4774 9.39764 17.004 10.6692 17.004 11.995C17.004 13.3208 16.4774 14.5924 15.54 15.53"/><path d="M19.07 4.93C20.9447 6.80528 21.9979 9.34836 21.9979 12C21.9979 14.6516 20.9447 17.1947 19.07 19.07"/>
                            </svg>
                        <?php else: ?>
                            <svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                              <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                        <?php endif; ?>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div class="flex-between mb-1">
                            <span style="font-weight: 700; font-size: 1.05rem;"><?= $isAnnouncement ? 'Announcement' : 'Update' ?></span>
                            <?php if (!$isRead): ?><div class="notif-dot"></div><?php endif; ?>
                        </div>
                        <p class="text-secondary" style="font-size: 0.95rem; line-height: 1.5; margin-bottom: 0.5rem;"><?= htmlspecialchars($n['message']) ?></p>
                        <div style="font-size: 0.75rem; color: var(--text-tertiary); font-weight: 500;">
                            <?= date('M j, Y • g:i A', strtotime($n['created_at'])) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
