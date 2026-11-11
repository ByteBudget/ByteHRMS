<?php
// public/request_leave.php - Self-Service Leave Request with Net Business Hours Calculation
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$employeeId = $_SESSION['employee_id'] ?? null;

if (!$employeeId) {
    http_response_code(403);
    $pageTitle = 'Access Denied';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container"><div class="alert-danger"><strong>Access Denied:</strong> No employee record associated with your account.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Load business hours configuration array
$bizConfigFile = __DIR__ . '/../config/business_hours.php';
if (file_exists($bizConfigFile)) {
    $businessHours = require $bizConfigFile;
} else {
    $businessHours = [
        'Monday'    => ['is_open' => true,  'open_time' => '08:00', 'close_time' => '17:00', 'break_duration' => 60],
        'Tuesday'   => ['is_open' => true,  'open_time' => '08:00', 'close_time' => '17:00', 'break_duration' => 60],
        'Wednesday' => ['is_open' => true,  'open_time' => '08:00', 'close_time' => '17:00', 'break_duration' => 60],
        'Thursday'  => ['is_open' => true,  'open_time' => '08:00', 'close_time' => '17:00', 'break_duration' => 60],
        'Friday'    => ['is_open' => true,  'open_time' => '08:00', 'close_time' => '17:00', 'break_duration' => 60],
        'Saturday'  => ['is_open' => false, 'open_time' => '09:00', 'close_time' => '14:00', 'break_duration' => 0],
        'Sunday'    => ['is_open' => false, 'open_time' => '09:00', 'close_time' => '14:00', 'break_duration' => 0],
    ];
}

/**
 * Calculates net business hours requested (gross operating hours minus unpaid break duration).
 */
function calculateBusinessHours($startDate, $endDate, $schedule) {
    $totalHours = 0.0;
    $current = strtotime($startDate);
    $last    = strtotime($endDate);

    while ($current <= $last) {
        $dayName = date('l', $current);
        
        if (!empty($schedule[$dayName]['is_open'])) {
            $open  = strtotime($schedule[$dayName]['open_time']);
            $close = strtotime($schedule[$dayName]['close_time']);
            $breakMins = (int)($schedule[$dayName]['break_duration'] ?? 0);
            
            if ($close > $open) {
                // Gross operating hours minus break duration
                $grossHours = ($close - $open) / 3600;
                $netHours   = $grossHours - ($breakMins / 60);
                if ($netHours < 0) { $netHours = 0; }
                
                $totalHours += $netHours;
            }
        }
        $current = strtotime('+1 day', $current);
    }

    return $totalHours;
}

$error = '';
$success = '';

// Handle Leave Request Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leaveTypeId = filter_input(INPUT_POST, 'leave_type_id', FILTER_VALIDATE_INT);
    $startDate   = $_POST['start_date'] ?? '';
    $endDate     = $_POST['end_date'] ?? '';
    $reason      = trim($_POST['reason'] ?? '');

    if (!$leaveTypeId || empty($startDate) || empty($endDate)) {
        $error = 'Please select a leave category and valid date range.';
    } elseif (strtotime($endDate) < strtotime($startDate)) {
        $error = 'End date cannot be earlier than start date.';
    } else {
        $totalHours = calculateBusinessHours($startDate, $endDate, $businessHours);

        if ($totalHours <= 0) {
            $error = 'Selected date range falls entirely on non-operating business days/hours.';
        } else {
            try {
                $stmt = $pdo->prepare('
                    INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, total_hours, reason, status)
                    VALUES (:emp_id, :type_id, :start_date, :end_date, :hours, :reason, "pending")
                ');
                $stmt->execute([
                    'emp_id'     => $employeeId,
                    'type_id'    => $leaveTypeId,
                    'start_date' => $startDate,
                    'end_date'   => $endDate,
                    'hours'      => $totalHours,
                    'reason'     => $reason
                ]);
                $success = "Your request for {$totalHours} working hours has been submitted!";
            } catch (Exception $e) {
                $error = 'Failed to submit request: ' . $e->getMessage();
            }
        }
    }
}

$leaveTypes = $pdo->query('SELECT * FROM leave_types ORDER BY type_name ASC')->fetchAll();

