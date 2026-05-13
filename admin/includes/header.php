<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/csrf.php';

// If not logged in or not admin/manager, redirect to main login
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("Location: ../login.php");
    exit;
}

$pdo = getDB();
$tenant_id = TenantContext::get()['id'];

// Get permissions if manager
$permissions = null;
if ($_SESSION['role'] === 'manager') {
    $pStmt = $pdo->prepare("SELECT * FROM manager_permissions WHERE user_id = ? AND tenant_id = ?");
    $pStmt->execute([$_SESSION['user_id'], $tenant_id]);
    $permissions = $pStmt->fetch();
}

function hasPermission($perm) {
    global $permissions;
    if ($_SESSION['role'] === 'admin') return true;
    if (!$permissions) return false;
    
    switch($perm) {
        case 'attendance': return $permissions['can_manage_attendance'];
        case 'students': return $permissions['can_manage_students'];
        case 'payments': return $permissions['can_manage_payments'];
        case 'scheduling': return $permissions['can_manage_scheduling'];
        case 'settings': return false; // Only admin can access settings
        default: return false;
    }
}
$current_page = basename($_SERVER['PHP_SELF']);

// Get all settings
$settings = [];
$sStmt = $pdo->query("SELECT * FROM settings");
while ($row = $sStmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$site_name = $settings['site_name'] ?? 'Elms Admin';
$enable_papers = ($settings['enable_papers'] ?? '1') == '1';

// Get unread admin notifications count
$nStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$nStmt->execute([$_SESSION['user_id']]);
$notif_count = $nStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - <?= htmlspecialchars($site_name) ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="<?= SITE_ROOT ?>css/style.css?v=<?= filemtime(__DIR__ . '/../../css/style.css') ?>">
    <link rel="stylesheet" href="<?= SITE_ROOT ?>css/admin.css?v=<?= filemtime(__DIR__ . '/../../css/admin.css') ?>">

    <script>
        const savedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        if (savedTheme === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
    </script>
</head>
<body>

<div id="uploadOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:99999; flex-direction:column; align-items:center; justify-content:center; color:#fff; backdrop-filter:blur(10px);">
    <div style="width: 50px; height: 50px; border: 4px solid rgba(255,255,255,0.1); border-top-color: var(--blue-500); border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 1.5rem;"></div>
    <h3 style="margin:0; font-weight:700; letter-spacing:1px;">UPLOADING...</h3>
    <p style="margin-top:0.5rem; opacity:0.7; font-size:0.9rem;">Please wait while we process your files.</p>
    <style>@keyframes spin { to { transform: rotate(360deg); } }</style>
</div>

<div class="layout-container">
    
    <!-- Sidebar Overlay (mobile) -->
    <div id="sidebarOverlay" class="sidebar-overlay" onclick="closeSidebar()"></div>

    <!-- Admin Sidebar -->
    <aside id="sidebar" class="app-sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-brand-icon admin">A</div>
            <div>
                <div class="sidebar-brand-name">Admin</div>
                <div class="sidebar-brand-sub"><?= htmlspecialchars($site_name) ?></div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="sidebar-section-label">Dashboard</div>
            
            <a href="index.php" class="sidebar-nav-link <?= $current_page==='index.php' ? 'active' : '' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                <span>Overview</span>
                <?php if ($notif_count > 0): ?>
                    <span class="nav-badge"><?= $notif_count ?></span>
                <?php endif; ?>
            </a>

            <?php if(hasPermission('attendance')): ?>
            <a href="attendance.php" class="sidebar-nav-link <?= $current_page==='attendance.php' ? 'active' : '' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
                <span>Attendance</span>
            </a>
            <?php endif; ?>

            <?php if(hasPermission('students')): ?>
            <a href="students.php" class="sidebar-nav-link <?= $current_page==='students.php' ? 'active' : '' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                <span>Students</span>
            </a>
            <?php endif; ?>

            <div class="sidebar-section-label">Curriculum</div>
            
            <?php if(hasPermission('scheduling')): ?>
            <a href="calendar.php" class="sidebar-nav-link <?= $current_page==='calendar.php' ? 'active' : '' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                <span>Live Classes</span>
            </a>
            <?php endif; ?>

            <?php if($enable_papers): ?>
            <a href="papers.php" class="sidebar-nav-link <?= $current_page==='papers.php' ? 'active' : '' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                <span>Papers</span>
            </a>
            <?php endif; ?>

            <div class="sidebar-section-label">Finance & System</div>
            
            <?php if(hasPermission('payments')): ?>
            <a href="payments.php" class="sidebar-nav-link <?= $current_page==='payments.php' ? 'active' : '' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                <span>Payments</span>
            </a>
            <?php endif; ?>

            <?php if($_SESSION['role'] === 'admin'): ?>
            <a href="settings.php" class="sidebar-nav-link <?= $current_page==='settings.php' ? 'active' : '' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path></svg>
                <span>Settings</span>
            </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <a href="../logout.php" class="sidebar-nav-link" style="color: var(--red-500);">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-wrapper">
        
        <header class="app-topbar">
            <div class="topbar-left">
                <button class="mobile-toggle" onclick="toggleSidebar()">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path></svg>
                </button>
                <span class="topbar-title">
                    <?php
                    switch($current_page) {
                        case 'index.php': echo 'Dashboard'; break;
                        case 'attendance.php': echo 'Attendance'; break;
                        case 'students.php': echo 'Students'; break;
                        case 'calendar.php': echo 'Schedule'; break;
                        case 'payments.php': echo 'Payments'; break;
                        case 'settings.php': echo 'Settings'; break;
                        case 'papers.php': echo 'Papers'; break;
                        case 'mark_paper.php': echo 'Mark Paper'; break;
                        default: echo 'Admin';
                    }
                    ?>
                </span>
            </div>

            <div class="topbar-right">
                <a href="../index.php" class="topbar-link">View Site</a>
                <div class="topbar-sep"></div>
                <div class="topbar-user">
                    <div class="topbar-user-avatar"><?= strtoupper(substr($_SESSION['username'],0,1)) ?></div>
                    <span class="topbar-user-name"><?= htmlspecialchars($_SESSION['username']) ?></span>
                </div>
            </div>
        </header>

        <div class="content-scroll">
            <div class="content-container">

<script>
    function updateThemeIcon() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const lightIcon = document.getElementById('theme-icon-light');
        const darkIcon = document.getElementById('theme-icon-dark');
        if(lightIcon && darkIcon) {
            lightIcon.style.display = isDark ? 'block' : 'none';
            darkIcon.style.display = isDark ? 'none' : 'block';
        }
    }
    function toggleTheme() {
        const currentTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', currentTheme);
        localStorage.setItem('theme', currentTheme);
        updateThemeIcon();
    }
    updateThemeIcon();

    // Global Upload Handler
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (form.getAttribute('enctype') === 'multipart/form-data') {
            const fileInputs = form.querySelectorAll('input[type="file"]');
            let hasFiles = false;
            let oversized = false;
            const maxSize = 50 * 1024 * 1024; // 50MB Limit

            fileInputs.forEach(input => {
                if (input.files && input.files[0]) {
                    hasFiles = true;
                    if (input.files[0].size > maxSize) oversized = true;
                }
            });

            if (oversized) {
                e.preventDefault();
                alert("File is too large. Maximum allowed size is 50MB.");
                return;
            }

            if (hasFiles) {
                document.getElementById('uploadOverlay').style.display = 'flex';
            }
        }
    });

    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('open');
    }
    function closeSidebar() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('open');
    }
</script>
