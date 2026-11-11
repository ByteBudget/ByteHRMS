<?php
// public/benefit_plans.php - Admin Benefit Plans & Categories Management
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

// Available Benefit Categories / Plan Types matching DB ENUM
$planCategories = [
    'medical'        => 'Medical Coverage',
    'dental'         => 'Dental Care',
    'vision'         => 'Vision Care',
    '401k'           => '401(k) Retirement',
    'life_insurance' => 'Life Insurance',
    'disability'     => 'Disability Insurance'
];

// Handle Create / Update / Delete Form Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action         = $_POST['action'] ?? '';
    $planId         = filter_input(INPUT_POST, 'plan_id', FILTER_VALIDATE_INT);
    $planName       = trim($_POST['plan_name'] ?? '');
    $providerName   = trim($_POST['provider_name'] ?? '');
    $planType       = $_POST['plan_type'] ?? '';
    $description    = trim($_POST['description'] ?? '');
    $waitingPeriod  = filter_input(INPUT_POST, 'waiting_period_days', FILTER_VALIDATE_INT) ?? 30;
    $minWeeklyHours = (float)($_POST['min_weekly_hours'] ?? 30.00);

    if ($action === 'delete' && $planId) {
        try {
            $stmt = $pdo->prepare('DELETE FROM benefit_plans WHERE plan_id = :id');
            $stmt->execute(['id' => $planId]);
            $success = 'Benefit plan deleted successfully!';
        } catch (Exception $e) {
            $error = 'Failed to delete plan: ' . $e->getMessage();
        }
    } elseif (empty($planName) || empty($providerName) || !array_key_exists($planType, $planCategories)) {
        $error = 'Plan name, provider name, and valid plan category are required.';
    } else {
        try {
            $pdo->beginTransaction();

            if ($action === 'create') {
                $planStmt = $pdo->prepare('
                    INSERT INTO benefit_plans (plan_name, provider_name, plan_type, description)
                    VALUES (:name, :provider, :type, :desc)
                ');
                $planStmt->execute([
                    'name'     => $planName,
                    'provider' => $providerName,
                    'type'     => $planType,
                    'desc'     => $description
                ]);
                $newPlanId = $pdo->lastInsertId();

                $ruleStmt = $pdo->prepare('
                    INSERT INTO benefit_eligibility_rules (plan_id, waiting_period_days, min_weekly_hours)
                    VALUES (:plan_id, :waiting, :min_hours)
                ');
                $ruleStmt->execute([
                    'plan_id'   => $newPlanId,
                    'waiting'   => $waitingPeriod,
                    'min_hours' => $minWeeklyHours
                ]);

                $success = 'New benefit plan created successfully!';
            } elseif ($action === 'update' && $planId) {
                $planStmt = $pdo->prepare('
                    UPDATE benefit_plans 
                    SET plan_name = :name, provider_name = :provider, plan_type = :type, description = :desc
                    WHERE plan_id = :id
                ');
                $planStmt->execute([
                    'name'     => $planName,
                    'provider' => $providerName,
                    'type'     => $planType,
                    'desc'     => $description,
                    'id'       => $planId
                ]);

                $ruleCheck = $pdo->prepare('SELECT rule_id FROM benefit_eligibility_rules WHERE plan_id = :id LIMIT 1');
                $ruleCheck->execute(['id' => $planId]);
                if ($ruleCheck->fetch()) {
                    $ruleStmt = $pdo->prepare('
                        UPDATE benefit_eligibility_rules 
                        SET waiting_period_days = :waiting, min_weekly_hours = :min_hours
                        WHERE plan_id = :id
                    ');
                    $ruleStmt->execute([
                        'waiting'   => $waitingPeriod,
                        'min_hours' => $minWeeklyHours,
                        'id'        => $planId
                    ]);
                } else {
                    $ruleStmt = $pdo->prepare('
                        INSERT INTO benefit_eligibility_rules (plan_id, waiting_period_days, min_weekly_hours)
                        VALUES (:id, :waiting, :min_hours)
                    ');
                    $ruleStmt->execute([
                        'id'        => $planId,
                        'waiting'   => $waitingPeriod,
                        'min_hours' => $minWeeklyHours
                    ]);
                }

                $success = 'Benefit plan updated successfully!';
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Database operation failed: ' . $e->getMessage();
        }
    }
}

// Fetch single plan for editing
$editPlan = null;
$editId = filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT);
if ($editId) {
    $editStmt = $pdo->prepare('
        SELECT bp.*, ber.waiting_period_days, ber.min_weekly_hours
        FROM benefit_plans bp
        LEFT JOIN benefit_eligibility_rules ber ON bp.plan_id = ber.plan_id
        WHERE bp.plan_id = :id LIMIT 1
    ');
    $editStmt->execute(['id' => $editId]);
    $editPlan = $editStmt->fetch();
}

$plansList = $pdo->query('
    SELECT bp.*, ber.waiting_period_days, ber.min_weekly_hours, COUNT(be.enrollment_id) AS enrolled_employees
    FROM benefit_plans bp
    LEFT JOIN benefit_eligibility_rules ber ON bp.plan_id = ber.plan_id
    LEFT JOIN benefit_enrollments be ON bp.plan_id = be.plan_id AND (be.end_date IS NULL OR be.end_date >= CURRENT_DATE())
    GROUP BY bp.plan_id ORDER BY bp.plan_type ASC, bp.plan_name ASC
')->fetchAll();

$pageTitle = 'Benefit Plans Management';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header">
        <div>
            <h2>Benefit Plans Management</h2>
            <p class="subtitle">Configure corporate medical, dental, retirement, and insurance plans.</p>
        </div>
    </div>

    <!-- Two-column layout: Left sidebar + Right settings content -->
    <div style="display: flex; gap: 2rem; align-items: flex-start; margin-top: 1rem; width: 100%;">
        
        <!-- LEFT COLUMN: Side Navigation Bar -->
        <div style="width: 260px; min-width: 260px; flex-shrink: 0;">
            <?php require_once __DIR__ . '/../includes/settings_sidebar.php'; ?>
        </div>

        <!-- RIGHT COLUMN: Benefit Plans Settings & Table -->
        <div style="flex: 1 1 auto; min-width: 0;">
            <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <!-- Add / Edit Benefit Plan Form Card -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 style="margin-top: 0; color: #2b6cb0;"><?= $editPlan ? 'Edit Benefit Plan' : 'Add New Benefit Plan' ?></h3>
                <form method="POST" action="benefit_plans.php">
                    <input type="hidden" name="action" value="<?= $editPlan ? 'update' : 'create' ?>">
                    <?php if ($editPlan): ?>
                        <input type="hidden" name="plan_id" value="<?= $editPlan['plan_id'] ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="plan_name">Plan Name *</label>
                            <input type="text" id="plan_name" name="plan_name" required placeholder="e.g. Standard Health PPO" value="<?= htmlspecialchars($editPlan['plan_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="provider_name">Insurance / Provider Name *</label>
                            <input type="text" id="provider_name" name="provider_name" required placeholder="e.g. BlueCross, Fidelity" value="<?= htmlspecialchars($editPlan['provider_name'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="plan_type">Benefit Category *</label>
                            <select id="plan_type" name="plan_type" required>
                                <option value="">-- Select Category --</option>
                                <?php foreach ($planCategories as $code => $label): ?>
                                    <option value="<?= $code ?>" <?= ($editPlan['plan_type'] ?? '') === $code ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="waiting_period_days">Waiting Period (Days)</label>
                            <input type="number" id="waiting_period_days" name="waiting_period_days" min="0" value="<?= htmlspecialchars($editPlan['waiting_period_days'] ?? '30') ?>">
                        </div>
                        <div class="form-group">
                            <label for="min_weekly_hours">Min Weekly Hours</label>
                            <input type="number" step="0.5" id="min_weekly_hours" name="min_weekly_hours" min="0" value="<?= htmlspecialchars($editPlan['min_weekly_hours'] ?? '30.00') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">Plan Summary / Coverage Details</label>
                        <input type="text" id="description" name="description" placeholder="Brief summary of coverage or benefits" value="<?= htmlspecialchars($editPlan['description'] ?? '') ?>">
                    </div>

                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="submit" class="btn-submit"><?= $editPlan ? 'Save Changes' : 'Create Benefit Plan' ?></button>
                        <?php if ($editPlan): ?>
                            <a href="benefit_plans.php" class="btn-reset" style="text-align: center; text-decoration: none;">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Benefit Plans Table Card -->
            <div class="card">
                <h3 style="margin-top: 0; color: #2b6cb0;">Active Benefit Plans</h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Plan Name & Provider</th>
                                <th>Category</th>
                                <th>Eligibility</th>
                                <th>Enrollees</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($plansList)): ?>
                                <?php foreach ($plansList as $p): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($p['plan_name']) ?></strong><br>
                                            <small style="color: #718096;">Provider: <?= htmlspecialchars($p['provider_name']) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge badge-active">
                                                <?= htmlspecialchars($planCategories[$p['plan_type']] ?? $p['plan_type']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= $p['waiting_period_days'] ?? 0 ?> days post-hire<br>
                                            <small style="color: #718096;">Min <?= number_format($p['min_weekly_hours'] ?? 30, 1) ?> hrs/wk</small>
                                        </td>
                                        <td><strong><?= $p['enrolled_employees'] ?> staff</strong></td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                <a href="benefit_plans.php?edit_id=<?= $p['plan_id'] ?>" class="btn-reset" style="font-size: 0.8rem; padding: 0.25rem 0.6rem; text-decoration: none;">Edit</a>
                                                <form method="POST" action="benefit_plans.php" onsubmit="return confirm('Are you sure you want to delete this benefit plan?');" style="margin: 0;">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="plan_id" value="<?= $p['plan_id'] ?>">
                                                    <button type="submit" style="background: #e53e3e; color: white; border: none; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer;">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: #a0aec0;">No benefit plans configured yet.</td>
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
