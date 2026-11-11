<?php
// public/employees.php - Admin Staff Directory with Complete Employee & User Account Deletion Safeguard
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

if (!hasRole('admin')) {
    http_response_code(403);
    $pageTitle = 'Access Denied';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container"><div class="alert-danger"><strong>Access Denied:</strong> You do not have permission to view the Staff Directory.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$error = '';
$success = '';

// Handle Employee & User Account Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $empId = filter_input(INPUT_POST, 'employee_id', FILTER_VALIDATE_INT);
    if ($empId) {
        // Fetch target employee and user account details
        $stmt = $pdo->prepare('SELECT user_id FROM employees WHERE employee_id = :id LIMIT 1');
        $stmt->execute(['id' => $empId]);
        $targetEmp = $stmt->fetch();
        $targetUserId = $targetEmp['user_id'] ?? null;

        // Safeguard Rule: Prevent current logged-in user from deleting their own record or user account
        if ($empId == $_SESSION['employee_id'] || ($targetUserId && $targetUserId == $_SESSION['user_id'])) {
            $error = 'Action denied: You cannot delete your own employee record or login account while signed in.';
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Delete the employee profile record
                $delEmp = $pdo->prepare('DELETE FROM employees WHERE employee_id = :id');
                $delEmp->execute(['id' => $empId]);

                // 2. Delete the user login account (and its RBAC roles via FK cascade)
                if ($targetUserId) {
                    $delUser = $pdo->prepare('DELETE FROM users WHERE user_id = :user_id');
                    $delUser->execute(['user_id' => $targetUserId]);
                }

                $pdo->commit();
                $success = 'Employee record and user account deleted successfully!';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Failed to delete employee: ' . $e->getMessage();
            }
        }
    }
}

$search       = trim($_GET['search'] ?? '');
$filterDept   = trim($_GET['department_id'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');

$deptStmt = $pdo->query('SELECT department_id, department_name FROM departments ORDER BY department_name ASC');
$departments = $deptStmt->fetchAll();

$sql = '
    SELECT 
        e.employee_id, e.user_id, e.first_name, e.last_name, e.work_email, e.phone_number,
        e.hire_date, e.employment_status, e.employment_type,
        d.department_name, jp.job_title,
        CONCAT(m.first_name, " ", m.last_name) AS manager_name,
        u.username
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN job_positions jp ON e.position_id = jp.position_id
    LEFT JOIN employees m ON e.manager_id = m.employee_id
    LEFT JOIN users u ON e.user_id = u.user_id
    WHERE 1=1
';

$params = [];

if (!empty($search)) {
    $sql .= ' AND (e.first_name LIKE :search OR e.last_name LIKE :search OR e.work_email LIKE :search OR u.username LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

if (!empty($filterDept)) {
    $sql .= ' AND e.department_id = :dept_id';
    $params['dept_id'] = $filterDept;
}

if (!empty($filterStatus)) {
    $sql .= ' AND e.employment_status = :status';
    $params['status'] = $filterStatus;
}

$sql .= ' ORDER BY e.last_name ASC, e.first_name ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employeesList = $stmt->fetchAll();

$pageTitle = 'Staff Directory (Admin)';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header">
        <div>
            <h2>Staff Directory</h2>
            <p class="subtitle">System-wide employee records and organizational directory.</p>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <a href="add_employee.php" class="btn-submit" style="text-decoration: none; padding: 0.5rem 1rem;">+ Add Employee</a>
            <span class="badge badge-admin">Admin Privileged Area</span>
        </div>
    </div>

    <?php if ($error): ?><div class="alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="card filter-card">
        <form method="GET" action="employees.php" class="filter-form">
            <div class="form-group">
                <label for="search">Search Employee</label>
                <input type="text" id="search" name="search" placeholder="Name, Email, or Username..." value="<?= htmlspecialchars($search) ?>">
            </div>
            
            <div class="form-group">
                <label for="department_id">Department</label>
                <select id="department_id" name="department_id">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['department_id'] ?>" <?= $filterDept == $dept['department_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="status">Employment Status</label>
                <select id="status" name="status">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="on_leave" <?= $filterStatus === 'on_leave' ? 'selected' : '' ?>>On Leave</option>
                    <option value="terminated" <?= $filterStatus === 'terminated' ? 'selected' : '' ?>>Terminated</option>
                </select>
            </div>

            <div class="form-group filter-actions">
                <button type="submit" class="btn-submit">Filter</button>
                <a href="employees.php" class="btn-reset">Reset</a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Employee</th>
                        <th>Title & Department</th>
                        <th>Contact</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($employeesList)): ?>
                        <?php foreach ($employeesList as $emp): 
                            $isSelf = ($emp['employee_id'] == $_SESSION['employee_id'] || ($emp['user_id'] && $emp['user_id'] == $_SESSION['user_id']));
                        ?>
                            <tr>
                                <td>#<?= htmlspecialchars($emp['employee_id']) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?></strong>
                                    <?php if ($isSelf): ?>
                                        <span class="badge badge-active" style="font-size: 0.7rem; margin-left: 0.25rem;">You</span>
                                    <?php endif; ?>
                                    <?php if ($emp['username']): ?>
                                        <br><small class="text-muted">@<?= htmlspecialchars($emp['username']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($emp['job_title'] ?? 'Unassigned Title') ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($emp['department_name'] ?? 'Unassigned Dept') ?></small>
                                </td>
                                <td>
                                    <a href="mailto:<?= htmlspecialchars($emp['work_email']) ?>"><?= htmlspecialchars($emp['work_email']) ?></a>
                                    <?php if ($emp['phone_number']): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($emp['phone_number']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $emp['employment_type']))) ?></td>
                                <td>
                                    <?php
                                    $statusClass = 'badge-active';
                                    if ($emp['employment_status'] === 'on_leave') $statusClass = 'badge-leave';
                                    if ($emp['employment_status'] === 'terminated') $statusClass = 'badge-terminated';
                                    ?>
                                    <span class="badge <?= $statusClass ?>">
                                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $emp['employment_status']))) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="edit_employee.php?id=<?= $emp['employee_id'] ?>" class="btn-edit">Edit</a>
                                        
                                        <?php if ($isSelf): ?>
                                            <button class="btn-delete" disabled style="opacity: 0.5; cursor: not-allowed;" title="You cannot delete your own account">Delete</button>
                                        <?php else: ?>
                                            <form method="POST" action="employees.php" onsubmit="return confirm('Are you sure you want to delete this employee record and their user account?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="employee_id" value="<?= $emp['employee_id'] ?>">
                                                <button type="submit" class="btn-delete">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: #a0aec0; padding: 2rem;">
                                No employee records matching criteria.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
