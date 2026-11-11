<?php
// public/payroll_settings.php - Company Pay Period & Payroll Configuration
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

$configFile = __DIR__ . '/../config/payroll_settings.php';

if (file_exists($configFile)) {
    $payrollSettings = require $configFile;
} else {
    $payrollSettings = [
        'pay_frequency'      => 'bi_weekly', // weekly, bi_weekly, semi_monthly, monthly
        'anchor_start_date' => '2026-01-05', // Monday start anchor
        'pay_day_offset'    => 5,            // Days after period end pay occurs
        'overtime_multiplier' => 1.50
    ];
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $frequency   = $_POST['pay_frequency'] ?? 'bi_weekly';
    $anchorDate  = $_POST['anchor_start_date'] ?? date('Y-01-01');
    $payOffset   = (int)($_POST['pay_day_offset'] ?? 5);
    $otMultiplier = (float)($_POST['overtime_multiplier'] ?? 1.50);

    if (empty($anchorDate)) {
        $error = 'Anchor Start Date is required to calculate pay period cycles.';
    } else {
        $updatedSettings = [
            'pay_frequency'       => $frequency,
            'anchor_start_date'  => $anchorDate,
            'pay_day_offset'     => $payOffset,
            'overtime_multiplier' => $otMultiplier
        ];

        $exportCode = "<?php\n// config/payroll_settings.php - Company Payroll Configuration\n\nreturn " . var_export($updatedSettings, true) . ";\n";

        if (@file_put_contents($configFile, $exportCode) !== false) {
            $payrollSettings = $updatedSettings;
            $success = 'Payroll and pay period settings saved successfully.';
        } else {
            $error = 'Failed to write to `config/payroll_settings.php`. Check server file permissions.';
        }
    }
}

$pageTitle = 'Payroll & Pay Period Settings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header" style="margin-top: 1.5rem; margin-bottom: 1.5rem;">
        <div>
            <h2>Payroll & Pay Period Settings</h2>
            <p class="subtitle">Configure corporate pay cycle frequencies, period start dates, and overtime multipliers.</p>
        </div>
    </div>

    <div style="display: flex; gap: 2rem; align-items: flex-start; margin-top: 1rem; width: 100%;">
        
        <!-- Sidebar Navigation -->
        <div style="width: 260px; min-width: 260px; flex-shrink: 0;">
            <?php require_once __DIR__ . '/../includes/settings_sidebar.php'; ?>
        </div>

        <!-- Form Content -->
        <div style="flex: 1 1 auto; min-width: 0;">
            <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <div class="card">
                <h3 style="margin-top: 0; color: #2b6cb0; border-bottom: 2px solid #edf2f7; padding-bottom: 0.5rem;">Pay Cycle Parameters</h3>
                
                <form method="POST" action="payroll_settings.php">
                    
                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label for="pay_frequency" style="font-weight: 600;">Pay Period Cycle Frequency *</label>
                        <select id="pay_frequency" name="pay_frequency" required style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                            <option value="weekly" <?= $payrollSettings['pay_frequency'] === 'weekly' ? 'selected' : '' ?>>Weekly (Every 7 Days)</option>
                            <option value="bi_weekly" <?= $payrollSettings['pay_frequency'] === 'bi_weekly' ? 'selected' : '' ?>>Bi-Weekly (Every 14 Days)</option>
                            <option value="semi_monthly" <?= $payrollSettings['pay_frequency'] === 'semi_monthly' ? 'selected' : '' ?>>Semi-Monthly (1st-15th / 16th-End)</option>
                            <option value="monthly" <?= $payrollSettings['pay_frequency'] === 'monthly' ? 'selected' : '' ?>>Monthly (1st to Last Day of Month)</option>
                        </select>
                        <small style="color: #718096;">Determines automatically generated period ranges in payroll reports.</small>
                    </div>

                    <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap;">
                        <div class="form-group" style="flex: 1; min-width: 220px;">
                            <label for="anchor_start_date" style="font-weight: 600;">Cycle Anchor Start Date *</label>
                            <input type="date" id="anchor_start_date" name="anchor_start_date" required value="<?= htmlspecialchars($payrollSettings['anchor_start_date']) ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                            <small style="color: #718096;">The start date of a known pay period to anchor repeating cycles.</small>
                        </div>

                        <div class="form-group" style="flex: 1; min-width: 220px;">
                            <label for="overtime_multiplier" style="font-weight: 600;">Overtime Rate Multiplier</label>
                            <input type="number" step="0.05" min="1.0" max="3.0" id="overtime_multiplier" name="overtime_multiplier" value="<?= htmlspecialchars($payrollSettings['overtime_multiplier']) ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                            <small style="color: #718096;">Standard overtime multiplier (e.g. 1.50 for time and a half).</small>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 1.5rem;">
                        <label for="pay_day_offset" style="font-weight: 600;">Payday Lag Offset (Days)</label>
                        <input type="number" id="pay_day_offset" name="pay_day_offset" min="0" max="30" value="<?= htmlspecialchars($payrollSettings['pay_day_offset']) ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                        <small style="color: #718096;">Number of calendar days after period close when funds are disbursed.</small>
                    </div>

                    <button type="submit" class="btn-submit" style="max-width: 240px;">Save Payroll Settings</button>
                </form>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
