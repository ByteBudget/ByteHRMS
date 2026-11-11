<?php
// public/leave_balances.php - Admin Management of Employee PTO & Leave Allocations
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

// Handle Form Submissions (Assign / Update / Delete Leave Balance)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action         = $_POST['action'] ?? '';
    $balanceId      = filter_input(INPUT_POST, 'balance_id', FILTER_VALIDATE_INT);
    $employeeId     = filter_input(INPUT_POST, 'employee_id', FILTER_VALIDATE_INT);
    $leaveTypeId    = filter_input(INPUT_POST, 'leave_type_id', FILTER_VALIDATE_INT);
    $fiscalYear     = filter_input(INPUT_POST, 'fiscal_year', FILTER_VALIDATE_INT) ?? (int)date('Y');
    $totalAllocated = (float)($_POST['total_allocated'] ?? 0.00);
    $carryoverHours = (float)($_POST['carryover_hours'] ?? 0.00);
    $usedHours      = (float)($_POST['used_hours'] ?? 0.00);

    if ($action === 'delete' && $balanceId) {
        try {
            $stmt = $pdo->prepare('DELETE FROM leave_balances WHERE balance_id = :id');
            $stmt->execute(['id' => $balanceId]);
            $success = 'Employee leave balance entry deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete record: ' . $e->getMessage();
        }
    } elseif ($action === 'update' && $balanceId) {
        try {
            $stmt = $pdo->prepare('
                UPDATE leave_balances 
                SET total_allocated = :allocated, carryover_hours = :carryover, used_hours = :used
                WHERE balance_id = :id
            ');
            $stmt->execute([
                'allocated' => $totalAllocated,
                'carryover' => $carryoverHours,
                'used'      => $usedHours,
                'id'        => $balanceId
            ]);
            $success = 'Leave allocation updated successfully.';
        } catch (Exception $e) {
            $error = 'Failed to update balance: ' . $e->getMessage();
        }
    } elseif ($action === 'create') {
        if (!$employeeId || !$leaveTypeId || !$fiscalYear) {
            $error = 'Employee, leave type, and fiscal year are required.';
        } else {
            try {
                // Upsert on unique key (employee_id, leave_type_id, fiscal_year)
                $stmt = $pdo->prepare('
                    INSERT INTO leave_balances (employee_id, leave_type_id, fiscal_year, total_allocated, carryover_hours, used_hours)
                    VALUES (:emp_id, :type_id, :year, :allocated, :carryover, :used)
                    ON DUPLICATE KEY UPDATE 
                        total_allocated = VALUES(total_allocated),
                        carryover_hours = VALUES(carryover_hours),
                        used_hours = VALUES(used_hours)
                ');
                $stmt->execute([
                    'emp_id'    => $employeeId,
                    'type_id'   => $leaveTypeId,
                    'year'      => $fiscalYear,
                    'allocated' => $totalAllocated,
                    'carryover' => $carryoverHours,
                    'used'      => $usedHours
                ]);
                $success = 'Leave balance allocated successfully.';
            } catch (Exception $e) {
                $error = 'Failed to save balance: ' . $e->getMessage();
            }
        }
    }
}

// Fetch record for editing
$editBal = null;
$editId  = filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT);
if ($editId) {
    $editStmt = $pdo->prepare('SELECT * FROM leave_balances WHERE balance_id = :id LIMIT 1');
    $editStmt->execute(['id' => $editId]);
    $editBal = $editStmt->fetch();
}