$currentYear = (int)date('Y');
$balStmt = $pdo->prepare('
    SELECT lb.*, lt.type_name, lt.is_paid
    FROM leave_balances lb
    JOIN leave_types lt ON lb.leave_type_id = lt.leave_type_id
    WHERE lb.employee_id = :emp_id AND lb.fiscal_year = :year
');
$balStmt->execute(['emp_id' => $employeeId, 'year' => $currentYear]);
$balances = $balStmt->fetchAll();

$reqStmt = $pdo->prepare('
    SELECT lr.*, lt.type_name
    FROM leave_requests lr
    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
    WHERE lr.employee_id = :emp_id
    ORDER BY lr.created_at DESC
');
$reqStmt->execute(['emp_id' => $employeeId]);
$myRequests = $reqStmt->fetchAll();

$pageTitle = 'Request Time Off';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header" style="margin-top: 1.5rem; margin-bottom: 1.5rem;">
        <div>
            <h2>Request Time Off</h2>
            <p class="subtitle">Submit time-off requests with automated net working hours calculation.</p>
        </div>
    </div>

    <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <!-- Balances View -->
    <div class="card" style="margin-bottom: 1.5rem;">
        <h3 style="margin-top: 0; color: #2b6cb0;">My Balances (<?= $currentYear ?>)</h3>
        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <?php if (!empty($balances)): ?>
                <?php foreach ($balances as $b): 
                    $rem = ($b['total_allocated'] + $b['carryover_hours']) - $b['used_hours'];
                ?>
                    <div style="background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 1rem; flex: 1; min-width: 180px;">
                        <small style="color: #718096; font-weight: 600; text-transform: uppercase;"><?= htmlspecialchars($b['type_name']) ?></small>
                        <div style="font-size: 1.5rem; font-weight: 700; color: #2b6cb0; margin-top: 0.25rem;">
                            <?= number_format($rem, 1) ?> <span style="font-size: 0.9rem; color: #4a5568;">hrs left</span>
                        </div>
                        <small style="color: #a0aec0;"><?= number_format($b['total_allocated'], 1) ?> allocated</small>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: #a0aec0;">No balance records allocated for this fiscal year.</p>
            <?php endif; ?>
        </div>
    </div>

    <div style="display: flex; gap: 1.5rem; align-items: flex-start;">
        <!-- Request Form -->
        <div class="card" style="flex: 1;">
            <h3 style="margin-top: 0; color: #2b6cb0;">Submit New Request</h3>
            <form method="POST" action="request_leave.php">
                <div class="form-group">
                    <label for="leave_type_id">Time Off Type *</label>
                    <select id="leave_type_id" name="leave_type_id" required style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                        <option value="">-- Select Type --</option>
                        <?php foreach ($leaveTypes as $lt): ?>
                            <option value="<?= $lt['leave_type_id'] ?>"><?= htmlspecialchars($lt['type_name']) ?> <?= $lt['is_paid'] ? '(Paid)' : '(Unpaid)' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group" style="flex: 1;">
                        <label for="start_date">Start Date *</label>
                        <input type="date" id="start_date" name="start_date" required min="<?= date('Y-m-d') ?>" onchange="calculateHoursPreview()" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label for="end_date">End Date *</label>
                        <input type="date" id="end_date" name="end_date" required min="<?= date('Y-m-d') ?>" onchange="calculateHoursPreview()" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                    </div>
                </div>

                <!-- Calculated Hours Output Preview -->
                <div style="background: #ebf8ff; border: 1px solid #bee3f8; border-radius: 6px; padding: 0.75rem; margin-bottom: 1rem; text-align: center;">
                    <span style="font-size: 0.85rem; color: #2b6cb0; font-weight: 600;">Calculated Working Hours:</span>
                    <div id="hours_preview" style="font-size: 1.4rem; font-weight: 700; color: #2b6cb0;">0.0 hrs</div>
                    <small style="color: #4a5568;">Computed automatically minus standard unpaid lunch breaks.</small>
                </div>

                <div class="form-group">
                    <label for="reason">Reason / Notes</label>
                    <textarea id="reason" name="reason" rows="3" placeholder="Optional notes for your manager..." style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;"></textarea>
                </div>

                <button type="submit" class="btn-submit" style="width: 100%; margin-top: 1rem;">Submit Request</button>
            </form>
        </div>

        <!-- Request History Table -->
        <div class="card" style="flex: 2;">
            <h3 style="margin-top: 0; color: #2b6cb0;">My Request History</h3>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Dates</th>
                            <th>Hours</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($myRequests)): ?>
                            <?php foreach ($myRequests as $r): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($r['type_name']) ?></strong></td>
                                    <td><?= date('M j, Y', strtotime($r['start_date'])) ?> – <?= date('M j, Y', strtotime($r['end_date'])) ?></td>
                                    <td><?= number_format($r['total_hours'], 1) ?> hrs</td>
                                    <td>
                                        <?php if ($r['status'] === 'approved'): ?>
                                            <span class="badge badge-active">Approved</span>
                                        <?php elseif ($r['status'] === 'rejected'): ?>
                                            <span class="badge badge-admin">Denied</span>
                                        <?php else: ?>
                                            <span class="badge badge-manager" style="background: #feebc8; color: #9c4221;">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: #a0aec0;">No previous time off requests found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const businessSchedule = <?= json_encode($businessHours) ?>;

function calculateHoursPreview() {
    const startVal = document.getElementById('start_date').value;
    const endVal   = document.getElementById('end_date').value;
    const preview  = document.getElementById('hours_preview');

    if (!startVal || !endVal) {
        preview.textContent = '0.0 hrs';
        return;
    }

    let start = new Date(startVal + 'T00:00:00');
    let end   = new Date(endVal + 'T00:00:00');

    if (end < start) {
        preview.textContent = '0.0 hrs';
        return;
    }

    let totalHours = 0;
    let days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    let cur = new Date(start);

    while (cur <= end) {
        let dayName = days[cur.getDay()];
        let sched = businessSchedule[dayName];

        if (sched && sched.is_open) {
            let [openH, openM]   = sched.open_time.split(':').map(Number);
            let [closeH, closeM] = sched.close_time.split(':').map(Number);
            let breakMins        = parseInt(sched.break_duration || 0, 10);

            let openMinutes  = openH * 60 + openM;
            let closeMinutes = closeH * 60 + closeM;

            if (closeMinutes > openMinutes) {
                let grossMins = closeMinutes - openMinutes;
                let netMins   = grossMins - breakMins;
                if (netMins < 0) netMins = 0;
                
                totalHours += netMins / 60;
            }
        }
        cur.setDate(cur.getDate() + 1);
    }

    preview.textContent = totalHours.toFixed(1) + ' hrs';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
