<?php
// public/payroll_report.php - Employee Earnings & Pay Period Selection Report
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

if (!hasRole('admin') && !hasRole('manager') && !hasRole('hr_specialist')) {
    http_response_code(403);
    $pageTitle = 'Access Denied';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container"><div class="alert-danger"><strong>Access Denied:</strong> Manager privileges required.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// 1. Load Payroll Settings & Helper Generator
$configFile = __DIR__ . '/../config/payroll_settings.php';
$payrollSettings = file_exists($configFile) ? require $configFile : [
    'pay_frequency'       => 'bi_weekly',
    'anchor_start_date'  => '2026-01-05',
    'pay_day_offset'      => 5,
    'overtime_multiplier' => 1.50
];

/**
 * Generates a list of pay periods around the current date based on company settings.
 */
function generatePayPeriods($settings, $numPeriods = 8) {
    $periods   = [];
    $frequency = $settings['pay_frequency'];
    $anchor    = strtotime($settings['anchor_start_date']);
    $today     = time();
    $otMult    = (float)($settings['overtime_multiplier'] ?? 1.50);

    if ($frequency === 'bi_weekly' || $frequency === 'weekly') {
        $daysInCycle = ($frequency === 'bi_weekly') ? 14 : 7;
        $cycleSecs   = $daysInCycle * 86400;

        // Find current cycle start
        $diff = $today - $anchor;
        $cyclesPassed = floor($diff / $cycleSecs);
        $currentCycleStart = $anchor + ($cyclesPassed * $cycleSecs);

        // Generate past and future cycles
        for ($i = -$numPeriods; $i <= 2; $i++) {
            $pStart = $currentCycleStart + ($i * $cycleSecs);
            $pEnd   = $pStart + (($daysInCycle - 1) * 86400);
            
            $periods[] = [
                'key'        => date('Y-m-d', $pStart) . '|' . date('Y-m-d', $pEnd),
                'label'      => date('M j, Y', $pStart) . ' – ' . date('M j, Y', $pEnd),
                'start_date' => date('Y-m-d', $pStart),
                'end_date'   => date('Y-m-d', $pEnd),
                'is_current' => ($today >= $pStart && $today <= ($pEnd + 86400))
            ];
        }
    } else {
        // Monthly or Semi-Monthly
        $curYear  = (int)date('Y');
        $curMonth = (int)date('n');

        for ($i = -$numPeriods; $i <= 2; $i++) {
            $m = $curMonth + $i;
            $y = $curYear;
            while ($m < 1) { $m += 12; $y--; }
            while ($m > 12) { $m -= 12; $y++; }

            $pStart = sprintf('%04d-%02d-01', $y, $m);
            $pEnd   = date('Y-m-t', strtotime($pStart));

            $periods[] = [
                'key'        => $pStart . '|' . $pEnd,
                'label'      => date('F Y', strtotime($pStart)) . ' (' . date('M 1', strtotime($pStart)) . ' - ' . date('M t', strtotime($pStart)) . ')',
                'start_date' => $pStart,
                'end_date'   => $pEnd,
                'is_current' => (date('Y-m') === sprintf('%04d-%02d', $y, $m))
            ];
        }
    }

    return $periods;
}

$availablePeriods = generatePayPeriods($payrollSettings, 6);

// 2. Resolve Selected Date Range
$selectedPeriodKey = $_GET['period_key'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate   = $_GET['end_date'] ?? '';

if (!empty($selectedPeriodKey)) {
    $parts = explode('|', $selectedPeriodKey);
    if (count($parts) === 2) {
        $startDate = $parts[0];
        $endDate   = $parts[1];
    }
}

// Fallback to Current Active Period if no selection
if (empty($startDate) || empty($endDate)) {
    foreach ($availablePeriods as $p) {
        if (!empty($p['is_current'])) {
            $startDate = $p['start_date'];
            $endDate   = $p['end_date'];
            $selectedPeriodKey = $p['key'];
            break;
        }
    }
    if (empty($startDate)) {
        $startDate = date('Y-m-01');
        $endDate   = date('Y-m-t');
    }
}

// 3. Fetch Payroll Data
$query = '
    SELECT 
        e.employee_id,
        e.first_name,
        e.last_name,
        d.department_name,
        ch.pay_rate,
        ch.pay_frequency,
        pt.type_name AS pay_model,
        COALESCE(SUM(tal.total_hours_worked), 0) AS total_hours,
        COALESCE(SUM(tal.overtime_hours), 0) AS total_overtime,
        (COALESCE(SUM(tal.total_hours_worked), 0) - COALESCE(SUM(tal.overtime_hours), 0)) AS regular_hours
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN compensation_history ch ON e.employee_id = ch.employee_id 
        AND (ch.end_date IS NULL OR ch.end_date >= :end_date1)
    LEFT JOIN pay_types pt ON ch.pay_type_id = pt.pay_type_id
    LEFT JOIN time_attendance_logs tal ON e.employee_id = tal.employee_id 
        AND DATE(tal.clock_in) BETWEEN :start_date AND :end_date2
    WHERE e.employment_status = "active"
    GROUP BY e.employee_id, e.first_name, e.last_name, d.department_name, ch.pay_rate, ch.pay_frequency, pt.type_name
    ORDER BY e.last_name ASC, e.first_name ASC
';

$stmt = $pdo->prepare($query);
$stmt->execute([
    'start_date' => $startDate,
    'end_date1'  => $endDate,
    'end_date2'  => $endDate
]);
$payrollData = $stmt->fetchAll();

$otMultiplier    = (float)($payrollSettings['overtime_multiplier'] ?? 1.50);
$grandTotalHours = 0;
$grandTotalPay   = 0;

$pageTitle = 'Payroll & Wage Summary';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="widescreen-container" style="max-width: 1440px; margin: 0 auto; padding: 0 1.5rem;">
    
    <div class="directory-header" style="margin-top: 1.5rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2>Payroll & Wage Summary Report</h2>
            <p class="subtitle">Gross earnings multiplier (Logged Hours × Pay Rate) for selected pay cycle.</p>
        </div>

        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <?php if (hasRole('admin')): ?>
                <a href="payroll_settings.php" class="btn-reset" style="padding: 0.5rem 0.8rem; font-size: 0.85rem;">⚙️ Pay Period Settings</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- FILTER CARD: Pay Period Selector & Custom Range -->
    <div class="card" style="margin-bottom: 1.5rem; background: #f8fafc; border: 1px solid #e2e8f0;">
        <form method="GET" action="payroll_report.php" style="display: flex; gap: 1.5rem; align-items: flex-end; flex-wrap: wrap;">
            
            <div style="flex: 1.5; min-width: 280px;">
                <label for="period_key" style="font-weight: 600; font-size: 0.85rem; color: #4a5568; margin-bottom: 0.35rem; display: block;">Select Pre-configured Pay Period</label>
                <select id="period_key" name="period_key" onchange="this.form.submit()" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px; font-weight: 500;">
                    <option value="">-- Custom Date Range --</option>
                    <?php foreach ($availablePeriods as $p): ?>
                        <option value="<?= $p['key'] ?>" <?= ($selectedPeriodKey === $p['key']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['label']) ?> <?= $p['is_current'] ? '(Current Cycle)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: flex; gap: 0.5rem; align-items: center; flex: 1; min-width: 260px;">
                <div style="flex: 1;">
                    <label for="start_date" style="font-weight: 600; font-size: 0.85rem; color: #4a5568; margin-bottom: 0.35rem; display: block;">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                </div>
                <span style="margin-top: 1.5rem; color: #a0aec0;">to</span>
                <div style="flex: 1;">
                    <label for="end_date" style="font-weight: 600; font-size: 0.85rem; color: #4a5568; margin-bottom: 0.35rem; display: block;">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                </div>
            </div>

            <button type="submit" class="btn-submit" style="padding: 0.55rem 1.25rem;">Apply Filter</button>
        </form>
    </div>

    <!-- PAYROLL SUMMARY TABLE -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="margin: 0; color: #2b6cb0;">Active Period: <?= date('M j, Y', strtotime($startDate)) ?> – <?= date('M j, Y', strtotime($endDate)) ?></h3>
            <span style="font-size: 0.85rem; color: #718096;">Overtime Rate: <strong><?= number_format($otMultiplier, 2) ?>x</strong></span>
        </div>

        <div class="table-responsive">
            <table class="table" style="width: 100%; font-size: 0.9rem;">
                <thead>
                    <tr style="background: #f7fafc;">
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Pay Model</th>
                        <th>Base Rate</th>
                        <th>Regular Hours</th>
                        <th>Overtime Hours</th>
                        <th>Total Hours</th>
                        <th>Est. Gross Pay</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($payrollData)): ?>
                        <?php foreach ($payrollData as $row): 
                            $rate        = (float)($row['pay_rate'] ?? 0);
                            $regHours    = (float)$row['regular_hours'];
                            $otHours     = (float)$row['total_overtime'];
                            $totalHours  = (float)$row['total_hours'];
                            $payModel    = strtolower($row['pay_model'] ?? 'hourly');

                            if ($payModel === 'hourly') {
                                $grossPay = ($regHours * $rate) + ($otHours * ($rate * $otMultiplier));
                            } else {
                                $grossPay = $totalHours * $rate;
                            }

                            $grandTotalHours += $totalHours;
                            $grandTotalPay   += $grossPay;
                        ?>
                            <tr>
                                <td>
                                    <strong>
                                        <a href="edit_employee.php?id=<?= $row['employee_id'] ?>" style="color: #2b6cb0; text-decoration: none;">
                                            <?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?>
                                        </a>
                                    </strong>
                                </td>
                                <td><?= htmlspecialchars($row['department_name'] ?? 'Unassigned') ?></td>
                                <td><?= htmlspecialchars($row['pay_model'] ?? 'Hourly') ?></td>
                                <td>
                                    <?php if ($rate > 0): ?>
                                        $<?= number_format($rate, 2) ?>/hr
                                    <?php else: ?>
                                        <span style="color: #e53e3e;">No Rate Set</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($regHours, 2) ?> hrs</td>
                                <td><?= $otHours > 0 ? '<span style="color:#e53e3e; font-weight:600;">' . number_format($otHours, 2) . ' hrs</span>' : '0.00' ?></td>
                                <td><strong><?= number_format($totalHours, 2) ?> hrs</strong></td>
                                <td style="font-size: 0.95rem; color: #2b6cb0;">
                                    <strong>$<?= number_format($grossPay, 2) ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: #a0aec0; padding: 2rem;">No attendance records found for this pay period.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #edf2f7; font-weight: 700; font-size: 0.95rem;">
                        <td colspan="6" style="text-align: right;">Total Payroll Period Summary:</td>
                        <td><?= number_format($grandTotalHours, 2) ?> hrs</td>
                        <td style="color: #2b6cb0; font-size: 1.1rem;">$<?= number_format($grandTotalPay, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
