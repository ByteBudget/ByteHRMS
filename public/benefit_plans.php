<?php
// public/benefit_plans.php - Benefit Plans Management
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

$planTypes = [
    'medical'        => 'Medical',
    'dental'         => 'Dental',
    'vision'         => 'Vision',
    '401k'           => '401(k) Retirement',
    'life_insurance' => 'Life Insurance',
    'disability'     => 'Disability'
];

// Handle Create / Update / Delete Form Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    $planId = filter_input(INPUT_POST, 'plan_id', FILTER_VALIDATE_INT);

    try {
        if ($action === 'delete' && $planId) {
            $stmt = $pdo->prepare('DELETE FROM benefit_plans WHERE plan_id = :id');
            $stmt->execute(['id' => $planId]);
            $success = 'Benefit plan deleted successfully.';
        } else {
            $planName     = trim($_POST['plan_name'] ?? '');
            $providerName = trim($_POST['provider_name'] ?? '');
            $planType     = trim($_POST['plan_type'] ?? 'medical');
            $waitDays     = filter_input(INPUT_POST, 'waiting_period_days', FILTER_VALIDATE_INT);
            $minHours     = filter_input(INPUT_POST, 'min_weekly_hours', FILTER_VALIDATE_FLOAT);

            if ($waitDays === false || $waitDays === null) $waitDays = 30;
            if ($minHours === false || $minHours === null) $minHours = 30.00;

            if (!array_key_exists($planType, $planTypes)) {
                $planType = 'medical';
            }

            if (empty($planName) || empty($providerName)) {
                $error = 'Plan Name and Provider Name are required.';
            } elseif ($action === 'update' && $planId) {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('
                    UPDATE benefit_plans 
                    SET plan_name = :name, provider_name = :provider, plan_type = :type 
                    WHERE plan_id = :id
                ');
                $stmt->execute([
                    'name'     => $planName,
                    'provider' => $providerName,
                    'type'     => $planType,
                    'id'       => $planId
                ]);

                // Update or insert eligibility rule
                $ruleCheck = $pdo->prepare('SELECT rule_id FROM benefit_eligibility_rules WHERE plan_id = :pid LIMIT 1');
                $ruleCheck->execute(['pid' => $planId]);
                $existingRuleId = $ruleCheck->fetchColumn();

                if ($existingRuleId) {
                    $ruleUpd = $pdo->prepare('
                        UPDATE benefit_eligibility_rules 
                        SET waiting_period_days = :wait, min_weekly_hours = :hours 
                        WHERE rule_id = :rid
                    ');
                    $ruleUpd->execute(['wait' => $waitDays, 'hours' => $minHours, 'rid' => $existingRuleId]);
                } else {
                    $ruleIns = $pdo->prepare('
                        INSERT INTO benefit_eligibility_rules (plan_id, waiting_period_days, min_weekly_hours) 
                        VALUES (:pid, :wait, :hours)
                    ');
                    $ruleIns->execute(['pid' => $planId, 'wait' => $waitDays, 'hours' => $minHours]);
                }

                $pdo->commit();
                $success = 'Benefit plan updated successfully.';
            } else {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('
                    INSERT INTO benefit_plans (plan_name, provider_name, plan_type) 
                    VALUES (:name, :provider, :type)
                ');
                $stmt->execute([
                    'name'     => $planName,
                    'provider' => $providerName,
                    'type'     => $planType
                ]);
                $newPlanId = $pdo->lastInsertId();

                $ruleIns = $pdo->prepare('
                    INSERT INTO benefit_eligibility_rules (plan_id, waiting_period_days, min_weekly_hours) 
                    VALUES (:pid, :wait, :hours)
                ');
                $ruleIns->execute([
                    'pid'   => $newPlanId,
                    'wait'  => $waitDays,
                    'hours' => $minHours
                ]);

                $pdo->commit();
                $success = 'Benefit plan added successfully.';
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Database operation failed: ' . $e->getMessage();
    }
}

// Fetch single plan for editing if edit_id is provided
$editPlan = null;
$editId = filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT);
if ($editId) {
    $editStmt = $pdo->prepare('
        SELECT bp.*, ber.waiting_period_days, ber.min_weekly_hours
        FROM benefit_plans bp
        LEFT JOIN benefit_eligibility_rules ber ON bp.plan_id = ber.plan_id
        WHERE bp.plan_id = :id
        LIMIT 1
    ');
    $editStmt->execute(['id' => $editId]);
    $editPlan = $editStmt->fetch();
}

// Fetch all plans with eligibility rules
$plans = $pdo->query('
    SELECT bp.*, ber.waiting_period_days, ber.min_weekly_hours
    FROM benefit_plans bp
    LEFT JOIN benefit_eligibility_rules ber ON bp.plan_id = ber.plan_id
    ORDER BY bp.plan_type ASC, bp.plan_name ASC
')->fetchAll();

$pageTitle = 'Benefit Plans Management';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header">
        <div>
            <h2>Benefit Plans Management</h2>
            <p class="subtitle">Configure corporate benefit programs, categories, and eligibility rules.</p>
        </div>
    </div>

    <div class="settings-layout">
        <?php require_once __DIR__ . '/../includes/settings_sidebar.php'; ?>

        <main class="settings-content">
            <?php if ($error): ?>
                <div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <!-- Add / Edit Benefit Plan Form Card -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 style="margin-top: 0; color: #2b6cb0;">
                    <?= $editPlan ? 'Edit Benefit Plan' : 'Add Benefit Plan' ?>
                </h3>
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
                            <label for="provider_name">Provider Name *</label>
                            <input type="text" id="provider_name" name="provider_name" required placeholder="e.g. Blue Cross Blue Shield" value="<?= htmlspecialchars($editPlan['provider_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="plan_type">Category / Plan Type *</label>
                            <select id="plan_type" name="plan_type" required>
                                <?php foreach ($planTypes as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= (($editPlan['plan_type'] ?? 'medical') === $key) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="waiting_period_days">Eligibility Waiting Period (Days)</label>
                            <input type="number" id="waiting_period_days" name="waiting_period_days" min="0" step="1" value="<?= htmlspecialchars($editPlan['waiting_period_days'] ?? '30') ?>">
                        </div>
                        <div class="form-group">
                            <label for="min_weekly_hours">Minimum Weekly Hours Required</label>
                            <input type="number" id="min_weekly_hours" name="min_weekly_hours" min="0" max="168" step="0.5" value="<?= htmlspecialchars($editPlan['min_weekly_hours'] ?? '30.00') ?>">
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.75rem; margin-top: 1rem;">
                        <button type="submit" class="btn-submit">
                            <?= $editPlan ? 'Update Plan' : 'Save Plan' ?>
                        </button>
                        <?php if ($editPlan): ?>
                            <a href="benefit_plans.php" class="btn-reset" style="text-decoration: none;">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Existing Benefit Plans List Card -->
            <div class="card">
                <h3 style="margin-top: 0; color: #2b6cb0;">Benefit Plans Directory</h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Plan Name</th>
                                <th>Provider</th>
                                <th>Category</th>
                                <th>Eligibility Rule</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($plans)): ?>
                                <?php foreach ($plans as $p): ?>
                                    <tr>
                                        <td><?= $p['plan_id'] ?></td>
                                        <td><strong><?= htmlspecialchars($p['plan_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($p['provider_name']) ?></td>
                                        <td>
                                            <span class="badge badge-active">
                                                <?= htmlspecialchars($planTypes[$p['plan_type']] ?? ucfirst($p['plan_type'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= (int)($p['waiting_period_days'] ?? 30) ?> days / 
                                            <?= number_format((float)($p['min_weekly_hours'] ?? 30), 1) ?> hrs/wk
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="benefit_plans.php?edit_id=<?= $p['plan_id'] ?>" class="btn-edit">Edit</a>
                                                <form method="POST" action="benefit_plans.php" onsubmit="return confirm('Are you sure you want to delete this benefit plan?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="plan_id" value="<?= $p['plan_id'] ?>">
                                                    <button type="submit" class="btn-delete">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: #a0aec0;">No benefit plans configured yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
