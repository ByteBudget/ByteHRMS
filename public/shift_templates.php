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

// Handle Form Submissions (Create, Update, Delete Shift Template)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? 'create';
    $templateId = filter_input(INPUT_POST, 'shift_template_id', FILTER_VALIDATE_INT);

    try {
        if ($action === 'delete' && $templateId) {
            $delStmt = $pdo->prepare('DELETE FROM shift_templates WHERE shift_template_id = :id');
            $delStmt->execute(['id' => $templateId]);
            $success = 'Shift template deleted successfully.';
        } else {
            $tName       = trim($_POST['template_name'] ?? '');
            $start       = trim($_POST['default_start_time'] ?? '');
            $end         = trim($_POST['default_end_time'] ?? '');
            $unpaidBreak = filter_input(INPUT_POST, 'unpaid_break_minutes', FILTER_VALIDATE_INT);
            if ($unpaidBreak === false || $unpaidBreak === null) {
                $unpaidBreak = 30;
            }

            if (empty($tName) || empty($start) || empty($end)) {
                $error = 'Template name, start time, and end time are required.';
            } elseif ($action === 'update' && $templateId) {
                $stmt = $pdo->prepare('
                    UPDATE shift_templates 
                    SET template_name = :name,
                        default_start_time = :start,
                        default_end_time = :end,
                        unpaid_break_minutes = :break_mins
                    WHERE shift_template_id = :id
                ');
                $stmt->execute([
                    'name'       => $tName,
                    'start'      => $start,
                    'end'        => $end,
                    'break_mins' => $unpaidBreak,
                    'id'         => $templateId
                ]);
                $success = 'Shift template updated successfully.';
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO shift_templates (template_name, default_start_time, default_end_time, unpaid_break_minutes) 
                    VALUES (:name, :start, :end, :break_mins)
                ');
                $stmt->execute([
                    'name'       => $tName,
                    'start'      => $start,
                    'end'        => $end,
                    'break_mins' => $unpaidBreak
                ]);
                $success = 'Shift template added successfully.';
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Fetch single template if editing
$editTemplate = null;
$editId = filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT);
if ($editId) {
    $editStmt = $pdo->prepare('SELECT * FROM shift_templates WHERE shift_template_id = :id LIMIT 1');
    $editStmt->execute(['id' => $editId]);
    $editTemplate = $editStmt->fetch();
}

// Fetch all existing shift templates
$templates = $pdo->query('SELECT * FROM shift_templates ORDER BY template_name ASC')->fetchAll();

$pageTitle = 'Shift Templates Management';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header">
        <div>
            <h2>Shift Templates Management</h2>
            <p class="subtitle">Define standard work shift schedules and unpaid break durations for employee assignment.</p>
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
            <?php if ($error): ?>
                <div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <!-- Add / Edit Shift Template Form Card -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 style="margin-top: 0; color: #2b6cb0;">
                    <?= $editTemplate ? 'Edit Shift Template' : 'Create Shift Template' ?>
                </h3>
                <form method="POST" action="shift_templates.php">
                    <input type="hidden" name="action" value="<?= $editTemplate ? 'update' : 'create' ?>">
                    <?php if ($editTemplate): ?>
                        <input type="hidden" name="shift_template_id" value="<?= $editTemplate['shift_template_id'] ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="template_name">Template Name *</label>
                            <input type="text" id="template_name" name="template_name" required placeholder="e.g. Morning Shift" value="<?= htmlspecialchars($editTemplate['template_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="default_start_time">Start Time *</label>
                            <input type="time" id="default_start_time" name="default_start_time" required value="<?= htmlspecialchars($editTemplate['default_start_time'] ?? '08:00') ?>">
                        </div>
                        <div class="form-group">
                            <label for="default_end_time">End Time *</label>
                            <input type="time" id="default_end_time" name="default_end_time" required value="<?= htmlspecialchars($editTemplate['default_end_time'] ?? '16:30') ?>">
                        </div>
                        <div class="form-group">
                            <label for="unpaid_break_minutes">Unpaid Break (Mins)</label>
                            <input type="number" id="unpaid_break_minutes" name="unpaid_break_minutes" min="0" step="5" value="<?= htmlspecialchars($editTemplate['unpaid_break_minutes'] ?? '30') ?>">
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.75rem; margin-top: 1rem;">
                        <button type="submit" class="btn-submit">
                            <?= $editTemplate ? 'Update Template' : 'Save Template' ?>
                        </button>
                        <?php if ($editTemplate): ?>
                            <a href="shift_templates.php" class="btn-reset">Cancel</a>
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
                                <th>Unpaid Break</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($templates)): ?>
                                <?php foreach ($templates as $st): ?>
                                    <tr>
                                        <td><?= $st['shift_template_id'] ?></td>
                                        <td><strong><?= htmlspecialchars($st['template_name']) ?></strong></td>
                                        <td><?= date('g:i A', strtotime($st['default_start_time'])) ?></td>
                                        <td><?= date('g:i A', strtotime($st['default_end_time'])) ?></td>
                                        <td><?= (int)$st['unpaid_break_minutes'] ?> mins</td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                <a href="shift_templates.php?edit_id=<?= $st['shift_template_id'] ?>" class="btn-edit">Edit</a>
                                                <form method="POST" action="shift_templates.php" style="margin: 0;" onsubmit="return confirm('Delete this shift template?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="shift_template_id" value="<?= $st['shift_template_id'] ?>">
                                                    <button type="submit" class="btn-reset" style="color: #e53e3e; border-color: #feb2b2;">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: #a0aec0;">No shift templates configured yet.</td>
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
