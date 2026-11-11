<?php
// public/edit_employee.php - Master Employee Profile, RBAC Roles, Benefits & Salary Management
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

if (!hasRole('admin') && !hasRole('hr_specialist') && !hasRole('manager')) {
    http_response_code(403);
    $pageTitle = 'Access Denied';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container"><div class="alert-danger"><strong>Access Denied:</strong> You do not have permission to edit employee records.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$employeeId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$employeeId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $employeeId = filter_input(INPUT_POST, 'employee_id', FILTER_VALIDATE_INT);
}

if (!$employeeId) {
    header('Location: employees.php');
    exit;
}

$error   = '';
$success = '';

// Fetch Dropdown Lookups
$departments = $pdo->query('SELECT department_id, department_name FROM departments ORDER BY department_name ASC')->fetchAll();
$positions   = $pdo->query('SELECT position_id, job_title, min_salary, max_salary FROM job_positions ORDER BY job_title ASC')->fetchAll();
$locations   = $pdo->query('SELECT location_id, location_name FROM locations WHERE is_active = TRUE ORDER BY location_name ASC')->fetchAll();
$plansList   = $pdo->query('SELECT plan_id, plan_name, provider_name FROM benefit_plans ORDER BY plan_name ASC')->fetchAll();
$payTypes    = $pdo->query('SELECT pay_type_id, type_name FROM pay_types ORDER BY pay_type_id ASC')->fetchAll();
$allRoles    = $pdo->query('SELECT role_id, role_name, description FROM roles ORDER BY role_id ASC')->fetchAll();

$mgrStmt = $pdo->prepare('SELECT employee_id, first_name, last_name FROM employees WHERE employee_id != :id AND employment_status != "terminated" ORDER BY last_name ASC');
$mgrStmt->execute(['id' => $employeeId]);
$managers = $mgrStmt->fetchAll();

