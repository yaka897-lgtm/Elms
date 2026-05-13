<?php
require_once __DIR__ . '/includes/header.php';

// Fetch all grades
$stmt = $pdo->query("SELECT * FROM grades ORDER BY name ASC");
$grades = $stmt->fetchAll();
?>

<link rel="stylesheet" href="<?= SITE_ROOT ?>css/home.css?v=<?= filemtime(__DIR__ . '/css/home.css') ?>">


<main class="container py-12">
    <div class="mb-12">
        <h1 class="mb-4">Explore Our Courses</h1>
        <p class="text-secondary" style="font-size: 1.1rem; max-width: 600px;">Choose from a wide range of subjects designed to help you master new skills and advance your education.</p>
    </div>

    <div id="store">
        <?php if (empty($grades)): ?>
            <div class="card text-center py-12">
                <div class="icon-wrap icon-wrap-md mb-4" style="margin: 0 auto;">
                    <svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                    </svg>
                </div>
                <h3 class="mb-2">No Courses Available</h3>
                <p class="text-secondary">Please check back later for new academic programs.</p>
            </div>
        <?php else: ?>
            <?php foreach ($grades as $grade): ?>
                <?php
                $subStmt = $pdo->prepare("SELECT * FROM subjects WHERE grade_id = ? ORDER BY name ASC");
                $subStmt->execute([$grade['id']]);
                $subjects = $subStmt->fetchAll();
                ?>

                <?php if (!empty($subjects)): ?>
                    <section class="mb-12">
                        <div class="grade-section-head">
                            <span class="grade-pill"><?= htmlspecialchars($grade['name']) ?></span>
                            <div class="grade-rule"></div>
                        </div>
                        
                        <div class="grid grid-cols-3">
                            <?php foreach ($subjects as $subject): ?>
                                <a href="store_subject.php?id=<?= $subject['id'] ?>" class="card" style="text-decoration: none; color: inherit; display: flex; flex-direction: column;">
                                    <div class="flex-between mb-4">
                                        <h4 style="color: var(--blue-600);"><?= htmlspecialchars($subject['name']) ?></h4>
                                        <svg class="icon icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                          <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12,5 19,12 12,19"/>
                                        </svg>
                                    </div>
                                    <p class="text-secondary mb-8" style="font-size: 0.9rem; flex: 1;">
                                        <?= htmlspecialchars(substr($subject['description'], 0, 100)) ?>...
                                    </p>
                                    <div class="flex-between pt-6" style="border-top: 1px solid var(--border-default);">
                                        <div style="font-weight: 700; font-size: 1rem; color: var(--text-primary);">LKR <?= number_format($subject['price'], 2) ?></div>
                                        <span class="badge badge-blue">Enroll Now</span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
