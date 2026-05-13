<?php
require_once __DIR__ . '/../core/DatabaseManager.php';
require_once __DIR__ . '/SuperAdminHelper.php';

session_start();
if (!isset($_SESSION['superadmin_id'])) {
    header("Location: login.php");
    exit;
}

$success = '';
$error = '';

$backupDir = __DIR__ . '/../backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

// Handle Update
$updateZipPath = null;
$cleanupZip = false;

// 1. Manual Upload
if (isset($_FILES['system_zip'])) {
    if ($_FILES['system_zip']['error'] === UPLOAD_ERR_OK) {
        $updateZipPath = $_FILES['system_zip']['tmp_name'];
    } else {
        $uploadError = $_FILES['system_zip']['error'];
        if ($uploadError != 4) { // 4 is no file uploaded, which is fine if they use Github
            $error = "Upload failed with error code: $uploadError. ";
            if ($uploadError == 1 || $uploadError == 2) $error .= "The file is too large for your server's PHP configuration.";
        }
    }
}

// 2. GitHub Auto-Update
if (isset($_POST['github_repo']) && !empty($_POST['github_repo'])) {
    $repoUrl = trim($_POST['github_repo']);
    if (preg_match('/github\.com\/([^\/]+\/[^\/]+)/i', $repoUrl, $matches)) {
        $repoPath = str_replace('.git', '', $matches[1]);
        $downloadUrl = "https://github.com/{$repoPath}/archive/refs/heads/main.zip";
        
        $tempZip = sys_get_temp_dir() . '/github_update_' . time() . '.zip';
        
        $ch = curl_init($downloadUrl);
        $fp = fopen($tempZip, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Elms-Auto-Updater');
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        
        if ($httpCode == 200 && file_exists($tempZip) && filesize($tempZip) > 1000) {
            $updateZipPath = $tempZip;
            $cleanupZip = true;
        } else {
            $error = "Failed to download update from GitHub. Ensure the repository is public and the 'main' branch exists. (HTTP $httpCode)";
            if (file_exists($tempZip)) unlink($tempZip);
        }
    } else {
         $error = "Invalid GitHub repository URL format. Please use https://github.com/username/repo";
    }
}

// Process Update
if ($updateZipPath && !$error) {
    $zipFile = $updateZipPath;
    $zip = new ZipArchive;
    
    if ($zip->open($zipFile) === TRUE) {
        $timestamp = date('Ymd_His');
        $currentBackup = $backupDir . '/backup_' . $timestamp;
        mkdir($currentBackup, 0777, true);
        
        // Function to copy directory
        function recurseCopy($src, $dst, $exclude = []) {
            $dir = opendir($src);
            @mkdir($dst);
            while (false !== ($file = readdir($dir))) {
                if (($file != '.') && ($file != '..') && !in_array($file, $exclude)) {
                    if (is_dir($src . '/' . $file)) {
                        recurseCopy($src . '/' . $file, $dst . '/' . $file, $exclude);
                    } else {
                        copy($src . '/' . $file, $dst . '/' . $file);
                    }
                }
            }
            closedir($dir);
        }

        try {
            // 1. Backup current system (excluding the backups folder itself)
            recurseCopy(__DIR__ . '/..', $currentBackup, ['backups', '.git', 'node_modules']);
            
            // 2. Backup Database
            try {
                $dbBackupFile = $currentBackup . '/database.sql';
                // Try shell_exec mysqldump first (fastest)
                $cmd = "mysqldump -h " . PLATFORM_DB_HOST . " -u " . PLATFORM_DB_USER . " -p" . PLATFORM_DB_PASS . " " . PLATFORM_DB_NAME . " > \"$dbBackupFile\"";
                shell_exec($cmd);
                
                // Fallback: If file is empty or doesn't exist, use PHP-based backup (simplified)
                if (!file_exists($dbBackupFile) || filesize($dbBackupFile) < 100) {
                    // We'll leave it as a warning for now or implement a PHP backup if needed
                }
            } catch (Exception $dbE) {
                // Non-critical if DB backup fails, but good to know
            }

            // 3. Extract new files

            $extractPath = __DIR__ . '/../';
            // Optimization: Find if the ZIP has a single root directory (like lms-master/)
            $rootSubdir = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                $parts = explode('/', trim($filename, '/'));
                if (count($parts) > 0) {
                    if ($rootSubdir === '') { $rootSubdir = $parts[0]; } 
                    elseif ($rootSubdir !== $parts[0]) { $rootSubdir = null; break; }
                }
            }

            if ($rootSubdir && $zip->numFiles > 1) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);
                    $newPath = preg_replace('/^' . preg_quote($rootSubdir, '/') . '\//', '', $entryName);
                    if ($newPath && $newPath !== $entryName) {
                        if (substr($entryName, -1) === '/') {
                            @mkdir($extractPath . $newPath, 0777, true);
                        } else {
                            $dir = dirname($extractPath . $newPath);
                            if (!is_dir($dir)) @mkdir($dir, 0777, true);
                            copy("zip://" . $zipFile . "#" . $entryName, $extractPath . $newPath);
                        }
                    }
                }
            } else {
                $zip->extractTo($extractPath);
            }
            $zip->close();
            
            // 3. Restore Local Configuration Files
            $protectedPaths = [
                'platform_config.php'
            ];

            foreach ($protectedPaths as $path) {
                $src = $currentBackup . '/' . $path;
                $dst = __DIR__ . '/../' . $path;
                if (file_exists($src)) {
                    if (is_dir($src)) {
                        recurseCopy($src, $dst);
                    } else {
                        $dir = dirname($dst);
                        if (!is_dir($dir)) @mkdir($dir, 0777, true);
                        copy($src, $dst);
                    }
                }
            }

            // 4. Run Database Updates
            ob_start();
            require_once __DIR__ . '/../update_db.php';
            $db_log = ob_get_clean();

            $success = "System updated successfully! A backup was created at " . basename($currentBackup);
            if (strpos($db_log, 'complete') !== false) {
                $success .= " Database migrations also completed.";
            } else {
                $success .= " Warning: Database migration output: " . strip_tags($db_log);
            }
        } catch (Exception $e) {
            $error = "Update failed: " . $e->getMessage();
        }

    } else {
         $error = "Failed to open ZIP file.";
    }
    
    if ($cleanupZip && file_exists($updateZipPath)) {
        unlink($updateZipPath);
    }
}

