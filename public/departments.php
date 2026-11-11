<?php
// public/departments.php - Organizational Department Hierarchy & Settings
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
    $action  = $_POST['action'] ?? '';
    $deptId  = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
    $deptName = trim($_POST['department_name'] ?? '');
    $parentId = !empty($_POST['parent_department_id']) ? (int)$_POST['parent_department_id'] : null;

    if ($action === 'delete' && $deptId) {
        try {
            $stmt = $pdo->prepare('DELETE FROM departments WHERE department_id = :id');
            $stmt->execute(['id' => $deptId]);
            $success = 'Department deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete department: ' . $e->getMessage();
        }
    } elseif ($action === 'update' && $deptId) {
        if (!empty($deptName)) {
            $stmt = $pdo->prepare('UPDATE departments SET department_name = :name, parent_department_id = :parent WHERE department_id = :id');
            $stmt->execute(['name' => $deptName, 'parent' => $parentId, 'id' => $deptId]);
            $success = 'Department updated successfully.';
        } else {
            $error = 'Department name cannot be empty.';
        }
    } elseif ($action === 'create') {
        if (!empty($deptName)) {
            $stmt = $pdo->prepare('INSERT INTO departments (department_name, parent_department_id) VALUES (:name, :parent)');
            $stmt->execute(['name' => $deptName, 'parent' => $parentId]);
            $success = 'Department created successfully.';
        } else {
            $error = 'Department name cannot be empty.';
        }
    }
}

// Fetch edit target if requested
$editDept = null;
$editId = filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT);
if ($editId) {
    $editStmt = $pdo->prepare('SELECT * FROM departments WHERE department_id = :id LIMIT 1');
    $editStmt->execute(['id' => $editId]);
    $editDept = $editStmt->fetch();
}

$departments = $pdo->query('
    SELECT d.*, p.department_name AS parent_name 
    FROM departments d 
    LEFT JOIN departments p ON d.parent_department_id = p.department_id 
    ORDER BY d.department_name ASC
')->fetchAll();

$pageTitle = 'Departments Management';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header">
        <div>
            <h2>Departments Management</h2>
            <p class="subtitle">Structure and manage organizational departments and reporting hierarchies.</p>
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
                <h3 style="margin-top: 0; color: #2b6cb0;"><?= $editDept ? 'Edit Department' : 'Add Department' ?></h3>
                <form method="POST" action="departments.php">
                    <input type="hidden" name="action" value="<?= $editDept ? 'update' : 'create' ?>">
                    <?php if ($editDept): ?>
                        <input type="hidden" name="department_id" value="<?= $editDept['department_id'] ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="department_name">Department Name *</label>
                            <input type="text" id="department_name" name="department_name" required value="<?= htmlspecialchars($editDept['department_name'] ?? '') ?>" placeholder="e.g. Engineering">
                        </div>
                        <div class="form-group">
                            <label for="parent_department_id">Parent Department (Optional)</label>
                            <select id="parent_department_id" name="parent_department_id">
                                <option value="">-- None (Top Level) --</option>
                                <?php foreach ($departments as $d): ?>
                                    <?php if ($editDept && $d['department_id'] == $editDept['department_id']) continue; ?>
                                    <option value="<?= $d['department_id'] ?>" <?= ($editDept && $editDept['parent_department_id'] == $d['department_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($d['department_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="submit" class="btn-submit"><?= $editDept ? 'Save Changes' : 'Create Department' ?></button>
                        <?php if ($editDept): ?>
                            <a href="departments.php" class="btn-reset" style="text-align: center; text-decoration: none;">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card">
                <h3 style="margin-top: 0; color: #2b6cb0;">Department Directory</h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Department Name</th>
                                <th>Parent Department</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($departments)): ?>
                                <?php foreach ($departments as $dept): ?>
                                    <tr>
                                        <td><?= $dept['department_id'] ?></td>
                                        <td><strong><?= htmlspecialchars($dept['department_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($dept['parent_name'] ?? '—') ?></td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                <a href="departments.php?edit_id=<?= $dept['department_id'] ?>" class="btn-reset" style="font-size: 0.8rem; padding: 0.25rem 0.6rem; text-decoration: none;">Edit</a>
                                                <form method="POST" action="departments.php" onsubmit="return confirm('Are you sure you want to delete this department?');" style="margin: 0;">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="department_id" value="<?= $dept['department_id'] ?>">
                                                    <button type="submit" style="background: #e53e3e; color: white; border: none; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer;">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: #a0aec0;">No departments configured yet.</td>
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
