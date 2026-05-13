<?php
require_once __DIR__ . '/../core/DatabaseManager.php';

session_start();
if (!isset($_SESSION['superadmin_id'])) {
    header("Location: login.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $subdomain = strtolower(preg_replace("/[^a-zA-Z0-9]/", "", $_POST['subdomain'] ?? ''));
    $admin_user = $_POST['admin_user'] ?? '';
    $admin_pass = $_POST['admin_pass'] ?? '';
    $months = (int)($_POST['subscription_months'] ?? 1);

    if (empty($name) || empty($subdomain) || empty($admin_user) || empty($admin_pass)) {
        $error = "All fields are required.";
    } else {
        $platformPdo = DatabaseManager::getPlatformDB();
        
        // Check if subdomain exists
        $check = $platformPdo->prepare("SELECT id FROM tenants WHERE subdomain = ?");
        $check->execute([$subdomain]);
        
        if ($check->fetch()) {
            $error = "Subdomain is already taken.";
        } else {
            try {
                // 1. Register Tenant in Platform DB
                $expiry = date('Y-m-d H:i:s', strtotime("+$months months"));
                
                // We still keep db_name, db_user, db_pass for schema compatibility, 
                // but we point them to the platform DB for now.
                $db_name = PLATFORM_DB_NAME;
                $db_user = PLATFORM_DB_USER;
                $db_pass = PLATFORM_DB_PASS;

                $regStmt = $platformPdo->prepare("INSERT INTO tenants (name, subdomain, db_name, db_user, db_password, subscription_expires_at) VALUES (?, ?, ?, ?, ?, ?)");
                $regStmt->execute([$name, $subdomain, $db_name, $db_user, $db_pass, $expiry]);
                
                $tenant_id = $platformPdo->lastInsertId();

                // 2. Create Admin User in Shared Users Table
                $hashed = password_hash($admin_pass, PASSWORD_DEFAULT);
                $stmt = $platformPdo->prepare("INSERT INTO users (tenant_id, username, password, role) VALUES (?, ?, ?, 'admin')");
                $stmt->execute([$tenant_id, $admin_user, $hashed]);

                // 3. Insert Default Settings in Shared Settings Table
                $settings = [
                    [$tenant_id, 'site_name', $name . ' Academy'],
                    [$tenant_id, 'payment_instructions', 'Please transfer to our bank account.'],
                    [$tenant_id, 'enable_past_papers', '1'],
                    [$tenant_id, 'grace_period_days', '5']
                ];
                $sStmt = $platformPdo->prepare("INSERT INTO settings (tenant_id, setting_key, setting_value) VALUES (?, ?, ?)");
                foreach ($settings as $s) { $sStmt->execute($s); }

                $success = "Tenant '$name' launched successfully on shared infrastructure!";
            } catch (Exception $e) {
                $error = "Provisioning Error: " . $e->getMessage();
            }

        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Provision New Academy</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=<?= filemtime(__DIR__ . '/../css/style.css') ?>">
    <style>
        :root {
            --admin-bg: #f8fafc;
            --admin-sidebar: #1e293b;
            --admin-primary: #4f46e5;
            --admin-text: #0f172a;
            --admin-text-secondary: #64748b;
            --admin-border: #e2e8f0;
        }
        body { 
            background: var(--admin-bg); 
            color: var(--admin-text); 
            font-family: 'Inter', sans-serif;
            margin: 0; padding: 0; display: flex; min-height: 100vh;
        }
        .sidebar { width: 240px; background: var(--admin-sidebar); color: white; padding: 2rem 1rem; position: sticky; top: 0; height: 100vh; }
        .main-content { flex: 1; padding: 3rem 4rem; max-width: 1000px; margin: 0 auto; }
        
        .nav-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.7rem 1rem; color: #94a3b8; text-decoration: none; border-radius: 8px; font-size: 0.9rem; font-weight: 500; transition: all 0.2s; margin-bottom: 0.5rem; }
        .nav-item:hover { background: rgba(255, 255, 255, 0.05); color: white; }
        .nav-item.active { background: var(--admin-primary); color: white; font-weight: 600; }

        .card { background: white; border: 1px solid var(--admin-border); border-radius: 12px; padding: 2.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--admin-text-secondary); margin-bottom: 0.5rem; }
        .form-group input, .form-group select { width: 100%; padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid var(--admin-border); font-family: inherit; font-size: 0.95rem; margin-bottom: 1.5rem; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div style="padding: 0 0.75rem 2rem; display: flex; align-items: center; gap: 0.5rem;">
        <div style="width: 24px; height: 24px; background: var(--admin-primary); border-radius: 4px;"></div>
        <span style="font-weight: 700; font-size: 0.95rem; letter-spacing: -0.01em;">SuperAdmin</span>
    </div>
    <nav>
        <a href="index.php" class="nav-item">
            <svg style="width: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
            Dashboard
        </a>
        <a href="create_tenant.php" class="nav-item active">
            <svg style="width: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
            Provisioning
        </a>
        <a href="settings.php" class="nav-item">
            <svg style="width: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            Settings
        </a>
    </nav>
</aside>

<main class="main-content">
    <header style="margin-bottom: 3rem;">
        <h1 style="font-size: 2rem; font-weight: 700; margin: 0; letter-spacing: -0.02em;">Provision New Academy</h1>
        <p style="color: var(--admin-text-secondary); margin: 0.5rem 0 0;">Spin up a completely isolated instance for a new tutor.</p>
    </header>

    <div class="card">
        <?php if ($error): ?><div class="alert alert-error" style="margin-bottom: 2rem;"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success" style="margin-bottom: 2rem;"><?= $success ?></div><?php endif; ?>

        <form method="POST">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                <div class="form-group">
                    <label>Academy Name</label>
                    <input type="text" name="name" placeholder="e.g. Einstein Physics" required>
                </div>
                <div class="form-group">
                    <label>Subdomain Identifier</label>
                    <div style="display: flex; align-items: center; position: relative;">
                        <input type="text" name="subdomain" placeholder="einstein" required style="padding-right: 120px;">
                        <span style="position: absolute; right: 1rem; top: 11px; font-size: 0.9rem; color: var(--admin-text-secondary); font-weight: 500;">.<?= PLATFORM_MAIN_DOMAIN ?></span>
                    </div>
                </div>
            </div>

            <div style="border-top: 1px solid var(--admin-border); margin: 2rem 0; padding-top: 2rem;">
                <h3 style="margin-bottom: 1.5rem; font-size: 1.1rem;">Tutor Account Credentials</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                    <div class="form-group">
                        <label>Admin Username</label>
                        <input type="text" name="admin_user" placeholder="admin" required>
                    </div>
                    <div class="form-group">
                        <label>Admin Password</label>
                        <input type="password" name="admin_pass" required>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Initial Subscription</label>
                <select name="subscription_months">
                    <option value="1">1 Month Plan</option>
                    <option value="3">3 Months Plan</option>
                    <option value="6">6 Months Plan</option>
                    <option value="12">12 Months (Annual)</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary" style="padding: 1rem 2rem; font-weight: 700; border-radius: 10px; width: 100%; margin-top: 1rem;">Launch New Instance</button>
        </form>
    </div>
</main>

</body>
</html>