// Handle Rollback
if (isset($_POST['rollback_version'])) {
    $version = $_POST['rollback_version'];
    $rollbackPath = $backupDir . '/' . $version;
    
    if (is_dir($rollbackPath)) {
        try {
            // Function to copy and overwrite
            function copyOverwrite($src, $dst) {
                $dir = opendir($src);
                while (false !== ($file = readdir($dir))) {
                    if (($file != '.') && ($file != '..')) {
                        if (is_dir($src . '/' . $file)) {
                            if (!is_dir($dst . '/' . $file)) mkdir($dst . '/' . $file);
                            copyOverwrite($src . '/' . $file, $dst . '/' . $file);
                        } else {
                            copy($src . '/' . $file, $dst . '/' . $file);
                        }
                    }
                }
                closedir($dir);
            }
            
            copyOverwrite($rollbackPath, __DIR__ . '/..');
            $success = "System rolled back to $version successfully!";
        } catch (Exception $e) {
            $error = "Rollback failed: " . $e->getMessage();
        }
    } else {
        $error = "Invalid rollback version.";
    }
}

// Get available backups
$backups = array_filter(glob($backupDir . '/*'), 'is_dir');
rsort($backups); // Newest first
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings | SuperAdmin</title>
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
            font-family: 'Inter', sans-serif;
            margin: 0; padding: 0;
        }
        .layout { display: flex; min-height: 100vh; }
        .sidebar { width: 240px; background: var(--admin-sidebar); color: white; padding: 2rem 1rem; position: sticky; top: 0; height: 100vh; }
        .main-content { flex: 1; padding: 3rem 4rem; max-width: 1000px; margin: 0 auto; width: 100%; }
        
        .nav-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.7rem 1rem; color: #94a3b8; text-decoration: none; border-radius: 8px; font-size: 0.9rem; font-weight: 500; transition: all 0.2s; margin-bottom: 0.5rem; }
        .nav-item:hover { background: rgba(255, 255, 255, 0.05); color: white; }
        .nav-item.active { background: var(--admin-primary); color: white; font-weight: 600; }

        .card { background: white; border: 1px solid var(--admin-border); border-radius: 12px; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 2rem; }
        .card-title { font-size: 1.25rem; font-weight: 700; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; }
        
        .upload-area { border: 2px dashed var(--admin-border); border-radius: 12px; padding: 3rem; text-align: center; transition: all 0.2s; cursor: pointer; }
        .upload-area:hover { border-color: var(--admin-primary); background: #f5f3ff; }
        
        .btn { padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; font-size: 0.9rem; cursor: pointer; transition: all 0.2s; border: none; }
        .btn-primary { background: var(--admin-primary); color: white; }
        .btn-primary:hover { background: var(--admin-primary-hover); }
        .btn-outline { background: transparent; border: 1px solid var(--admin-border); color: var(--admin-text); }
        .btn-outline:hover { background: #f8fafc; }

        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 2rem; font-size: 0.9rem; font-weight: 500; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        .backup-list { width: 100%; border-collapse: collapse; }
        .backup-list td { padding: 1rem; border-bottom: 1px solid var(--admin-border); font-size: 0.9rem; }
        .backup-list tr:last-child td { border-bottom: none; }
        
        .version-name { font-family: monospace; font-weight: 600; color: var(--admin-primary); }
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
            <a href="index.php" class="nav-item">
                <svg style="width: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                Dashboard
            </a>
            <a href="create_tenant.php" class="nav-item">
                <svg style="width: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                Provisioning
            </a>
            <a href="settings.php" class="nav-item active">
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
        <header style="margin-bottom: 3rem;">
            <h1 style="font-size: 2rem; font-weight: 700; margin: 0; letter-spacing: -0.02em;">System Settings</h1>
            <p style="color: var(--admin-text-secondary); margin: 0.5rem 0 0;">Manage platform updates and core configurations.</p>
        </header>

        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

        <div class="card">
            <div class="card-title">
                <svg style="width: 24px; color: var(--admin-primary);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg>
                Platform Configuration
            </div>
            <p style="color: var(--admin-text-secondary); font-size: 0.9rem; margin-top: -1rem; margin-bottom: 2rem;">Configure the primary identity of your SaaS platform.</p>
            
            <?php
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_platform_config'])) {
                $newMainDomain = $_POST['platform_main_domain'] ?? '';
                if ($newMainDomain) {
                    $configPath = __DIR__ . '/../platform_config.php';
                    $config = file_get_contents($configPath);
                    $config = preg_replace("/define\('PLATFORM_MAIN_DOMAIN',\s*'.*?'\);/", "define('PLATFORM_MAIN_DOMAIN', '" . addslashes($newMainDomain) . "');", $config);
                    file_put_contents($configPath, $config);
                    $success = "Platform configuration updated. Please refresh the page.";
                    echo "<script>window.location.reload();</script>";
                }
            }
            ?>

            <form action="" method="POST">
                <input type="hidden" name="update_platform_config" value="1">
                <div class="form-group">
                    <label>Main Platform Domain</label>
                    <input type="text" name="platform_main_domain" value="<?= PLATFORM_MAIN_DOMAIN ?>" placeholder="eduspark.com.lk" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--admin-border); border-radius: 8px;">
                    <small style="color: var(--admin-text-secondary); display: block; margin-top: 0.5rem;">This domain is used for all tenant subdomains (e.g., tutor.<?= PLATFORM_MAIN_DOMAIN ?>).</small>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top: 1rem;">Update Identity</button>
            </form>
        </div>

        <div class="card">
            <div class="card-title">
                <svg style="width: 24px; color: var(--admin-primary);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                System Update
            </div>
            <p style="color: var(--admin-text-secondary); font-size: 0.9rem; margin-top: -1rem; margin-bottom: 2rem;">Upload a .zip file containing the new system files. The current version will be backed up automatically.</p>
            
            <form action="" method="POST" enctype="multipart/form-data" id="updateForm">
                <input type="file" name="system_zip" id="zipInput" hidden accept=".zip">
                <div class="upload-area" onclick="document.getElementById('zipInput').click()">
                    <svg style="width: 48px; color: var(--admin-text-secondary); margin-bottom: 1rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    <div style="font-weight: 600;" id="fileNameDisplay">Click to select update ZIP</div>
                    <div style="font-size: 0.8rem; color: var(--admin-text-secondary); margin-top: 0.5rem;">Maximum file size: <?= ini_get('upload_max_filesize') ?></div>
                </div>
                
                <div style="margin-top: 2rem; display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-primary">Start Update</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-title">
                <svg style="width: 24px; color: var(--admin-primary);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path></svg>
                GitHub Auto-Update
            </div>
            <p style="color: var(--admin-text-secondary); font-size: 0.9rem; margin-top: -1rem; margin-bottom: 2rem;">Automatically download and install the latest <code>main</code> branch version from a public GitHub repository.</p>
            
            <form action="" method="POST" id="githubUpdateForm">
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;">GitHub Repository URL</label>
                    <input type="url" name="github_repo" value="https://github.com/nethmal14/elms" placeholder="https://github.com/username/repo" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--admin-border); border-radius: 8px;">
                </div>
                
                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-outline" style="border-color: var(--admin-primary); color: var(--admin-primary);" onclick="return confirm('This will download the latest code from GitHub and update your system. A backup will be created first. Proceed?')">Pull & Update Now</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-title">
                <svg style="width: 24px; color: var(--admin-primary);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
                Database Maintenance
            </div>
            <p style="color: var(--admin-text-secondary); font-size: 0.9rem; margin-top: -1rem; margin-bottom: 2rem;">Synchronize shared database schema and verify tenant storage directories across the platform.</p>
            
            <form action="" method="POST">
                <input type="hidden" name="run_db_update" value="1">
                <button type="submit" class="btn btn-outline" style="border-color: var(--admin-primary); color: var(--admin-primary);">Run Database Sync</button>
            </form>
            
            <?php if (isset($_POST['run_db_update'])): 
                ob_start();
                require_once __DIR__ . '/../update_db.php';
                $log = ob_get_clean();
            ?>
                <div style="margin-top: 2rem; background: #1e293b; color: #34d399; padding: 1.5rem; border-radius: 8px; font-family: monospace; font-size: 0.85rem; max-height: 300px; overflow-y: auto; white-space: pre-wrap;">
                    <?= htmlspecialchars($log) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-title">
                <svg style="width: 24px; color: var(--admin-primary);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                Available Rollbacks
            </div>

            <p style="color: var(--admin-text-secondary); font-size: 0.9rem; margin-top: -1rem; margin-bottom: 2rem;">Revert to a previous version of the system. This will overwrite current files.</p>
            
            <table class="backup-list">
                <?php if (empty($backups)): ?>
                    <tr>
                        <td colspan="2" style="text-align: center; color: var(--admin-text-secondary); padding: 2rem;">No backups available yet.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($backups as $b): 
                    $name = basename($b);
                    $dateStr = substr($name, 7); // remove 'backup_'
                    $formattedDate = DateTime::createFromFormat('Ymd_His', $dateStr)->format('M j, Y - H:i:s');
                ?>
                <tr>
                    <td>
                        <div class="version-name"><?= $name ?></div>
                        <div style="font-size: 0.75rem; color: var(--admin-text-secondary);"><?= $formattedDate ?></div>
                    </td>
                    <td style="text-align: right;">
                        <form action="" method="POST" onsubmit="return confirm('Are you sure you want to rollback to this version? This will overwrite current files.')">
                            <input type="hidden" name="rollback_version" value="<?= $name ?>">
                            <button type="submit" class="btn btn-outline">Rollback</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </main>
</div>

<script>
    document.getElementById('zipInput').addEventListener('change', function(e) {
        const fileName = e.target.files[0]?.name || 'Click to select update ZIP';
        document.getElementById('fileNameDisplay').textContent = fileName;
    });
</script>

</body>
</html>
