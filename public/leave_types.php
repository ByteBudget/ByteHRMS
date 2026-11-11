<?php
// public/leave_types.php - PTO & Time Off Category Settings
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

$error   = '';
$success = '';

// Handle CRUD Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action'] ?? '';
    $leaveTypeId = filter_input(INPUT_POST, 'leave_type_id', FILTER_VALIDATE_INT);
    $typeName    = trim($_POST['type_name'] ?? '');
    $isPaid      = isset($_POST['is_paid']) ? 1 : 0;

    if ($action === 'delete' && $leaveTypeId) {
        try {
            $stmt = $pdo->prepare('DELETE FROM leave_types WHERE leave_type_id = :id');
            $stmt->execute(['id' => $leaveTypeId]);
            $success = 'Leave category deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete leave category (check if active employee balances or requests exist).';
        }
    } elseif ($action === 'update' && $leaveTypeId) {
        if (!empty($typeName)) {
            try {
                $stmt = $pdo->prepare('UPDATE leave_types SET type_name = :name, is_paid = :is_paid WHERE leave_type_id = :id');
                $stmt->execute(['name' => $typeName, 'is_paid' => $isPaid, 'id' => $leaveTypeId]);
                $success = 'Leave category updated successfully.';
            } catch (Exception $e) {
                $error = 'Failed to update: ' . $e->getMessage();
            }
        } else {
            $error = 'Leave category name cannot be empty.';
        }
    } elseif ($action === 'create') {
        if (!empty($typeName)) {
            try {
                $stmt = $pdo->prepare('INSERT INTO leave_types (type_name, is_paid) VALUES (:name, :is_paid)');
                $stmt->execute(['name' => $typeName, 'is_paid' => $isPaid]);
                $success = 'Leave category created successfully.';
            } catch (Exception $e) {
                $error = 'Failed to create category: ' . $e->getMessage();
            }
        } else {
            $error = 'Leave category name cannot be empty.';
        }
    }
}

// Fetch record for inline editing
$editType = null;
$editId   = filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT);
if ($editId) {
    $editStmt = $pdo->prepare('SELECT * FROM leave_types WHERE leave_type_id = :id LIMIT 1');
    $editStmt->execute(['id' => $editId]);
    $editType = $editStmt->fetch();
}

// Query all leave types with count of active requests/balances
$leaveTypes = $pdo->query('
    SELECT lt.*, 
           COUNT(DISTINCT lr.request_id) AS request_count,
           COUNT(DISTINCT lb.balance_id) AS balance_count
    FROM leave_types lt
    LEFT JOIN leave_requests lr ON lt.leave_type_id = lr.leave_type_id
    LEFT JOIN leave_balances lb ON lt.leave_type_id = lb.leave_type_id
    GROUP BY lt.leave_type_id
    ORDER BY lt.type_name ASC
')->fetchAll();

$pageTitle = 'PTO & Time Off Settings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header">
        <div>
            <h2>PTO & Time Off Category Settings</h2>
            <p class="subtitle">Configure paid time off, sick leave, bereavement, and unpaid leave policy rules.</p>
        </div>
    </div>

    <!-- Two-column layout: Left sidebar + Right settings content -->
    <div style="display: flex; gap: 2rem; align-items: flex-start; margin-top: 1rem; width: 100%;">
        
        <!-- LEFT COLUMN: Side Navigation Bar -->
        <div style="width: 260px; min-width: 260px; flex-shrink: 0;">
            <?php require_once __DIR__ . '/../includes/settings_sidebar.php'; ?>
        </div>

        <!-- RIGHT COLUMN: Time Off Settings & Table -->
        <div style="flex: 1 1 auto; min-width: 0;">
            <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <!-- Add / Edit Leave Type Form Card -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 style="margin-top: 0; color: #2b6cb0;"><?= $editType ? 'Edit Time Off Category' : 'Add Time Off Category' ?></h3>
                <form method="POST" action="leave_types.php">
                    <input type="hidden" name="action" value="<?= $editType ? 'update' : 'create' ?>">
                    <?php if ($editType): ?>
                        <input type="hidden" name="leave_type_id" value="<?= $editType['leave_type_id'] ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group" style="flex: 2;">
                            <label for="type_name">Category Name *</label>
                            <input type="text" id="type_name" name="type_name" required value="<?= htmlspecialchars($editType['type_name'] ?? '') ?>" placeholder="e.g. Paid Time Off (PTO), Sick Leave, Floating Holiday">
                        </div>
                        <div class="form-group" style="flex: 1; display: flex; align-items: flex-end; padding-bottom: 0.5rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 600;">
                                <input type="checkbox" name="is_paid" value="1" <?= (!isset($editType['is_paid']) || $editType['is_paid']) ? 'checked' : '' ?>>
                                <span>Paid Leave</span>
                            </label>
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="submit" class="btn-submit"><?= $editType ? 'Save Changes' : 'Create Category' ?></button>
                        <?php if ($editType): ?>
                            <a href="leave_types.php" class="btn-reset" style="text-align: center; text-decoration: none;">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Existing Leave Categories Card -->
            <div class="card">
                <h3 style="margin-top: 0; color: #2b6cb0;">Configured Time Off Categories</h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category Name</th>
                                <th>Type</th>
                                <th>Active Allocations / Requests</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($leaveTypes)): ?>
                                <?php foreach ($leaveTypes as $lt): ?>
                                    <tr>
                                        <td><?= $lt['leave_type_id'] ?></td>
                                        <td><strong><?= htmlspecialchars($lt['type_name']) ?></strong></td>
                                        <td>
                                            <?php if ($lt['is_paid']): ?>
                                                <span class="badge badge-active">Paid</span>
                                            <?php else: ?>
                                                <span class="badge badge-manager" style="background: #edf2f7; color: #4a5568;">Unpaid</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small style="color: #718096;">
                                                <?= $lt['balance_count'] ?> balance entries / <?= $lt['request_count'] ?> requests
                                            </small>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                <a href="leave_types.php?edit_id=<?= $lt['leave_type_id'] ?>" class="btn-reset" style="font-size: 0.8rem; padding: 0.25rem 0.6rem; text-decoration: none;">Edit</a>
                                                <form method="POST" action="leave_types.php" onsubmit="return confirm('Are you sure you want to delete this leave category?');" style="margin: 0;">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="leave_type_id" value="<?= $lt['leave_type_id'] ?>">
                                                    <button type="submit" style="background: #e53e3e; color: white; border: none; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer;">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: #a0aec0;">No time off categories configured yet.</td>
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
