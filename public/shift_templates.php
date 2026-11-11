<?php
// public/shift_templates.php - Shift Templates Management
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

// Handle Form Submissions (Create / Update / Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $stId     = filter_input(INPUT_POST, 'shift_template_id', FILTER_VALIDATE_INT);
    $tName    = trim($_POST['template_name'] ?? '');
    $start    = $_POST['start_time'] ?? '';
    $end      = $_POST['end_time'] ?? '';

    if ($action === 'delete' && $stId) {
        try {
            $stmt = $pdo->prepare('DELETE FROM shift_templates WHERE shift_template_id = :id');
            $stmt->execute(['id' => $stId]);
            $success = 'Shift template deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete shift template: ' . $e->getMessage();
        }
    } elseif ($action === 'update' && $stId) {
        if (!empty($tName) && !empty($start) && !empty($end)) {
            $stmt = $pdo->prepare('UPDATE shift_templates SET template_name = :name, start_time = :start, end_time = :end WHERE shift_template_id = :id');
            $stmt->execute(['name' => $tName, 'start' => $start, 'end' => $end, 'id' => $stId]);
            $success = 'Shift template updated successfully.';
        } else {
            $error = 'All fields are required.';
        }
    } elseif ($action === 'create') {
        if (!empty($tName) && !empty($start) && !empty($end)) {
            $stmt = $pdo->prepare('INSERT INTO shift_templates (template_name, start_time, end_time) VALUES (:name, :start, :end)');
            $stmt->execute(['name' => $tName, 'start' => $start, 'end' => $end]);
            $success = 'Shift template added successfully.';
        } else {
            $error = 'All fields are required.';
        }
    }
}

// Fetch record for inline editing if edit_id is passed
$editSt = null;
$editId = filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT);
if ($editId) {
    $editStmt = $pdo->prepare('SELECT * FROM shift_templates WHERE shift_template_id = :id LIMIT 1');
    $editStmt->execute(['id' => $editId]);
    $editSt = $editStmt->fetch();
}

$templates = $pdo->query('SELECT * FROM shift_templates ORDER BY template_name ASC')->fetchAll();

$pageTitle = 'Shift Templates Management';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header">
        <div>
            <h2>Shift Templates Management</h2>
            <p class="subtitle">Define standard work shift schedules for employee assignment.</p>
        </div>
    </div>

    <!-- Two-column layout: Left sidebar + Right settings content -->
    <div style="display: flex; gap: 2rem; align-items: flex-start; margin-top: 1rem; width: 100%;">
        
        <!-- LEFT COLUMN: Side Navigation Bar -->
        <div style="width: 260px; min-width: 260px; flex-shrink: 0;">
            <?php require_once __DIR__ . '/../includes/settings_sidebar.php'; ?>
        </div>

        <!-- RIGHT COLUMN: Shift Templates Settings & Table -->
        <div style="flex: 1 1 auto; min-width: 0;">
            <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <!-- Add / Edit Shift Template Form Card -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 style="margin-top: 0; color: #2b6cb0;"><?= $editSt ? 'Edit Shift Template' : 'Create Shift Template' ?></h3>
                <form method="POST" action="shift_templates.php">
                    <input type="hidden" name="action" value="<?= $editSt ? 'update' : 'create' ?>">
                    <?php if ($editSt): ?>
                        <input type="hidden" name="shift_template_id" value="<?= $editSt['shift_template_id'] ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="template_name">Template Name *</label>
                            <input type="text" id="template_name" name="template_name" required value="<?= htmlspecialchars($editSt['template_name'] ?? '') ?>" placeholder="e.g. Morning Shift">
                        </div>
                        <div class="form-group">
                            <label for="start_time">Start Time *</label>
                            <input type="time" id="start_time" name="start_time" required value="<?= htmlspecialchars($editSt['start_time'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="end_time">End Time *</label>
                            <input type="time" id="end_time" name="end_time" required value="<?= htmlspecialchars($editSt['end_time'] ?? '') ?>">
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="submit" class="btn-submit"><?= $editSt ? 'Save Changes' : 'Save Template' ?></button>
                        <?php if ($editSt): ?>
                            <a href="shift_templates.php" class="btn-reset" style="text-align: center; text-decoration: none;">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Existing Shift Templates List Card -->
            <div class="card">
                <h3 style="margin-top: 0; color: #2b6cb0;">Active Shift Templates</h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Template Name</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($templates)): ?>
                                <?php foreach ($templates as $st): ?>
                                    <tr>
                                        <td><?= $st['shift_template_id'] ?></td>
                                        <td><strong><?= htmlspecialchars($st['template_name']) ?></strong></td>
                                        <td><?= date('g:i A', strtotime($st['start_time'])) ?></td>
                                        <td><?= date('g:i A', strtotime($st['end_time'])) ?></td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                <a href="shift_templates.php?edit_id=<?= $st['shift_template_id'] ?>" class="btn-reset" style="font-size: 0.8rem; padding: 0.25rem 0.6rem; text-decoration: none;">Edit</a>
                                                <form method="POST" action="shift_templates.php" onsubmit="return confirm('Are you sure you want to delete this shift template?');" style="margin: 0;">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="shift_template_id" value="<?= $st['shift_template_id'] ?>">
                                                    <button type="submit" style="background: #e53e3e; color: white; border: none; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer;">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: #a0aec0;">No shift templates configured yet.</td>
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
