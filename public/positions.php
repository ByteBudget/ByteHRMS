<?php
// public/positions.php - Job Positions Management & Salary Ranges
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

if (!hasRole('admin')) {
    http_response_code(403);
    $pageTitle = 'Access Denied';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container"><div class="alert-danger"><strong>Access Denied:</strong> Admin privileges required.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $posId  = filter_input(INPUT_POST, 'position_id', FILTER_VALIDATE_INT);
    $title  = trim($_POST['job_title'] ?? '');
    $minSal = (float)($_POST['min_salary'] ?? 0);
    $maxSal = (float)($_POST['max_salary'] ?? 0);

    if ($action === 'delete' && $posId) {
        try {
            $stmt = $pdo->prepare('DELETE FROM job_positions WHERE position_id = :id');
            $stmt->execute(['id' => $posId]);
            $success = 'Job position deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete position: ' . $e->getMessage();
        }
    } elseif ($maxSal < $minSal) {
        $error = 'Maximum salary cannot be less than minimum salary.';
    } elseif (!empty($title)) {
        if ($action === 'create') {
            $stmt = $pdo->prepare('INSERT INTO job_positions (job_title, min_salary, max_salary) VALUES (:title, :min, :max)');
            $stmt->execute(['title' => $title, 'min' => $minSal, 'max' => $maxSal]);
            $success = 'Job position created successfully.';
        } elseif ($action === 'update' && $posId) {
            $stmt = $pdo->prepare('UPDATE job_positions SET job_title = :title, min_salary = :min, max_salary = :max WHERE position_id = :id');
            $stmt->execute(['title' => $title, 'min' => $minSal, 'max' => $maxSal, 'id' => $posId]);
            $success = 'Job position updated successfully.';
        }
    } else {
        $error = 'Job title cannot be empty.';
    }
}

$editPos = null;
$editId = filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT);
if ($editId) {
    $editStmt = $pdo->prepare('SELECT * FROM job_positions WHERE position_id = :id LIMIT 1');
    $editStmt->execute(['id' => $editId]);
    $editPos = $editStmt->fetch();
}

$positions = $pdo->query('SELECT * FROM job_positions ORDER BY job_title ASC')->fetchAll();

$pageTitle = 'Job Positions Management';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header">
        <div>
            <h2>Job Positions Management</h2>
            <p class="subtitle">Configure job titles and salary brackets across the organization.</p>
        </div>
    </div>

    <div style="display: flex; gap: 2rem; align-items: flex-start; margin-top: 1rem; width: 100%;">
        <div style="width: 260px; min-width: 260px; flex-shrink: 0;">
            <?php require_once __DIR__ . '/../includes/settings_sidebar.php'; ?>
        </div>

        <div style="flex: 1 1 auto; min-width: 0;">
            <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 style="margin-top: 0; color: #2b6cb0;"><?= $editPos ? 'Edit Job Position' : 'Add New Job Position' ?></h3>
                <form method="POST" action="positions.php">
                    <input type="hidden" name="action" value="<?= $editPos ? 'update' : 'create' ?>">
                    <?php if ($editPos): ?>
                        <input type="hidden" name="position_id" value="<?= $editPos['position_id'] ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="job_title">Job Title *</label>
                            <input type="text" id="job_title" name="job_title" required value="<?= htmlspecialchars($editPos['job_title'] ?? '') ?>" placeholder="e.g. Senior Software Engineer">
                        </div>
                        <div class="form-group">
                            <label for="min_salary">Minimum Salary ($)</label>
                            <input type="number" step="0.01" id="min_salary" name="min_salary" value="<?= htmlspecialchars($editPos['min_salary'] ?? '0.00') ?>">
                        </div>
                        <div class="form-group">
                            <label for="max_salary">Maximum Salary ($)</label>
                            <input type="number" step="0.01" id="max_salary" name="max_salary" value="<?= htmlspecialchars($editPos['max_salary'] ?? '0.00') ?>">
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="submit" class="btn-submit"><?= $editPos ? 'Save Changes' : 'Save Position' ?></button>
                        <?php if ($editPos): ?>
                            <a href="positions.php" class="btn-reset" style="text-align: center; text-decoration: none;">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card">
                <h3 style="margin-top: 0; color: #2b6cb0;">Existing Job Positions</h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Min Salary</th>
                                <th>Max Salary</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($positions)): ?>
                                <?php foreach ($positions as $pos): ?>
                                    <tr>
                                        <td><?= $pos['position_id'] ?></td>
                                        <td><strong><?= htmlspecialchars($pos['job_title']) ?></strong></td>
                                        <td>$<?= number_format($pos['min_salary'], 2) ?></td>
                                        <td>$<?= number_format($pos['max_salary'], 2) ?></td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                <a href="positions.php?edit_id=<?= $pos['position_id'] ?>" class="btn-reset" style="font-size: 0.8rem; padding: 0.25rem 0.6rem; text-decoration: none;">Edit</a>
                                                <form method="POST" action="positions.php" onsubmit="return confirm('Are you sure you want to delete this position?');" style="margin: 0;">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="position_id" value="<?= $pos['position_id'] ?>">
                                                    <button type="submit" style="background: #e53e3e; color: white; border: none; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer;">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: #a0aec0;">No job positions configured yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
