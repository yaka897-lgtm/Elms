<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/csrf.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['subject_id'])) {
    header("Location: dashboard.php");
    exit;
}

$pdo = getDB();
$subject_id = (int)$_GET['subject_id'];
$user_id = $_SESSION['user_id'];

// Get subject (PERF-4: Specific columns)
$stmt = $pdo->prepare("SELECT id, name, description, price FROM subjects WHERE id = ?");
$stmt->execute([$subject_id]);
$subject = $stmt->fetch();

if (!$subject) {
    die("Subject not found.");
}

// Check existing payment for current month
$curr_month = (int)date('n');
$curr_year = (int)date('Y');

$pStmt = $pdo->prepare("SELECT status, created_at FROM payments WHERE user_id = ? AND subject_id = ? ORDER BY id DESC");
$pStmt->execute([$user_id, $subject_id]);

$can_pay = true;
while ($row = $pStmt->fetch()) {
    $time = strtotime($row['created_at']);
    $p_month = (int)date('n', $time);
    $p_year = (int)date('Y', $time);
    
    if ($p_month === $curr_month && $p_year === $curr_year) {
        if ($row['status'] === 'approved' || $row['status'] === 'pending') {
            $can_pay = false;
        }
        break;
    }
}

if (!$can_pay) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['proof'])) {
    csrf_verify();
    $file = $_FILES['proof'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        // SEC-6: File size limit
        $max_bytes = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $max_bytes) {
            $error = "File too large. Maximum allowed size is 5MB.";
        } else {
            // SEC-5: MIME type validation
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']);
            
            if (in_array($mime, $allowed_mimes)) {
                $ext = match($mime) {
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp',
                };
                $upload_dir = TenantContext::getUploadDir('payments/');
                
                // Secure random filename
                $filename = bin2hex(random_bytes(8)) . '.' . $ext;
                $destination = $upload_dir . '/' . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    $db_path = TenantContext::getUploadUrl('payments/' . $filename);
                    
                    $insert = $pdo->prepare("INSERT INTO payments (user_id, subject_id, proof_image, status) VALUES (?, ?, ?, 'pending')");
                    if ($insert->execute([$user_id, $subject_id, $db_path])) {
                        // Notify Admin
                        $adminStmt = $pdo->query("SELECT id FROM users WHERE role = 'admin'");
                        $admins = $adminStmt->fetchAll();
                        $msg = "New payment uploaded by " . $_SESSION['username'] . " for subject " . $subject['name'];
                        
                        $nInsert = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                        foreach ($admins as $admin) {
                            $nInsert->execute([$admin['id'], $msg]);
                        }

                        $success = "Payment proof uploaded successfully! Please wait for admin approval.";
                    } else {
                        $error = "Database error. Please try again.";
                    }
                } else {
                    $error = "Failed to save file.";
                }
            } else {
                $error = "Invalid file type. Only JPG, PNG, WEBP and GIF are allowed.";
            }
        }
    } else {
        $error = "Error uploading file.";
    }
}

// Get payment instructions
$iStmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'payment_instructions'");
$instructions = $iStmt->fetchColumn();

require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="<?= SITE_ROOT ?>css/dashboard.css?v=<?= filemtime(__DIR__ . '/css/dashboard.css') ?>">


<main class="container py-8">
    <div style="max-width: 580px; margin: 0 auto;">
        <a href="dashboard.php" class="btn btn-ghost btn-sm mb-6" style="padding-left: 0;">
            <svg class="icon icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
              <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12,19 5,12 12,5"/>
            </svg>
            Back to Dashboard
        </a>

        <div class="text-center mb-8">
            <h1 style="font-size: 1.75rem; margin-bottom: 0.5rem;">Subject Enrollment</h1>
            <p class="text-secondary">Enrolling in <strong><?= htmlspecialchars($subject['name']) ?></strong></p>
        </div>

        <?php if ($success): ?>
            <div class="card text-center py-10">
                <div class="icon-wrap icon-wrap-lg icon-wrap-success mb-6" style="margin: 0 auto;">
                    <svg class="icon icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                      <polyline points="20,6 9,17 4,12"/>
                    </svg>
                </div>
                <h3 class="mb-4">Verification Pending</h3>
                <p class="text-secondary mb-10"><?= htmlspecialchars($success) ?></p>
                <a href="dashboard.php" class="btn btn-primary btn-block">Return to Dashboard</a>
            </div>
        <?php else: ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error mb-6">
                    <svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                      <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="payment-instructions-block">
                <h4>Payment Instructions</h4>
                <div class="instruction-text mb-6"><?= nl2br(htmlspecialchars($instructions)) ?></div>
                <div class="flex-between" style="border-top: 1px dashed var(--blue-200); padding-top: 1rem;">
                    <span style="font-size: 0.85rem; color: var(--blue-700); font-weight: 600;">Total to pay</span>
                    <span style="font-size: 1.25rem; font-weight: 800; color: var(--text-primary);">LKR <?= number_format($subject['price'], 2) ?></span>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="form-group mb-8">
                    <label>Upload Payment Proof</label>
                    <div class="upload-zone" id="upload-zone">
                        <div class="upload-zone-inner">
                            <svg class="icon icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                              <polyline points="16,16 12,12 8,16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0018 9h-1.26A8 8 0 103 16.3"/>
                            </svg>
                            <p style="font-weight:600;color:var(--text-primary);margin:0.75rem 0 0.25rem;">Drop screenshot here</p>
                            <p style="font-size:0.82rem;margin:0;">or <span style="color:var(--blue-500);font-weight:600;">browse files</span></p>
                            <p style="font-size:0.75rem;color:var(--text-tertiary);margin-top:0.5rem;">JPG, PNG, GIF or WEBP · Max 5MB</p>
                            <input type="file" name="proof" accept="image/jpeg,image/png,image/gif,image/webp" required>
                        </div>
                    </div>
                    <p id="upload-filename" style="font-size:0.8rem;color:var(--blue-600);font-weight:600;margin-top:0.5rem;"></p>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block btn-lg">Submit Payment Proof</button>
            </form>

        <?php endif; ?>
    </div>
</main>

<script>
var zone = document.getElementById('upload-zone');
var fileInput = zone ? zone.querySelector('input[type=file]') : null;
var fname = document.getElementById('upload-filename');
if (fileInput) {
  fileInput.addEventListener('change', function() {
    if (this.files[0] && fname) fname.textContent = '✓ ' + this.files[0].name;
  });
  zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.classList.add('drag-over'); });
  zone.addEventListener('dragleave', function() { zone.classList.remove('drag-over'); });
  zone.addEventListener('drop', function(e) {
    e.preventDefault();
    zone.classList.remove('drag-over');
    if (e.dataTransfer.files[0]) {
      fileInput.files = e.dataTransfer.files;
      if (fname) fname.textContent = '✓ ' + e.dataTransfer.files[0].name;
    }
  });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
