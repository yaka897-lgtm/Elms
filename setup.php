<?php
session_start();

if (file_exists(__DIR__ . '/platform_config.php')) {
    // Already set up — block access
    http_response_code(403);
    die('<h1>403 Forbidden</h1><p>Setup has already been completed.</p>');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = $_POST['db_host'] ?? 'localhost';
    $db_name = $_POST['db_name'] ?? 'platform_db';
    $db_user = $_POST['db_user'] ?? 'root';
    $db_pass = $_POST['db_pass'] ?? '';
    $main_domain = $_POST['main_domain'] ?? 'localhost';
    $admin_user = $_POST['admin_user'] ?? 'superadmin';
    $admin_pass = $_POST['admin_pass'] ?? '';

    if (empty($db_host) || empty($db_name) || empty($db_user) || empty($admin_user) || empty($admin_pass) || empty($main_domain)) {
        $error = "Please fill in all required fields.";
    } else {
        try {
            // 1. Create Platform DB
            $dsn = "mysql:host=$db_host;charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$db_name`");

            // 2. Create Platform & Tenant Shared Tables
            $schema = file_get_contents(__DIR__ . '/platform_schema.sql');
            $pdo->exec($schema);


            // 3. Create Super Admin
            $hashed = password_hash($admin_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT IGNORE INTO super_admins (username, password) VALUES (?, ?)");
            $stmt->execute([$admin_user, $hashed]);

            // 4. Create Platform Config File
            $config_content = "<?php\n";
            $config_content .= "define('PLATFORM_DB_HOST', '" . addslashes($db_host) . "');\n";
            $config_content .= "define('PLATFORM_DB_NAME', '" . addslashes($db_name) . "');\n";
            $config_content .= "define('PLATFORM_DB_USER', '" . addslashes($db_user) . "');\n";
            $config_content .= "define('PLATFORM_DB_PASS', '" . addslashes($db_pass) . "');\n";
            $config_content .= "define('PLATFORM_MAIN_DOMAIN', '" . addslashes($main_domain) . "');\n";
            $config_content .= "define('TENANT_UPLOAD_BASE', __DIR__ . '/uploads');\n";
            
            file_put_contents(__DIR__ . '/platform_config.php', $config_content);

            $success = "Platform Setup Successful! Redirecting to SuperAdmin login...";
            echo "<script>setTimeout(() => window.location.href = 'superadmin/login.php', 2000);</script>";

        } catch (PDOException $e) {
            $error = "Platform Setup Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Elms SaaS Platform Setup</title>
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
    <style>
        body { background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .setup-card { background: white; padding: 2.5rem; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); width: 100%; max-width: 500px; }
    </style>
</head>
<body>
<div class="setup-card">
    <h2 style="text-align: center; margin-bottom: 0.5rem;">Elms SaaS Platform</h2>
    <p style="text-align: center; color: #64748b; margin-bottom: 2rem;">Initialize your multi-tenant LMS platform.</p>

    <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>

    <form method="POST">
        <h3>Master Database</h3>
        <div class="form-group"><label>DB Host</label><input type="text" name="db_host" value="localhost" required></div>
        <div class="form-group"><label>Platform DB Name</label><input type="text" name="db_name" value="platform_db" required></div>
        <div class="form-group"><label>DB User</label><input type="text" name="db_user" value="root" required></div>
        <div class="form-group"><label>DB Password</label><input type="password" name="db_pass"></div>

        <h3 style="margin-top: 2rem;">Platform Settings</h3>
        <div class="form-group">
            <label>Main Domain (e.g. lms-saas.com)</label>
            <input type="text" name="main_domain" value="localhost" required>
            <small style="color: #64748b;">This domain will be used for subdomains.</small>
        </div>

        <h3 style="margin-top: 2rem;">Super Admin Setup</h3>
        <div class="form-group"><label>Username</label><input type="text" name="admin_user" value="superadmin" required></div>
        <div class="form-group"><label>Password</label><input type="password" name="admin_pass" required></div>

        <button type="submit" class="btn btn-primary btn-block" style="margin-top: 2rem;">Install SaaS Platform</button>
    </form>
</div>
</body>
</html>
