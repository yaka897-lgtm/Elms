<?php
require_once __DIR__ . '/../core/DatabaseManager.php';
require_once __DIR__ . '/SuperAdminHelper.php';

session_start();
if (!isset($_SESSION['superadmin_id'])) {
    header("Location: login.php");
    exit;
}

$pdo = DatabaseManager::getPlatformDB();
$id = $_GET['id'] ?? 0;

// Fetch tenant
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
$stmt->execute([$id]);
$tenant = $stmt->fetch();

if (!$tenant) {
    die("Tenant not found.");
}

// Ensure custom_homepage_html column exists & table is utf8mb4 (Auto-migration)
try {
    $pdo->query("SELECT custom_homepage_html FROM tenants LIMIT 1");
} catch (Exception $e) {
    // First, try to ensure table is utf8mb4 for emoji/special char support
    try {
        $pdo->exec("ALTER TABLE tenants CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("ALTER TABLE tenants ADD COLUMN custom_homepage_html TEXT NULL AFTER subscription_expires_at");
    } catch (Exception $e2) {
        // Column might already exist
    }
}

// Always try to ensure the specific column is utf8mb4 if it exists
try {
    $pdo->exec("ALTER TABLE tenants MODIFY COLUMN custom_homepage_html TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Exception $e) {}

$success = '';
$error = '';
$explorer_msg = '';

// Handle Tenant Info Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_info') {
    $name = $_POST['name'];
    $custom_domain = $_POST['custom_domain'] ?: null;
    $status = $_POST['status'];
    $expires = str_replace('T', ' ', $_POST['subscription_expires_at']);
    $custom_homepage_html = $_POST['custom_homepage_html'] ?: null;

    try {
        $update = $pdo->prepare("UPDATE tenants SET name = ?, custom_domain = ?, status = ?, subscription_expires_at = ?, custom_homepage_html = ? WHERE id = ?");
        $update->execute([$name, $custom_domain, $status, $expires, $custom_homepage_html, $id]);
        $success = "Tenant updated successfully.";
        $stmt->execute([$id]);
        $tenant = $stmt->fetch();
    } catch (Exception $e) {
        $error = "Error updating tenant: " . $e->getMessage();
    }
}

// Handle Database Explorer Actions
$tenantPdo = null;
$tables = [];
try {
    $tenantPdo = DatabaseManager::getTenantDB($tenant);
    $tables = $tenantPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$selectedTable = $_GET['table'] ?? null;
$action = $_GET['db_action'] ?? null;
$rowId = $_GET['row_id'] ?? null;

if ($tenantPdo && $selectedTable && in_array($selectedTable, $tables)) {
    // 1. Handle Row Deletion
    if ($action === 'delete') {
        try {
            $del = $tenantPdo->prepare("DELETE FROM `$selectedTable` WHERE id = ?");
            $del->execute([$rowId]);
            $explorer_msg = "Row #$rowId deleted successfully.";
        } catch (Exception $e) {
            $explorer_msg = "Delete Error: " . $e->getMessage();
        }
    }
    
    // 2. Handle Row Update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['db_action']) && $_POST['db_action'] === 'update_row') {
        $cols = array_keys($_POST['data']);
        $setStr = "";
        $params = [];
        foreach ($cols as $c) {
            $setStr .= "`$c` = ?, ";
            $params[] = $_POST['data'][$c];
        }
        $setStr = rtrim($setStr, ", ");
        $params[] = $_POST['target_id'];
        
        try {
            $upd = $tenantPdo->prepare("UPDATE `$selectedTable` SET $setStr WHERE id = ?");
            $upd->execute($params);
            $explorer_msg = "Row updated successfully.";
            $action = null; // Close edit form
        } catch (Exception $e) {
            $explorer_msg = "Update Error: " . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_tenant') {
    $delete = $pdo->prepare("DELETE FROM tenants WHERE id = ?");
    $delete->execute([$id]);
    header("Location: index.php");
    exit;
}

$stats = SuperAdminHelper::getTenantStats($tenant);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Academy - <?= htmlspecialchars($tenant['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=<?= filemtime(__DIR__ . '/../css/style.css') ?>">
    <style>
        :root {
            --admin-bg: #f8fafc;
            --admin-primary: #4f46e5;
            --admin-text: #0f172a;
            --admin-text-secondary: #64748b;
            --admin-border: #e2e8f0;
            --terminal-bg: #1e1e1e;
            --terminal-header: #2d2d2d;
            --terminal-text: #d4d4d4;
        }
        body { background: var(--admin-bg); color: var(--admin-text); font-family: 'Inter', sans-serif; margin: 0; padding: 3rem 2rem; display: block; }
        .container { max-width: 1200px; margin: 0 auto; }
        .back-link { display: inline-flex; align-items: center; gap: 0.5rem; color: var(--admin-text-secondary); text-decoration: none; font-size: 0.9rem; font-weight: 500; margin-bottom: 2rem; transition: color 0.2s; }
        .back-link:hover { color: var(--admin-text); }
        .card { background: white; border: 1px solid var(--admin-border); border-radius: 12px; padding: 2rem; margin-bottom: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card h3 { margin: 0 0 1.5rem; font-size: 1.1rem; font-weight: 700; letter-spacing: -0.01em; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 2.5rem; }
        .mini-stat { border-left: 2px solid var(--admin-border); padding-left: 1rem; }
        .mini-stat .label { font-size: 0.7rem; color: var(--admin-text-secondary); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
        .mini-stat .value { font-size: 1.5rem; font-weight: 800; color: var(--admin-text); }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--admin-text-secondary); margin-bottom: 0.5rem; }
        .form-group input, .form-group select { width: 100%; padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid var(--admin-border); font-family: inherit; font-size: 0.9rem; margin-bottom: 1.5rem; }
        
        .terminal { background: var(--terminal-bg); border-radius: 12px; overflow: hidden; display: flex; height: 500px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); border: 1px solid #333; color: #d4d4d4 !important; }
        .terminal-sidebar { width: 220px; background: #252526; border-right: 1px solid #333; display: flex; flex-direction: column; }
        .terminal-sidebar-header { padding: 1rem; font-size: 0.7rem; font-weight: 700; color: #aaaaaa !important; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #333; display: flex; align-items: center; gap: 0.5rem; }
        .terminal-sidebar-items { flex: 1; overflow-y: auto; padding: 0.5rem; }
        .table-item { display: flex; align-items: center; gap: 0.5rem; padding: 0.4rem 0.6rem; color: #d4d4d4 !important; text-decoration: none; font-size: 0.85rem; border-radius: 4px; transition: none; }
        .table-item:hover { background: transparent !important; color: #d4d4d4 !important; }
        .table-item.active { background: #37373d !important; color: #569cd6 !important; font-weight: 600; }
        .table-item.active:hover { background: #37373d !important; color: #569cd6 !important; }
        .table-item svg { width: 16px; opacity: 0.8; }
        .terminal-content { flex: 1; display: flex; flex-direction: column; background: var(--terminal-bg); }
        .terminal-header { background: var(--terminal-header); padding: 0.5rem 1.5rem; display: flex; align-items: center; justify-content: space-between; height: 35px; border-bottom: 1px solid #333; }
        .terminal-body { flex: 1; overflow: auto; padding: 1rem; font-family: 'JetBrains Mono', monospace; color: #d4d4d4 !important; }
        .data-grid { width: 100%; border-collapse: collapse; color: #cccccc !important; font-size: 0.85rem; }
        .data-grid th { background: #2d2d2d; position: sticky; top: -1rem; padding: 0.5rem 1rem; text-align: left; border-bottom: 1px solid #333; color: #569cd6 !important; font-weight: 600; z-index: 10; }
        .data-grid td { padding: 0.5rem 1rem; border-bottom: 1px solid #252526; color: #d4d4d4 !important; }
        .data-grid tr:hover { background: transparent !important; }
        .data-grid tr:hover td { background: transparent !important; color: #d4d4d4 !important; }
        
        .action-link { font-size: 0.7rem; text-decoration: none; padding: 0.2rem 0.4rem; border-radius: 3px; font-weight: 600; }
        .action-edit { color: #569cd6; }
        .action-del { color: #f44747; }
        .action-link:hover { background: rgba(255,255,255,0.1); }

        .danger-section { margin-top: 4rem; border-top: 1px solid var(--admin-border); padding-top: 2rem; }
        .danger-card { border: 1px solid #eb575722; background: #fff1f1; border-radius: 12px; padding: 2rem; display: none; margin-top: 1.5rem; }
        .danger-toggle { background: #fff1f1; border: 1px solid #eb575711; color: #eb5757; font-weight: 700; font-size: 0.9rem; cursor: pointer; display: flex; align-items: center; gap: 0.6rem; padding: 0.6rem 1.2rem; border-radius: 8px; transition: all 0.2s; }
        .danger-toggle:hover { background: #eb5757; color: white; }
    </style>
</head>
<body>

<div class="container">
    <a href="index.php" class="back-link">
        <svg style="width: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
        Back to Dashboard
    </a>

    <header style="margin-bottom: 4rem; display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <h1 style="font-size: 2.5rem; font-weight: 800; margin: 0; letter-spacing: -0.04em;"><?= htmlspecialchars($tenant['name']) ?></h1>
            <p style="color: var(--admin-text-secondary); margin: 0.5rem 0 0; font-size: 1.1rem;">Academy Instance Control Panel</p>
        </div>
        <div class="status-pill status-<?= $tenant['status'] ?>" style="font-size: 0.85rem; padding: 0.5rem 1rem;"><?= strtoupper($tenant['status']) ?></div>
    </header>

    <?php if ($success): ?><div class="alert alert-success" style="margin-bottom: 2rem;"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error" style="margin-bottom: 2rem;"><?= $error ?></div><?php endif; ?>

    <section class="stats-grid">
        <div class="mini-stat">
            <div class="label">Total Students</div>
            <div class="value"><?= number_format($stats['users']) ?></div>
        </div>
        <div class="mini-stat">
            <div class="label">Disk Usage</div>
            <div class="value"><?= SuperAdminHelper::formatBytes($stats['storage']) ?></div>
        </div>
        <div class="mini-stat">
            <div class="label">Total Requests</div>
            <div class="value"><?= number_format($stats['requests']) ?></div>
        </div>
        <div class="mini-stat">
            <div class="label">Bandwidth</div>
            <div class="value"><?= SuperAdminHelper::formatBytes($stats['bandwidth']) ?></div>
        </div>
    </section>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; align-items: start;">
        <div class="card">
            <h3>General Settings</h3>
            <form method="POST">
                <input type="hidden" name="action" value="update_info">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                    <div class="form-group">
                        <label>Academy Name</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($tenant['name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Custom Domain</label>
                        <input type="text" name="custom_domain" value="<?= htmlspecialchars($tenant['custom_domain'] ?? '') ?>" placeholder="e.g. university.com">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                    <div class="form-group">
                        <label>Account Status</label>
                        <select name="status">
                            <option value="active" <?= $tenant['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="suspended" <?= $tenant['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Expiry Date</label>
                        <input type="datetime-local" name="subscription_expires_at" value="<?= date('Y-m-d\TH:i', strtotime($tenant['subscription_expires_at'])) ?>" required>
                    </div>
                </div>
                <div class="form-group" style="margin-top: 1.5rem;">
                    <label>Custom Homepage HTML (Overrides root index.php)</label>
                    <textarea name="custom_homepage_html" style="width: 100%; height: 300px; padding: 1rem; border-radius: 8px; border: 1px solid var(--admin-border); font-family: 'JetBrains Mono', monospace; font-size: 0.85rem; background: #fff; color: var(--admin-text); resize: vertical;" placeholder="Enter custom HTML here. Use {{hero_bg_image}}, {{tutor_name}}, etc. as placeholders."><?= htmlspecialchars($tenant['custom_homepage_html'] ?? '') ?></textarea>
                    <p style="font-size: 0.75rem; color: var(--admin-text-secondary); margin-top: 0.5rem;">Available placeholders: {{hero_bg_image}}, {{hero_heading}}, {{hero_subtext}}, {{tutor_name}}, {{tutor_bio}}, {{tutor_image}}, {{site_name}}</p>
                </div>
                <button type="submit" class="btn btn-primary" style="padding: 0.75rem 2rem; border-radius: 8px; font-weight: 700; margin-top: 1rem;">Save Changes</button>
            </form>
        </div>

        <div class="card" style="background: #f1f5f9; border: none;">
            <h3>Technical Blueprint</h3>
            <div style="font-family: monospace; font-size: 0.85rem; color: var(--admin-text-secondary); line-height: 1.8;">
                <div><strong style="color: var(--admin-text);">Namespace:</strong> <?= htmlspecialchars($tenant['subdomain']) ?></div>
                <div><strong style="color: var(--admin-text);">Database:</strong> <?= htmlspecialchars($tenant['db_name']) ?></div>
                <div><strong style="color: var(--admin-text);">ID:</strong> #<?= $tenant['id'] ?></div>
                <div><strong style="color: var(--admin-text);">Provisioned:</strong> <?= date('Y-m-d', strtotime($tenant['created_at'])) ?></div>
            </div>
        </div>
    </div>

    <div style="margin: 2rem 0 1.5rem; display: flex; align-items: center; justify-content: space-between;">
        <h2 style="font-weight: 800; margin: 0;">Resource Explorer</h2>
        <div style="font-family: 'JetBrains Mono', monospace; font-size: 0.8rem; color: var(--admin-text-secondary);">
            Connection: <span style="color: #059669;">ESTABLISHED</span>
        </div>
    </div>

    <!-- Terminal Database Explorer -->
    <div class="terminal">
        <div class="terminal-sidebar">
            <div class="terminal-sidebar-header">
                <svg style="width: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
                Explorer
            </div>
            <div class="terminal-sidebar-items">
                <?php foreach ($tables as $tbl): 
                    $isActive = $selectedTable === $tbl;
                ?>
                    <a href="?id=<?= $id ?>&table=<?= urlencode($tbl) ?>#terminal" class="table-item <?= $isActive ? 'active' : '' ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
                        <?= htmlspecialchars($tbl) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="terminal-content" id="terminal">
            <div class="terminal-header">
                <div style="color: #858585; font-size: 0.75rem; font-family: 'JetBrains Mono', monospace; display: flex; align-items: center; gap: 1rem;">
                    <?php if ($selectedTable): ?>
                        <a href="?id=<?= $id ?>#terminal" style="color: #569cd6; text-decoration: none; display: flex; align-items: center; gap: 0.25rem;">
                            <svg style="width: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 15l-3-3m0 0l3-3m-3 3h8M3 12a9 9 0 1118 0 8.959 8.959 0 01-18 0z"></path></svg>
                            Go Up
                        </a>
                        <span>/ <?= htmlspecialchars($selectedTable) ?></span>
                    <?php else: ?>
                        <span>/ root</span>
                    <?php endif; ?>
                </div>
                <?php if ($explorer_msg): ?>
                    <div style="font-size: 0.7rem; color: #ce9178;"><?= htmlspecialchars($explorer_msg) ?></div>
                <?php endif; ?>
            </div>
            
            <div class="terminal-body">
                <?php if ($selectedTable && in_array($selectedTable, $tables)): 
                    if ($action === 'edit' && $rowId): 
                        $row = $tenantPdo->prepare("SELECT * FROM `$selectedTable` WHERE id = ?");
                        $row->execute([$rowId]);
                        $data = $row->fetch(PDO::FETCH_ASSOC);
                ?>
                        <!-- Edit Row Form -->
                        <div style="max-width: 600px;">
                            <div style="color: #569cd6; margin-bottom: 1.5rem;">// Edit Row #<?= $rowId ?> in `<?= htmlspecialchars($selectedTable) ?>`</div>
                            <form method="POST">
                                <input type="hidden" name="db_action" value="update_row">
                                <input type="hidden" name="target_id" value="<?= $rowId ?>">
                                <?php foreach ($data as $key => $val): if($key === 'id') continue; ?>
                                    <div style="margin-bottom: 1rem;">
                                        <label style="display: block; font-size: 0.75rem; color: #858585; margin-bottom: 0.25rem;"><?= htmlspecialchars($key) ?></label>
                                        <input type="text" name="data[<?= $key ?>]" value="<?= htmlspecialchars($val ?? '') ?>" 
                                               style="width: 100%; background: #2d2d2d; border: 1px solid #444; color: #d4d4d4; padding: 0.4rem; font-family: inherit;">
                                    </div>
                                <?php endforeach; ?>
                                <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                                    <button type="submit" style="background: #0e639c; color: white; border: none; padding: 0.5rem 1.5rem; cursor: pointer; font-family: inherit;">Update Record</button>
                                    <a href="?id=<?= $id ?>&table=<?= urlencode($selectedTable) ?>#terminal" style="color: #858585; text-decoration: none; padding-top: 0.4rem;">Cancel</a>
                                </div>
                            </form>
                        </div>
                <?php else: 
                    $data = $tenantPdo->query("SELECT * FROM `$selectedTable` LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
                    $columns = !empty($data) ? array_keys($data[0]) : [];
                ?>
                    <?php if (empty($data)): ?>
                        <div style="color: #6a9955;">// Query returned 0 results. Table is empty.</div>
                    <?php else: ?>
                        <table class="data-grid">
                            <thead>
                                <tr>
                                    <?php foreach ($columns as $col): ?>
                                        <th><?= htmlspecialchars($col) ?></th>
                                    <?php endforeach; ?>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $val): ?>
                                            <td><?= $val === null ? '<span style="color:#569cd6">null</span>' : htmlspecialchars($val) ?></td>
                                        <?php endforeach; ?>
                                        <td>
                                            <a href="?id=<?= $id ?>&table=<?= urlencode($selectedTable) ?>&db_action=edit&row_id=<?= $row['id'] ?? '' ?>#terminal" class="action-link action-edit">Edit</a>
                                            <a href="?id=<?= $id ?>&table=<?= urlencode($selectedTable) ?>&db_action=delete&row_id=<?= $row['id'] ?? '' ?>#terminal" 
                                               class="action-link action-del" 
                                               onclick="return confirm('Delete this row permanently?')">Del</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                <?php endif; ?>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #666;">
                        <svg style="width: 64px; margin-bottom: 1rem; opacity: 0.1;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path></svg>
                        <p style="font-size: 0.9rem;">Select a table to start inspecting data.</p>
                        <code style="background: rgba(255,255,255,0.05); padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.8rem; margin-top: 1rem;">root@elms-saas:~$ _</code>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="danger-section">
        <button class="danger-toggle" onclick="toggleDangerZone()">
            <svg style="width: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.268 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            Danger Zone
        </button>
        <div id="dangerCard" class="danger-card">
            <h4 style="color: #b91c1c; margin-top: 0; font-weight: 800;">Archive Academy Instance</h4>
            <p style="font-size: 0.9rem; color: #b91c1c; margin-bottom: 1.5rem;">This will permanently revoke all access for this tutor. Database records will remain in the system for manual archival.</p>
            <form method="POST" onsubmit="return confirm('CRITICAL: This cannot be undone. Are you sure?')">
                <input type="hidden" name="action" value="delete_tenant">
                <button type="submit" style="background: #eb5757; color: white; border: none; padding: 0.6rem 1.5rem; border-radius: 8px; font-weight: 700; cursor: pointer;">Permanently Delete Instance</button>
            </form>
        </div>
    </div>

    <div class="card glass-panel" style="margin-top: 2rem; border: 1px solid var(--primary-color);">
        <h3>Monthly Data Backup (Export)</h3>
        <p style="color: var(--text-secondary); margin-bottom: 2rem;">Download a complete ZIP archive for this tutor including database CSVs and all uploaded files (PDFs, Images, Notes) for a specific month.</p>
        
        <div style="display: flex; gap: 1rem; align-items: flex-end; background: rgba(var(--primary-rgb), 0.05); padding: 1.5rem; border-radius: 12px;">
            <div class="form-group" style="margin-bottom: 0;">
                <label>Select Month</label>
                <input type="month" id="exportMonth" value="<?= date('Y-m') ?>" class="form-control" style="width: 200px;">
            </div>
            <button onclick="startTenantExport()" class="btn btn-primary" style="display: flex; align-items: center; gap: 0.5rem;">
                <svg style="width: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                Download Full Monthly Archive
            </button>
        </div>
    </div>
</div>

<script>
function toggleDangerZone() {
    if (confirm("WARNING: You are entering the Danger Zone. Proceed?")) {
        const card = document.getElementById('dangerCard');
        card.style.display = card.style.display === 'block' ? 'none' : 'block';
    }
}

function startTenantExport() {
    const month = document.getElementById('exportMonth').value;
    window.location.href = 'api/export_tenant_data.php?tenant_id=<?= $tenant_id ?>&month=' + month;
}
</script>

</body>
</html>
