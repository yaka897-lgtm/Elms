<?php
require_once __DIR__ . '/../core/DatabaseManager.php';
require_once __DIR__ . '/SuperAdminHelper.php';

session_start();
if (!isset($_SESSION['superadmin_id'])) {
    header("Location: login.php");
    exit;
}

$pdo = DatabaseManager::getPlatformDB();

// Search logic
$search = $_GET['search'] ?? '';
$query = "SELECT * FROM tenants";
$params = [];
if ($search) {
    $query .= " WHERE name LIKE ? OR subdomain LIKE ? OR custom_domain LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%"];
}
$query .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$tenants = $stmt->fetchAll();

// Platform Wide Stats
$totalTenants = count($tenants);
$totalStudents = 0;
$totalStorage = 0;
$activeTenants = 0;

foreach ($tenants as $key => $t) {
    $tenants[$key]['stats'] = SuperAdminHelper::getTenantStats($t);
    $totalStudents += $tenants[$key]['stats']['users'];
    $totalStorage += $tenants[$key]['stats']['storage'];
    if ($t['status'] === 'active') $activeTenants++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform Command Center</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=<?= filemtime(__DIR__ . '/../css/style.css') ?>">
    <style>
        :root {
            --admin-bg: #f8fafc;
            --admin-sidebar: #1e293b;
            --admin-primary: #4f46e5;
            --admin-primary-hover: #4338ca;
            --admin-text: #0f172a;
            --admin-text-secondary: #64748b;
            --admin-border: #e2e8f0;
            --admin-card: #ffffff;
        }
        body { 
            background: var(--admin-bg); 
            color: var(--admin-text); 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, "Apple Color Emoji", Arial, sans-serif, "Segoe UI Emoji", "Segoe UI Symbol";
            padding: 0;
            margin: 0;
        }
        .layout { display: flex; min-height: 100vh; }
        .sidebar { width: 240px; background: var(--admin-sidebar); border-right: none; padding: 2rem 1rem; position: sticky; top: 0; height: 100vh; color: white; }
        .main-content { flex: 1; padding: 3rem 4rem; max-width: 1400px; margin: 0 auto; width: 100%; }
        
        .nav-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.7rem 1rem; color: #94a3b8; text-decoration: none; border-radius: 8px; font-size: 0.9rem; font-weight: 500; transition: all 0.2s; margin-bottom: 0.5rem; }
        .nav-item:hover { background: rgba(255, 255, 255, 0.05); color: white; }
        .nav-item.active { background: var(--admin-primary); color: white; font-weight: 600; }

        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 3rem; }
        .stat-card { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid var(--admin-border); }
        .stat-card .label { font-size: 0.75rem; font-weight: 700; color: var(--admin-text-secondary); margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-card .value { font-size: 1.75rem; font-weight: 800; color: var(--admin-text); }

        .search-bar { position: relative; margin-bottom: 2.5rem; }
        .search-bar input { width: 100%; padding: 0.8rem 1rem 0.8rem 2.8rem; border-radius: 10px; border: 1px solid var(--admin-border); font-family: inherit; font-size: 0.95rem; background: white; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .search-bar svg { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--admin-text-secondary); width: 18px; }

        .tenant-table { width: 100%; border-collapse: separate; border-spacing: 0; background: white; border-radius: 12px; border: 1px solid var(--admin-border); overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .tenant-table th { padding: 1rem; text-align: left; font-size: 0.75rem; font-weight: 700; color: var(--admin-text-secondary); text-transform: uppercase; background: #f8fafc; border-bottom: 1px solid var(--admin-border); }
        .tenant-table td { padding: 1.25rem 1rem; border-bottom: 1px solid var(--admin-border); font-size: 0.95rem; }
        .tenant-table tr:last-child td { border-bottom: none; }
        
        .tenant-info .name { font-weight: 600; color: var(--admin-text); display: block; margin-bottom: 0.1rem; }
        .tenant-info .domain { font-size: 0.75rem; color: var(--admin-text-secondary); }
        
        .status-pill { padding: 0.1rem 0.5rem; border-radius: 3px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
        .status-active { background: #eef8f1; color: #1e6b3e; }
        .status-suspended { background: #fff1f1; color: #b91c1c; }
        
        .res-text { font-size: 0.85rem; color: var(--admin-text); }
        .res-text small { color: var(--admin-text-secondary); display: block; font-size: 0.7rem; }

        .btn-manage { padding: 0.3rem 0.6rem; border-radius: 4px; font-weight: 500; font-size: 0.8rem; border: 1px solid var(--admin-border); color: var(--admin-text); text-decoration: none; transition: background 0.2s; }
        .btn-manage:hover { background: rgba(55, 53, 47, 0.04); }

        /* Custom Scrollbar to match Notion */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(55, 53, 47, 0.1); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(55, 53, 47, 0.2); }
    </style>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div style="padding: 0 0.75rem 2rem; display: flex; align-items: center; gap: 0.5rem;">
            <div style="width: 24px; height: 24px; background: var(--admin-primary); border-radius: 4px;"></div>
            <span style="font-weight: 700; font-size: 0.95rem; letter-spacing: -0.01em;">SuperAdmin</span>
        </div>
        <nav>
            <a href="index.php" class="nav-item active">
                <svg style="width: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                Dashboard
            </a>
            <a href="create_tenant.php" class="nav-item">
                <svg style="width: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                Provisioning
            </a>
            <a href="settings.php" class="nav-item">
                <svg style="width: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                Settings
            </a>
            <a href="login.php?logout=1" class="nav-item" style="margin-top: 2rem; color: #eb5757;">
                <svg style="width: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                Logout
            </a>
        </nav>
    </aside>

    <main class="main-content">
        <header style="margin-bottom: 4rem;">
            <h1 style="font-size: 2rem; font-weight: 700; margin: 0; letter-spacing: -0.02em;">Command Center</h1>
            <p style="color: var(--admin-text-secondary); margin: 0.5rem 0 0; font-size: 1rem;">Platform-wide resource monitoring and tenant orchestration.</p>
        </header>

        <section class="stats-grid">
            <div class="stat-card">
                <div class="label">Academies</div>
                <div class="value"><?= $totalTenants ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Total Students</div>
                <div class="value"><?= $totalStudents ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Disk Storage</div>
                <div class="value"><?= SuperAdminHelper::formatBytes($totalStorage) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Health</div>
                <div class="value" style="color: #1e6b3e;"><?= $activeTenants ?>/<?= $totalTenants ?></div>
            </div>
        </section>

        <div class="search-bar">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            <form method="GET">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by academy name, subdomain, or domain...">
            </form>
        </div>

        <table class="tenant-table">
            <thead>
                <tr>
                    <th>Academy</th>
                    <th>Status</th>
                    <th>Resources</th>
                    <th>Storage</th>
                    <th>Bandwidth</th>
                    <th>Subscription</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tenants as $t): ?>
                <tr>
                    <td>
                        <div class="tenant-info">
                            <span class="name"><?= htmlspecialchars($t['name']) ?></span>
                            <span class="domain"><?= htmlspecialchars($t['subdomain']) ?>.<?= PLATFORM_MAIN_DOMAIN ?></span>
                        </div>
                    </td>
                    <td>
                        <span class="status-pill status-<?= $t['status'] ?>">
                            <?= strtoupper($t['status']) ?>
                        </span>
                    </td>
                    <td>
                        <div class="res-text">
                            <strong><?= $t['stats']['users'] ?></strong> <small>Students</small>
                            <strong><?= $t['stats']['subjects'] ?></strong> <small>Subjects</small>
                        </div>
                    </td>
                    <td>
                        <div class="res-text"><?= SuperAdminHelper::formatBytes($t['stats']['storage']) ?></div>
                    </td>
                    <td>
                        <div class="res-text">
                            <?= SuperAdminHelper::formatBytes($t['stats']['bandwidth']) ?>
                            <small><?= number_format($t['stats']['requests']) ?> Requests</small>
                        </div>
                    </td>
                    <td>
                        <div style="font-size: 0.8rem; font-weight: 500;"><?= date('M j, Y', strtotime($t['subscription_expires_at'])) ?></div>
                        <?php if (strtotime($t['subscription_expires_at']) < time()): ?>
                            <div style="font-size: 0.65rem; color: #eb5757; font-weight: 700; text-transform: uppercase;">Expired</div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <a href="edit_tenant.php?id=<?= $t['id'] ?>" class="btn-manage">Manage</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($tenants)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 4rem; color: var(--admin-text-secondary); font-size: 0.9rem;">No matching academies found.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </main>
</div>

</body>
</html>

</body>
</html>