// Dropdown lookups
$employees = $pdo->query('
    SELECT employee_id, first_name, last_name, work_email 
    FROM employees 
    WHERE employment_status = "active" 
    ORDER BY last_name ASC, first_name ASC
')->fetchAll();

$leaveTypes = $pdo->query('SELECT * FROM leave_types ORDER BY type_name ASC')->fetchAll();

// Year Filter
$filterYear = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? (int)date('Y');

// Query active allocations
$balStmt = $pdo->prepare('
    SELECT lb.*, e.first_name, e.last_name, e.work_email, lt.type_name, lt.is_paid
    FROM leave_balances lb
    JOIN employees e ON lb.employee_id = e.employee_id
    JOIN leave_types lt ON lb.leave_type_id = lt.leave_type_id
    WHERE lb.fiscal_year = :year
    ORDER BY e.last_name ASC, lt.type_name ASC
');
$balStmt->execute(['year' => $filterYear]);
$balances = $balStmt->fetchAll();

$pageTitle = 'Employee Leave Allocations';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header">
        <div>
            <h2>Employee PTO & Leave Allocations</h2>
            <p class="subtitle">Assign annual PTO hours, track carryover, and manage individual leave balances.</p>
        </div>
    </div>

    <!-- Two-column layout: Left sidebar + Right settings content -->
    <div style="display: flex; gap: 2rem; align-items: flex-start; margin-top: 1rem; width: 100%;">
        
        <!-- LEFT COLUMN: Side Navigation Bar -->
        <div style="width: 260px; min-width: 260px; flex-shrink: 0;">
            <?php require_once __DIR__ . '/../includes/settings_sidebar.php'; ?>
        </div>

        <!-- RIGHT COLUMN: Leave Balances Management & Table -->
        <div style="flex: 1 1 auto; min-width: 0;">
            <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <!-- Add / Edit Leave Allocation Form Card -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 style="margin-top: 0; color: #2b6cb0;"><?= $editBal ? 'Edit Leave Allocation' : 'Allocate Leave to Employee' ?></h3>
                <form method="POST" action="leave_balances.php?year=<?= $filterYear ?>">
                    <input type="hidden" name="action" value="<?= $editBal ? 'update' : 'create' ?>">
                    <?php if ($editBal): ?>
                        <input type="hidden" name="balance_id" value="<?= $editBal['balance_id'] ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="employee_id">Employee *</label>
                            <select id="employee_id" name="employee_id" required <?= $editBal ? 'disabled' : '' ?>>
                                <option value="">-- Select Employee --</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= $emp['employee_id'] ?>" <?= ($editBal['employee_id'] ?? '') == $emp['employee_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']) ?> (<?= htmlspecialchars($emp['work_email']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($editBal): ?>
                                <input type="hidden" name="employee_id" value="<?= $editBal['employee_id'] ?>">
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="leave_type_id">Leave Category *</label>
                            <select id="leave_type_id" name="leave_type_id" required <?= $editBal ? 'disabled' : '' ?>>
                                <option value="">-- Select Category --</option>
                                <?php foreach ($leaveTypes as $lt): ?>
                                    <option value="<?= $lt['leave_type_id'] ?>" <?= ($editBal['leave_type_id'] ?? '') == $lt['leave_type_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($lt['type_name']) ?> <?= $lt['is_paid'] ? '(Paid)' : '(Unpaid)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($editBal): ?>
                                <input type="hidden" name="leave_type_id" value="<?= $editBal['leave_type_id'] ?>">
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="fiscal_year">Fiscal Year *</label>
                            <input type="number" id="fiscal_year" name="fiscal_year" required value="<?= htmlspecialchars($editBal['fiscal_year'] ?? $filterYear) ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="total_allocated">Annual Allocated Hours *</label>
                            <input type="number" step="0.5" id="total_allocated" name="total_allocated" required value="<?= htmlspecialchars($editBal['total_allocated'] ?? '80.00') ?>">
                        </div>
                        <div class="form-group">
                            <label for="carryover_hours">Carryover Hours</label>
                            <input type="number" step="0.5" id="carryover_hours" name="carryover_hours" value="<?= htmlspecialchars($editBal['carryover_hours'] ?? '0.00') ?>">
                        </div>
                        <div class="form-group">
                            <label for="used_hours">Used Hours</label>
                            <input type="number" step="0.5" id="used_hours" name="used_hours" value="<?= htmlspecialchars($editBal['used_hours'] ?? '0.00') ?>">
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="submit" class="btn-submit"><?= $editBal ? 'Save Allocation Changes' : 'Assign Balance' ?></button>
                        <?php if ($editBal): ?>
                            <a href="leave_balances.php?year=<?= $filterYear ?>" class="btn-reset" style="text-align: center; text-decoration: none;">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Existing Allocations Table Card -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3 style="margin: 0; color: #2b6cb0;">Allocated Balances (<?= $filterYear ?>)</h3>
                    <form method="GET" action="leave_balances.php" style="margin: 0;">
                        <label for="year" style="font-weight: 600; margin-right: 0.5rem;">Fiscal Year:</label>
                        <select id="year" name="year" onchange="this.form.submit()" style="padding: 0.3rem 0.5rem;">
                            <?php for ($y = date('Y') - 1; $y <= date('Y') + 2; $y++): ?>
                                <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Category</th>
                                <th>Allocated</th>
                                <th>Carryover</th>
                                <th>Used</th>
                                <th>Remaining</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($balances)): ?>
                                <?php foreach ($balances as $b): 
                                    $remaining = ($b['total_allocated'] + $b['carryover_hours']) - $b['used_hours'];
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($b['first_name'] . ' ' . $b['last_name']) ?></strong><br>
                                            <small style="color: #718096;"><?= htmlspecialchars($b['work_email']) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($b['type_name']) ?></td>
                                        <td><?= number_format($b['total_allocated'], 1) ?> hrs</td>
                                        <td><?= number_format($b['carryover_hours'], 1) ?> hrs</td>
                                        <td><?= number_format($b['used_hours'], 1) ?> hrs</td>
                                        <td><strong><?= number_format($remaining, 1) ?> hrs</strong></td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                <a href="leave_balances.php?edit_id=<?= $b['balance_id'] ?>&year=<?= $filterYear ?>" class="btn-reset" style="font-size: 0.8rem; padding: 0.25rem 0.6rem; text-decoration: none;">Edit</a>
                                                <form method="POST" action="leave_balances.php?year=<?= $filterYear ?>" onsubmit="return confirm('Delete this balance record?');" style="margin: 0;">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="balance_id" value="<?= $b['balance_id'] ?>">
                                                    <button type="submit" style="background: #e53e3e; color: white; border: none; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer;">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: #a0aec0;">No leave balances allocated for fiscal year <?= $filterYear ?>.</td>
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