// Handle All Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update_profile';

    if ($action === 'update_profile') {
        // 1. Personal & Employment Data
        $firstName        = trim($_POST['first_name'] ?? '');
        $lastName         = trim($_POST['last_name'] ?? '');
        $workEmail        = trim($_POST['work_email'] ?? '');
        $phoneNumber      = trim($_POST['phone_number'] ?? '');
        $departmentId     = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $positionId       = !empty($_POST['position_id']) ? (int)$_POST['position_id'] : null;
        $managerId        = !empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null;
        $locationId       = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
        $employmentStatus = $_POST['employment_status'] ?? 'active';
        $employmentType   = $_POST['employment_type'] ?? 'full_time';
        $hireDate         = $_POST['hire_date'] ?? '';
        $terminationDate  = !empty($_POST['termination_date']) ? $_POST['termination_date'] : null;

        // 2. User Account Data
        $username      = trim($_POST['username'] ?? '');
        $newPassword   = $_POST['new_password'] ?? '';
        $isActiveAcc   = ($_POST['is_active_account'] ?? '0') === '1';
        $selectedRoles = $_POST['roles'] ?? [];

        // 3. Benefit Enrollment Data
        $selectedPlanId        = !empty($_POST['plan_id']) ? (int)$_POST['plan_id'] : null;
        $coverageTier          = $_POST['coverage_tier'] ?? 'individual';
        $employeeContribution = (float)($_POST['employee_contribution'] ?? 0.00);
        $employerContribution = (float)($_POST['employer_contribution'] ?? 0.00);

        if (empty($firstName) || empty($lastName) || empty($workEmail) || empty($hireDate)) {
            $error = 'First Name, Last Name, Work Email, and Hire Date are required.';
        } elseif (!filter_var($workEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please provide a valid work email address.';
        } else {
            try {
                $pdo->beginTransaction();

                // Update Employee Details
                $updateEmpStmt = $pdo->prepare('
                    UPDATE employees SET
                        first_name = :first_name, last_name = :last_name, work_email = :work_email,
                        phone_number = :phone_number, department_id = :department_id, position_id = :position_id,
                        manager_id = :manager_id, location_id = :location_id, employment_status = :employment_status, 
                        employment_type = :employment_type, hire_date = :hire_date, termination_date = :termination_date
                    WHERE employee_id = :employee_id
                ');
                $updateEmpStmt->execute([
                    'first_name'        => $firstName,
                    'last_name'         => $lastName,
                    'work_email'        => $workEmail,
                    'phone_number'      => $phoneNumber,
                    'department_id'     => $departmentId,
                    'position_id'       => $positionId,
                    'manager_id'        => $managerId,
                    'location_id'       => $locationId,
                    'employment_status' => $employmentStatus,
                    'employment_type'   => $employmentType,
                    'hire_date'         => $hireDate,
                    'termination_date'  => $terminationDate,
                    'employee_id'       => $employeeId
                ]);

                // Update User Account & Roles if linked
                $userIdStmt = $pdo->prepare('SELECT user_id FROM employees WHERE employee_id = :id LIMIT 1');
                $userIdStmt->execute(['id' => $employeeId]);
                $linkedUserId = $userIdStmt->fetchColumn();

                if ($linkedUserId) {
                    if (!empty($username)) {
                        $upUserStmt = $pdo->prepare('UPDATE users SET username = :u, is_active = :act WHERE user_id = :uid');
                        $upUserStmt->execute(['u' => $username, 'act' => $isActiveAcc ? 1 : 0, 'uid' => $linkedUserId]);
                    }

                    if (!empty($newPassword)) {
                        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
                        $passStmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :uid');
                        $passStmt->execute(['hash' => $newHash, 'uid' => $linkedUserId]);
                    }

                    // Update User Roles
                    $delRoles = $pdo->prepare('DELETE FROM user_account_roles WHERE user_id = :uid');
                    $delRoles->execute(['uid' => $linkedUserId]);

                    if (!empty($selectedRoles)) {
                        $insRole = $pdo->prepare('INSERT INTO user_account_roles (user_id, role_id) VALUES (:uid, :rid)');
                        foreach ($selectedRoles as $rId) {
                            $insRole->execute(['uid' => $linkedUserId, 'rid' => (int)$rId]);
                        }
                    }
                }

                // Add Benefit Plan Enrollment
                if ($selectedPlanId) {
                    $addBenStmt = $pdo->prepare('
                        INSERT INTO benefit_enrollments (employee_id, plan_id, coverage_tier, employee_contribution, employer_contribution, start_date)
                        VALUES (:emp_id, :plan_id, :tier, :emp_cost, :empr_cost, CURRENT_DATE())
                    ');
                    $addBenStmt->execute([
                        'emp_id'    => $employeeId,
                        'plan_id'   => $selectedPlanId,
                        'tier'      => $coverageTier,
                        'emp_cost'  => $employeeContribution,
                        'empr_cost' => $employerContribution
                    ]);
                }

                $pdo->commit();
                $success = 'Employee record and user profile updated successfully!';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = 'Failed to update employee profile: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_salary') {
        // Handle Salary / Compensation History Update
        $payTypeId     = filter_input(INPUT_POST, 'pay_type_id', FILTER_VALIDATE_INT);
        $payRate       = (float)($_POST['pay_rate'] ?? 0);
        $payFrequency  = $_POST['pay_frequency'] ?? 'bi_weekly';
        $commissionPct = (float)($_POST['commission_percentage'] ?? 0.00);
        $effectiveDate = $_POST['effective_date'] ?? date('Y-m-d');

        if (!$payTypeId || $payRate <= 0 || empty($effectiveDate)) {
            $error = 'Please enter a valid pay rate, pay model, and effective date.';
        } else {
            try {
                $pdo->beginTransaction();

                // Expire active rate
                $previousEndDate = date('Y-m-d', strtotime('-1 day', strtotime($effectiveDate)));
                $expireStmt = $pdo->prepare('
                    UPDATE compensation_history 
                    SET end_date = :prev_end 
                    WHERE employee_id = :emp_id AND (end_date IS NULL OR end_date >= :effective_date)
                ');
                $expireStmt->execute(['prev_end' => $previousEndDate, 'emp_id' => $employeeId, 'effective_date' => $effectiveDate]);

                // Insert new rate
                $insStmt = $pdo->prepare('
                    INSERT INTO compensation_history 
                    (employee_id, pay_type_id, pay_rate, pay_frequency, commission_percentage, effective_date, end_date)
                    VALUES (:emp_id, :pay_type, :rate, :freq, :comm, :eff_date, NULL)
                ');
                $insStmt->execute([
                    'emp_id'   => $employeeId,
                    'pay_type' => $payTypeId,
                    'rate'     => $payRate,
                    'freq'     => $payFrequency,
                    'comm'     => $commissionPct,
                    'eff_date' => $effectiveDate
                ]);

                $pdo->commit();
                $success = 'Employee salary and compensation updated successfully!';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = 'Failed to update salary: ' . $e->getMessage();
            }
        }
    }
}

// Fetch Current Employee & User Record
$empStmt = $pdo->prepare('
    SELECT e.*, u.username, u.is_active AS user_active, jp.job_title, jp.min_salary, jp.max_salary 
    FROM employees e
    LEFT JOIN users u ON e.user_id = u.user_id
    LEFT JOIN job_positions jp ON e.position_id = jp.position_id
    WHERE e.employee_id = :id LIMIT 1
');
$empStmt->execute(['id' => $employeeId]);
$emp = $empStmt->fetch();

if (!$emp) {
    header('Location: employees.php');
    exit;
}

// Fetch User Assigned Roles
$assignedRoles = [];
if ($emp['user_id']) {
    $roleStmt = $pdo->prepare('SELECT role_id FROM user_account_roles WHERE user_id = :uid');
    $roleStmt->execute(['uid' => $emp['user_id']]);
    $assignedRoles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Fetch Active Compensation
$currentCompStmt = $pdo->prepare('
    SELECT ch.*, pt.type_name
    FROM compensation_history ch
    JOIN pay_types pt ON ch.pay_type_id = pt.pay_type_id
    WHERE ch.employee_id = :emp_id AND (ch.end_date IS NULL OR ch.end_date >= CURRENT_DATE())
    ORDER BY ch.effective_date DESC LIMIT 1
');
$currentCompStmt->execute(['emp_id' => $employeeId]);
$currentComp = $currentCompStmt->fetch();

// Fetch Compensation Audit Trail
$compHistoryStmt = $pdo->prepare('
    SELECT ch.*, pt.type_name
    FROM compensation_history ch
    JOIN pay_types pt ON ch.pay_type_id = pt.pay_type_id
    WHERE ch.employee_id = :emp_id
    ORDER BY ch.effective_date DESC
');
$compHistoryStmt->execute(['emp_id' => $employeeId]);
$compHistory = $compHistoryStmt->fetchAll();

// Fetch Active Benefit Enrollments
$benStmt = $pdo->prepare('
    SELECT be.*, bp.plan_name, bp.provider_name 
    FROM benefit_enrollments be
    JOIN benefit_plans bp ON be.plan_id = bp.plan_id
    WHERE be.employee_id = :id AND (be.end_date IS NULL OR be.end_date >= CURRENT_DATE())
');
$benStmt->execute(['id' => $employeeId]);
$activeEnrollments = $benStmt->fetchAll();

$pageTitle = 'Edit Employee Profile #' . $emp['employee_id'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="widescreen-container" style="max-width: 1350px; margin: 0 auto; padding: 0 1.5rem 2rem;">
    <div class="directory-header" style="margin-top: 1.5rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
        <div>
            <h2>Edit Employee: <?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?></h2>
            <p class="subtitle">Manage directory details, user authentication, security roles, benefits, and pay rates.</p>
        </div>
        <div>
            <a href="employees.php" class="btn-reset" style="text-decoration: none; padding: 0.5rem 0.9rem;">← Back to Directory</a>
        </div>
    </div>

    <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr 400px; gap: 1.5rem; align-items: flex-start;">
        
        <!-- LEFT COLUMN: MAIN EMPLOYEE FORM -->
        <div class="card">
            <form method="POST" action="edit_employee.php?id=<?= $employeeId ?>">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">

                <h3 style="margin-top: 0; color: #2b6cb0; border-bottom: 2px solid #edf2f7; padding-bottom: 0.5rem; margin-bottom: 1.25rem;">Personal Information</h3>
                
                <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1; min-width: 200px;">
                        <label for="first_name" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">First Name *</label>
                        <input type="text" id="first_name" name="first_name" required value="<?= htmlspecialchars($emp['first_name']) ?>" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 200px;">
                        <label for="last_name" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" required value="<?= htmlspecialchars($emp['last_name']) ?>" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                    </div>
                </div>

                <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1; min-width: 200px;">
                        <label for="work_email" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Work Email *</label>
                        <input type="email" id="work_email" name="work_email" required value="<?= htmlspecialchars($emp['work_email']) ?>" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 200px;">
                        <label for="phone_number" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Phone Number</label>
                        <input type="text" id="phone_number" name="phone_number" value="<?= htmlspecialchars($emp['phone_number'] ?? '') ?>" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                    </div>
                </div>

                <h3 style="color: #2b6cb0; border-bottom: 2px solid #edf2f7; padding-bottom: 0.5rem; margin-bottom: 1.25rem;">Job & Organization</h3>
                
                <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1; min-width: 200px;">
                        <label for="department_id" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Department</label>
                        <select id="department_id" name="department_id" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['department_id'] ?>" <?= $emp['department_id'] == $d['department_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['department_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 200px;">
                        <label for="position_id" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Job Position</label>
                        <select id="position_id" name="position_id" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($positions as $p): ?>
                                <option value="<?= $p['position_id'] ?>" <?= $emp['position_id'] == $p['position_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['job_title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1; min-width: 200px;">
                        <label for="manager_id" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Reporting Manager</label>
                        <select id="manager_id" name="manager_id" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                            <option value="">-- No Manager --</option>
                            <?php foreach ($managers as $m): ?>
                                <option value="<?= $m['employee_id'] ?>" <?= $emp['manager_id'] == $m['employee_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 200px;">
                        <label for="location_id" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Work Location</label>
                        <select id="location_id" name="location_id" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= $loc['location_id'] ?>" <?= $emp['location_id'] == $loc['location_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($loc['location_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <h3 style="color: #2b6cb0; border-bottom: 2px solid #edf2f7; padding-bottom: 0.5rem; margin-bottom: 1.25rem;">Employment Details</h3>
                
                <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1; min-width: 200px;">
                        <label for="employment_status" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Employment Status *</label>
                        <select id="employment_status" name="employment_status" required style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                            <option value="active" <?= $emp['employment_status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="on_leave" <?= $emp['employment_status'] === 'on_leave' ? 'selected' : '' ?>>On Leave</option>
                            <option value="terminated" <?= $emp['employment_status'] === 'terminated' ? 'selected' : '' ?>>Terminated</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 200px;">
                        <label for="employment_type" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Employment Type *</label>
                        <select id="employment_type" name="employment_type" required style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                            <option value="full_time" <?= $emp['employment_type'] === 'full_time' ? 'selected' : '' ?>>Full Time</option>
                            <option value="part_time" <?= $emp['employment_type'] === 'part_time' ? 'selected' : '' ?>>Part Time</option>
                            <option value="contractor" <?= $emp['employment_type'] === 'contractor' ? 'selected' : '' ?>>Contractor</option>
                        </select>
                    </div>
                </div>

                <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1; min-width: 200px;">
                        <label for="hire_date" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Hire Date *</label>
                        <input type="date" id="hire_date" name="hire_date" required value="<?= htmlspecialchars($emp['hire_date']) ?>" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 200px;">
                        <label for="termination_date" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Termination Date</label>
                        <input type="date" id="termination_date" name="termination_date" value="<?= htmlspecialchars($emp['termination_date'] ?? '') ?>" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                    </div>
                </div>

                <!-- STYLED TOGGLE CARDS: USER ACCOUNT & SECURITY ROLES -->
                <h3 style="color: #2b6cb0; border-bottom: 2px solid #edf2f7; padding-bottom: 0.5rem; margin-bottom: 1.25rem;">User Account & Access Credentials</h3>
                
                <?php if ($emp['user_id']): ?>
                    <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap;">
                        <div class="form-group" style="flex: 1; min-width: 200px;">
                            <label for="username" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Portal Username</label>
                            <input type="text" id="username" name="username" value="<?= htmlspecialchars($emp['username'] ?? '') ?>" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                        </div>
                        <div class="form-group" style="flex: 1; min-width: 200px;">
                            <label for="new_password" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Reset Password (Leave blank to keep)</label>
                            <input type="password" id="new_password" name="new_password" placeholder="••••••••" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                        </div>
                    </div>

                    <!-- User Account Active Card Toggle -->
                    <div style="margin-bottom: 1.5rem;">
                        <label style="font-weight: 600; font-size: 0.85rem; color: #4a5568; margin-bottom: 0.5rem; display: block;">Portal Access Status *</label>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem;">
                            
                            <label style="display: flex; align-items: flex-start; gap: 0.75rem; background: <?= $emp['user_active'] ? '#ebf8ff' : '#f8fafc' ?>; border: 2px solid <?= $emp['user_active'] ? '#3182ce' : '#e2e8f0' ?>; padding: 0.85rem; border-radius: 6px; cursor: pointer;" id="label_acc_active">
                                <input type="radio" name="is_active_account" value="1" <?= $emp['user_active'] ? 'checked' : '' ?> onchange="updateAccountCardStyles()" style="margin-top: 0.2rem;">
                                <div>
                                    <strong style="color: #2b6cb0; font-size: 0.9rem; display: block;">Active Portal Access</strong>
                                    <span style="font-size: 0.8rem; color: #718096; display: block; margin-top: 0.15rem;">Permit employee to log in to time clock, dashboard, and portal.</span>
                                </div>
                            </label>

                            <label style="display: flex; align-items: flex-start; gap: 0.75rem; background: <?= !$emp['user_active'] ? '#ebf8ff' : '#f8fafc' ?>; border: 2px solid <?= !$emp['user_active'] ? '#3182ce' : '#e2e8f0' ?>; padding: 0.85rem; border-radius: 6px; cursor: pointer;" id="label_acc_disabled">
                                <input type="radio" name="is_active_account" value="0" <?= !$emp['user_active'] ? 'checked' : '' ?> onchange="updateAccountCardStyles()" style="margin-top: 0.2rem;">
                                <div>
                                    <strong style="color: #2d3748; font-size: 0.9rem; display: block;">Disabled Account Access</strong>
                                    <span style="font-size: 0.8rem; color: #718096; display: block; margin-top: 0.15rem;">Block portal login while preserving employee directory history.</span>
                                </div>
                            </label>

                        </div>
                    </div>

                    <!-- RBAC Roles Selection Cards -->
                    <div style="margin-bottom: 1.5rem;">
                        <label style="font-weight: 600; font-size: 0.85rem; color: #4a5568; margin-bottom: 0.5rem; display: block;">Assigned RBAC Security Roles</label>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.85rem;">
                            <?php foreach ($allRoles as $role): 
                                $isAssigned = in_array($role['role_id'], $assignedRoles);
                            ?>
                                <label style="display: flex; align-items: flex-start; gap: 0.75rem; background: <?= $isAssigned ? '#ebf8ff' : '#f8fafc' ?>; border: 2px solid <?= $isAssigned ? '#3182ce' : '#e2e8f0' ?>; padding: 0.8rem; border-radius: 6px; cursor: pointer;" id="role_card_<?= $role['role_id'] ?>">
                                    <input type="checkbox" name="roles[]" value="<?= $role['role_id'] ?>" <?= $isAssigned ? 'checked' : '' ?> onchange="updateRoleCardStyle(this, 'role_card_<?= $role['role_id'] ?>')" style="margin-top: 0.2rem;">
                                    <div>
                                        <strong style="color: #2b6cb0; font-size: 0.88rem; display: block; text-transform: capitalize;"><?= htmlspecialchars($role['role_name']) ?></strong>
                                        <span style="font-size: 0.78rem; color: #718096; display: block; margin-top: 0.15rem;"><?= htmlspecialchars($role['description'] ?? 'System role privileges') ?></span>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <p style="color: #718096; font-size: 0.9rem; margin-bottom: 1.5rem;">No user login account is currently linked to this employee profile.</p>
                <?php endif; ?>

                <!-- BENEFITS ENROLLMENT SECTION -->
                <h3 style="color: #2b6cb0; border-bottom: 2px solid #edf2f7; padding-bottom: 0.5rem; margin-bottom: 1.25rem;">Active Benefit Plans</h3>
                
                <?php if (!empty($activeEnrollments)): ?>
                    <table class="table" style="width: 100%; margin-bottom: 1.25rem; font-size: 0.88rem;">
                        <thead>
                            <tr>
                                <th>Plan Details</th>
                                <th>Coverage Tier</th>
                                <th>Employee Cost</th>
                                <th>Employer Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeEnrollments as $enr): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($enr['plan_name']) ?></strong> (<?= htmlspecialchars($enr['provider_name']) ?>)</td>
                                    <td><?= htmlspecialchars(ucwords($enr['coverage_tier'])) ?></td>
                                    <td>$<?= number_format($enr['employee_contribution'], 2) ?>/mo</td>
                                    <td>$<?= number_format($enr['employer_contribution'], 2) ?>/mo</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: #a0aec0; font-size: 0.88rem; margin-bottom: 1.25rem;">No active benefit plan enrollments assigned.</p>
                <?php endif; ?>

                <!-- Add Benefit Plan Card -->
                <div style="background: #f8fafc; padding: 1.25rem; border-radius: 6px; border: 1px solid #e2e8f0; margin-bottom: 1.5rem;">
                    <h4 style="margin-top: 0; color: #2b6cb0; font-size: 0.95rem;">+ Enroll in Additional Benefit Plan</h4>
                    
                    <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap;">
                        <div class="form-group" style="flex: 1; min-width: 200px;">
                            <label for="plan_id" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Benefit Plan</label>
                            <select id="plan_id" name="plan_id" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                                <option value="">-- Select Plan --</option>
                                <?php foreach ($plansList as $plan): ?>
                                    <option value="<?= $plan['plan_id'] ?>">
                                        <?= htmlspecialchars($plan['plan_name']) ?> (<?= htmlspecialchars($plan['provider_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1; min-width: 200px;">
                            <label for="coverage_tier" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Coverage Tier</label>
                            <select id="coverage_tier" name="coverage_tier" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                                <option value="individual">Individual Only</option>
                                <option value="spouse">Individual + Spouse</option>
                                <option value="family">Full Family</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row" style="display: flex; gap: 1rem; flex-wrap: wrap;">
                        <div class="form-group" style="flex: 1; min-width: 200px;">
                            <label for="employee_contribution" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Employee Monthly ($)</label>
                            <input type="number" step="0.01" id="employee_contribution" name="employee_contribution" value="0.00" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                        </div>
                        <div class="form-group" style="flex: 1; min-width: 200px;">
                            <label for="employer_contribution" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Employer Monthly ($)</label>
                            <input type="number" step="0.01" id="employer_contribution" name="employer_contribution" value="0.00" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                        </div>
                    </div>
                </div>

                <div style="margin-top: 1.5rem; display: flex; gap: 1rem;">
                    <button type="submit" class="btn-submit" style="flex: 1; padding: 0.75rem;">Save All Profile Changes</button>
                    <a href="employees.php" class="btn-reset" style="text-align: center; padding: 0.75rem; text-decoration: none;">Cancel</a>
                </div>
            </form>
        </div>

        <!-- RIGHT COLUMN: SALARY & WAGE MANAGEMENT -->
        <div>
            <!-- Current Salary Card -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 style="margin-top: 0; color: #2b6cb0; border-bottom: 2px solid #edf2f7; padding-bottom: 0.5rem;">Current Compensation</h3>
                
                <div style="margin-top: 1rem;">
                    <small style="color: #718096; font-weight: 600; display: block; text-transform: uppercase; font-size: 0.75rem;">Pay Rate / Salary</small>
                    <div style="font-size: 1.8rem; font-weight: 700; color: #2b6cb0; margin-top: 0.2rem;">
                        <?php if ($currentComp): ?>
                            $<?= number_format($currentComp['pay_rate'], 2) ?>
                            <span style="font-size: 0.85rem; color: #4a5568; font-weight: 400;">
                                / <?= strtolower(str_replace('_', '-', $currentComp['pay_frequency'])) ?>
                            </span>
                        <?php else: ?>
                            <span style="color: #e53e3e; font-size: 1.1rem;">Not Set</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="margin-top: 0.85rem;">
                    <small style="color: #718096; font-weight: 600; display: block; text-transform: uppercase; font-size: 0.75rem;">Pay Model</small>
                    <div style="font-size: 0.95rem; font-weight: 600; color: #2d3748; margin-top: 0.1rem;">
                        <?= htmlspecialchars($currentComp['type_name'] ?? 'Unassigned') ?>
                    </div>
                </div>
            </div>

            <!-- Adjust Salary Form Card -->
            <?php if (hasRole('admin') || hasRole('hr_specialist')): ?>
                <div class="card" style="margin-bottom: 1.5rem;">
                    <h3 style="margin-top: 0; color: #2b6cb0; border-bottom: 2px solid #edf2f7; padding-bottom: 0.5rem;">Adjust Compensation Rate</h3>
                    
                    <form method="POST" action="edit_employee.php?id=<?= $employeeId ?>">
                        <input type="hidden" name="action" value="update_salary">

                        <div class="form-group" style="margin-bottom: 0.85rem;">
                            <label for="pay_type_id" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Pay Model *</label>
                            <select id="pay_type_id" name="pay_type_id" required style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                                <option value="">-- Select Model --</option>
                                <?php foreach ($payTypes as $pt): ?>
                                    <option value="<?= $pt['pay_type_id'] ?>" <?= ($currentComp && $currentComp['pay_type_id'] == $pt['pay_type_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($pt['type_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom: 0.85rem;">
                            <label for="pay_rate" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">New Rate / Salary ($) *</label>
                            <input type="number" step="0.01" min="0.01" id="pay_rate" name="pay_rate" required placeholder="e.g. 75000.00" value="<?= htmlspecialchars($currentComp['pay_rate'] ?? '') ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                        </div>

                        <div class="form-group" style="margin-bottom: 0.85rem;">
                            <label for="pay_frequency" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Pay Frequency *</label>
                            <select id="pay_frequency" name="pay_frequency" required style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                                <option value="hourly" <?= ($currentComp && $currentComp['pay_frequency'] === 'hourly') ? 'selected' : '' ?>>Hourly</option>
                                <option value="weekly" <?= ($currentComp && $currentComp['pay_frequency'] === 'weekly') ? 'selected' : '' ?>>Weekly</option>
                                <option value="bi_weekly" <?= ($currentComp && $currentComp['pay_frequency'] === 'bi_weekly') ? 'selected' : '' ?>>Bi-Weekly</option>
                                <option value="semi_monthly" <?= ($currentComp && $currentComp['pay_frequency'] === 'semi_monthly') ? 'selected' : '' ?>>Semi-Monthly</option>
                                <option value="monthly" <?= ($currentComp && $currentComp['pay_frequency'] === 'monthly') ? 'selected' : '' ?>>Monthly</option>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom: 1.25rem;">
                            <label for="effective_date" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Effective Date *</label>
                            <input type="date" id="effective_date" name="effective_date" required value="<?= date('Y-m-d') ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                        </div>

                        <button type="submit" class="btn-submit" style="width: 100%;">Save Compensation Adjustment</button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Historical Compensation Audit Log -->
            <div class="card">
                <h3 style="margin-top: 0; color: #2b6cb0; border-bottom: 2px solid #edf2f7; padding-bottom: 0.5rem;">Salary Audit Trail</h3>
                <?php if (!empty($compHistory)): ?>
                    <div class="table-responsive">
                        <table class="table" style="width: 100%; font-size: 0.82rem;">
                            <thead>
                                <tr>
                                    <th>Effective</th>
                                    <th>Model</th>
                                    <th>Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($compHistory as $h): ?>
                                    <tr>
                                        <td><?= date('M j, Y', strtotime($h['effective_date'])) ?></td>
                                        <td><?= htmlspecialchars($h['type_name']) ?></td>
                                        <td><strong>$<?= number_format($h['pay_rate'], 2) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="color: #a0aec0; padding: 0.5rem 0; font-size: 0.85rem;">No historical records found.</p>
                <?php endif; ?>
            </div>

        </div>

    </div>
</div>

<script>
function updateAccountCardStyles() {
    const isActive = document.querySelector('input[name="is_active_account"][value="1"]').checked;
    const activeLabel   = document.getElementById('label_acc_active');
    const disabledLabel = document.getElementById('label_acc_disabled');

    if (activeLabel && disabledLabel) {
        activeLabel.style.borderColor   = isActive ? '#3182ce' : '#e2e8f0';
        activeLabel.style.background    = isActive ? '#ebf8ff' : '#f8fafc';
        disabledLabel.style.borderColor = !isActive ? '#3182ce' : '#e2e8f0';
        disabledLabel.style.background  = !isActive ? '#ebf8ff' : '#f8fafc';
    }
}

function updateRoleCardStyle(checkbox, containerId) {
    const card = document.getElementById(containerId);
    if (card) {
        card.style.borderColor = checkbox.checked ? '#3182ce' : '#e2e8f0';
        card.style.background  = checkbox.checked ? '#ebf8ff' : '#f8fafc';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
