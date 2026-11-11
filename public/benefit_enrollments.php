<?php
// public/benefit_enrollments.php - Employee Benefit Enrollments Management
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

// Handle Enrollment Creation, Update, or Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action                = $_POST['action'] ?? '';
    $enrollmentId          = filter_input(INPUT_POST, 'enrollment_id', FILTER_VALIDATE_INT);
    $employeeId            = filter_input(INPUT_POST, 'employee_id', FILTER_VALIDATE_INT);
    $planId                = filter_input(INPUT_POST, 'plan_id', FILTER_VALIDATE_INT);
    $coverageTier          = $_POST['coverage_tier'] ?? 'individual';
    $employeeContribution = (float)($_POST['employee_contribution'] ?? 0.00);
    $employerContribution = (float)($_POST['employer_contribution'] ?? 0.00);
    $startDate             = $_POST['start_date'] ?? date('Y-m-d');
    $endDate               = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

    if ($action === 'delete' && $enrollmentId) {
        try {
            $stmt = $pdo->prepare('DELETE FROM benefit_enrollments WHERE enrollment_id = :id');
            $stmt->execute(['id' => $enrollmentId]);
            $success = 'Benefit enrollment record deleted successfully!';
        } catch (Exception $e) {
            $error = 'Failed to delete enrollment: ' . $e->getMessage();
        }
    } elseif ($action === 'update' && $enrollmentId) {
        if (!$employeeId || !$planId || empty($startDate)) {
            $error = 'Employee, benefit plan, and start date are required.';
        } else {
            try {
                $stmt = $pdo->prepare('
                    UPDATE benefit_enrollments 
                    SET employee_id = :emp_id, plan_id = :plan_id, coverage_tier = :tier, 
                        employee_contribution = :emp_contrib, employer_contribution = :empr_contrib, 
                        start_date = :start_date, end_date = :end_date
                    WHERE enrollment_id = :id
                ');
                $stmt->execute([
                    'emp_id'       => $employeeId,
                    'plan_id'      => $planId,
                    'tier'         => $coverageTier,
                    'emp_contrib'  => $employeeContribution,
                    'empr_contrib' => $employerContribution,
                    'start_date'   => $startDate,
                    'end_date'     => $endDate,
                    'id'           => $enrollmentId
                ]);
                $success = 'Enrollment updated successfully!';
            } catch (Exception $e) {
                $error = 'Failed to update enrollment: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'create') {
        if (!$employeeId || !$planId || empty($startDate)) {
            $error = 'Employee, benefit plan, and start date are required.';
        } else {
            try {
                $stmt = $pdo->prepare('
                    INSERT INTO benefit_enrollments (
                        employee_id, plan_id, coverage_tier, employee_contribution, employer_contribution, start_date, end_date
                    ) VALUES (
                        :emp_id, :plan_id, :tier, :emp_contrib, :empr_contrib, :start_date, :end_date
                    )
                ');
                $stmt->execute([
                    'emp_id'       => $employeeId,
                    'plan_id'      => $planId,
                    'tier'         => $coverageTier,
                    'emp_contrib'  => $employeeContribution,
                    'empr_contrib' => $employerContribution,
                    'start_date'   => $startDate,
                    'end_date'     => $endDate
                ]);
                $success = 'Employee enrolled in benefit plan successfully!';
            } catch (Exception $e) {
                $error = 'Failed to save enrollment: ' . $e->getMessage();
            }
        }
    }
}

// Fetch single enrollment record for editing
$editEnr = null;
$editId  = filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT);
if ($editId) {
    $editStmt = $pdo->prepare('SELECT * FROM benefit_enrollments WHERE enrollment_id = :id LIMIT 1');
    $editStmt->execute(['id' => $editId]);
    $editEnr = $editStmt->fetch();
}

$employeesList = $pdo->query('
    SELECT employee_id, first_name, last_name, work_email 
    FROM employees 
    WHERE employment_status = "active" 
    ORDER BY last_name ASC, first_name ASC
')->fetchAll();

$plansList = $pdo->query('
    SELECT plan_id, plan_name, provider_name, plan_type 
    FROM benefit_plans 
    ORDER BY plan_name ASC
')->fetchAll();

$enrollments = $pdo->query('
    SELECT be.*, e.first_name, e.last_name, e.work_email, bp.plan_name, bp.provider_name, bp.plan_type
    FROM benefit_enrollments be
    JOIN employees e ON be.employee_id = e.employee_id
    JOIN benefit_plans bp ON be.plan_id = bp.plan_id
    ORDER BY be.start_date DESC
')->fetchAll();

$pageTitle = 'Employee Benefit Enrollments';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header">
        <div>
            <h2>Employee Benefit Enrollments</h2>
            <p class="subtitle">Enroll employees in medical, dental, and retirement plans and manage contribution splits.</p>
        </div>
    </div>

    <!-- Two-column layout: Left sidebar + Right settings content -->
    <div style="display: flex; gap: 2rem; align-items: flex-start; margin-top: 1rem; width: 100%;">
        
        <!-- LEFT COLUMN: Side Navigation Bar -->
        <div style="width: 260px; min-width: 260px; flex-shrink: 0;">
            <?php require_once __DIR__ . '/../includes/settings_sidebar.php'; ?>
        </div>

        <!-- RIGHT COLUMN: Benefit Enrollments Settings & Table -->
        <div style="flex: 1 1 auto; min-width: 0;">
            <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <!-- New / Edit Enrollment Form Card -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 style="margin-top: 0; color: #2b6cb0;"><?= $editEnr ? 'Edit Benefit Enrollment' : 'Enroll Employee in Plan' ?></h3>
                <form method="POST" action="benefit_enrollments.php">
                    <input type="hidden" name="action" value="<?= $editEnr ? 'update' : 'create' ?>">
                    <?php if ($editEnr): ?>
                        <input type="hidden" name="enrollment_id" value="<?= $editEnr['enrollment_id'] ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="employee_id">Employee *</label>
                            <select id="employee_id" name="employee_id" required>
                                <option value="">-- Select Employee --</option>
                                <?php foreach ($employeesList as $emp): ?>
                                    <option value="<?= $emp['employee_id'] ?>" <?= ($editEnr['employee_id'] ?? '') == $emp['employee_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']) ?> (<?= htmlspecialchars($emp['work_email']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="plan_id">Benefit Plan *</label>
                            <select id="plan_id" name="plan_id" required>
                                <option value="">-- Select Benefit Plan --</option>
                                <?php foreach ($plansList as $p): ?>
                                    <option value="<?= $p['plan_id'] ?>" <?= ($editEnr['plan_id'] ?? '') == $p['plan_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['plan_name']) ?> (<?= htmlspecialchars($p['provider_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="coverage_tier">Coverage Tier *</label>
                        <select id="coverage_tier" name="coverage_tier" required>
                            <option value="individual" <?= ($editEnr['coverage_tier'] ?? 'individual') === 'individual' ? 'selected' : '' ?>>Individual Only</option>
                            <option value="spouse" <?= ($editEnr['coverage_tier'] ?? '') === 'spouse' ? 'selected' : '' ?>>Individual + Spouse</option>
                            <option value="family" <?= ($editEnr['coverage_tier'] ?? '') === 'family' ? 'selected' : '' ?>>Full Family</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="employee_contribution">Employee Cost ($/mo)</label>
                            <input type="number" step="0.01" id="employee_contribution" name="employee_contribution" value="<?= htmlspecialchars($editEnr['employee_contribution'] ?? '0.00') ?>">
                        </div>
                        <div class="form-group">
                            <label for="employer_contribution">Employer Cost ($/mo)</label>
                            <input type="number" step="0.01" id="employer_contribution" name="employer_contribution" value="<?= htmlspecialchars($editEnr['employer_contribution'] ?? '0.00') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="start_date">Enrollment Start *</label>
                            <input type="date" id="start_date" name="start_date" required value="<?= htmlspecialchars($editEnr['start_date'] ?? date('Y-m-d')) ?>">
                        </div>
                        <div class="form-group">
                            <label for="end_date">End Date (Optional)</label>
                            <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($editEnr['end_date'] ?? '') ?>">
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="submit" class="btn-submit"><?= $editEnr ? 'Save Changes' : 'Enroll Employee' ?></button>
                        <?php if ($editEnr): ?>
                            <a href="benefit_enrollments.php" class="btn-reset" style="text-align: center; text-decoration: none;">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Enrollments Log Table Card -->
            <div class="card">
                <h3 style="margin-top: 0; color: #2b6cb0;">Active & Past Enrollments</h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Plan & Provider</th>
                                <th>Coverage Tier</th>
                                <th>Monthly Cost (Emp / Co)</th>
                                <th>Effective Dates</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($enrollments)): ?>
                                <?php foreach ($enrollments as $enr): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($enr['first_name'] . ' ' . $enr['last_name']) ?></strong>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($enr['plan_name']) ?></strong><br>
                                            <small style="color: #718096;"><?= htmlspecialchars($enr['provider_name']) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars(ucwords($enr['coverage_tier'])) ?></td>
                                        <td>
                                            $<?= number_format($enr['employee_contribution'], 2) ?> / 
                                            $<?= number_format($enr['employer_contribution'], 2) ?>
                                        </td>
                                        <td>
                                            <?= date('M j, Y', strtotime($enr['start_date'])) ?> – 
                                            <?= $enr['end_date'] ? date('M j, Y', strtotime($enr['end_date'])) : 'Present' ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                <a href="benefit_enrollments.php?edit_id=<?= $enr['enrollment_id'] ?>" class="btn-reset" style="font-size: 0.8rem; padding: 0.25rem 0.6rem; text-decoration: none;">Edit</a>
                                                <form method="POST" action="benefit_enrollments.php" onsubmit="return confirm('Delete this enrollment record?');" style="margin: 0;">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="enrollment_id" value="<?= $enr['enrollment_id'] ?>">
                                                    <button type="submit" style="background: #e53e3e; color: white; border: none; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer;">Remove</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: #a0aec0;">No active benefit enrollments recorded.</td>
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
