<?php
// public/admin_add_leave.php - Manager / Admin Manual Time-Off Entry Portal
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

// Permission check
if (!hasRole('admin') && !hasRole('manager') && !hasRole('hr_specialist')) {
    http_response_code(403);
    $pageTitle = 'Access Denied';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container"><div class="alert-danger"><strong>Access Denied:</strong> Manager privileges required.</div></div>';
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
 * Calculates net business hours (operating hours minus daily unpaid break duration).
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

$error   = '';
$success = '';

// Handle Manual Time Off Entry Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employeeId  = filter_input(INPUT_POST, 'employee_id', FILTER_VALIDATE_INT);
    $leaveTypeId = filter_input(INPUT_POST, 'leave_type_id', FILTER_VALIDATE_INT);
    $startDate   = $_POST['start_date'] ?? '';
    $endDate     = $_POST['end_date'] ?? '';
    $calculationMode = $_POST['calculation_mode'] ?? 'auto';
    $manualHours = (float)($_POST['manual_hours'] ?? 0);
    $reason      = trim($_POST['reason'] ?? '');
    $approvalStatus = $_POST['approval_status'] ?? 'approved';

    if (!$employeeId || !$leaveTypeId || empty($startDate) || empty($endDate)) {
        $error = 'Please select an employee, leave category, and valid date range.';
    } elseif (strtotime($endDate) < strtotime($startDate)) {
        $error = 'End date cannot be earlier than start date.';
    } else {
        // Calculate total hours
        if ($calculationMode === 'auto') {
            $totalHours = calculateBusinessHours($startDate, $endDate, $businessHours);
        } else {
            $totalHours = $manualHours;
        }

        if ($totalHours <= 0) {
            $error = 'Total hours must be greater than 0.';
        } else {
            try {
                $pdo->beginTransaction();

                $isApproved    = ($approvalStatus === 'approved');
                $status        = $isApproved ? 'approved' : 'pending';
                $removedShifts = 0;

                $stmt = $pdo->prepare('
                    INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, total_hours, reason, status)
                    VALUES (:emp_id, :type_id, :start_date, :end_date, :hours, :reason, :status)
                ');
                $stmt->execute([
                    'emp_id'     => $employeeId,
                    'type_id'    => $leaveTypeId,
                    'start_date' => $startDate,
                    'end_date'   => $endDate,
                    'hours'      => $totalHours,
                    'reason'     => $reason,
                    'status'     => $status
                ]);

                // If approved immediately, deduct from employee balance and remove overlapping shifts from roster
                if ($isApproved) {
                    $year = (int)date('Y', strtotime($startDate));
                    $updStmt = $pdo->prepare('
                        UPDATE leave_balances
                        SET used_hours = used_hours + :hours
                        WHERE employee_id = :emp_id AND leave_type_id = :type_id AND fiscal_year = :year
                    ');
                    $updStmt->execute([
                        'hours'   => $totalHours,
                        'emp_id'  => $employeeId,
                        'type_id' => $leaveTypeId,
                        'year'    => $year
                    ]);

                    $removeShiftsStmt = $pdo->prepare('
                        DELETE FROM shift_assignments
                        WHERE employee_id = :emp_id
                          AND shift_date BETWEEN :start_date AND :end_date
                    ');
                    $removeShiftsStmt->execute([
                        'emp_id'     => $employeeId,
                        'start_date' => $startDate,
                        'end_date'   => $endDate
                    ]);
                    $removedShifts = $removeShiftsStmt->rowCount();
                }

                $pdo->commit();
                $success = "Time off entry logged successfully ({$totalHours} net hours assigned).";
                if ($isApproved && $removedShifts > 0) {
                    $success .= " Removed {$removedShifts} conflicting shift(s) from the roster.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = 'Failed to create time off entry: ' . $e->getMessage();
            }
        }
    }
}

// Fetch Active Employees
$employees = $pdo->query('
    SELECT e.employee_id, e.first_name, e.last_name, d.department_name
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    WHERE e.employment_status = "active"
    ORDER BY e.last_name ASC, e.first_name ASC
')->fetchAll();

// Fetch Leave Types
$leaveTypes = $pdo->query('SELECT * FROM leave_types ORDER BY type_name ASC')->fetchAll();

$pageTitle = 'Add Employee Time Off';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container" style="max-width: 900px; margin: 0 auto; padding: 0 1rem;">
    <div class="directory-header" style="margin-top: 1.5rem; margin-bottom: 1.5rem;">
        <div>
            <h2>Add Employee Time Off</h2>
            <p class="subtitle">Manually record PTO, sick leave, or administrative absences for staff members.</p>
        </div>
    </div>

    <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="card">
        <h3 style="margin-top: 0; color: #2b6cb0; border-bottom: 2px solid #edf2f7; padding-bottom: 0.5rem; margin-bottom: 1.25rem;">Time Off Entry Details</h3>
        
        <form method="POST" action="admin_add_leave.php">
            
            <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap;">
                <div class="form-group" style="flex: 1; min-width: 260px;">
                    <label for="employee_id" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Select Employee *</label>
                    <select id="employee_id" name="employee_id" required style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                        <option value="">-- Choose Staff Member --</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['employee_id'] ?>">
                                <?= htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']) ?> (<?= htmlspecialchars($emp['department_name'] ?? 'Unassigned') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="flex: 1; min-width: 260px;">
                    <label for="leave_type_id" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Leave Category *</label>
                    <select id="leave_type_id" name="leave_type_id" required style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                        <option value="">-- Select Type --</option>
                        <?php foreach ($leaveTypes as $lt): ?>
                            <option value="<?= $lt['leave_type_id'] ?>"><?= htmlspecialchars($lt['type_name']) ?> <?= $lt['is_paid'] ? '(Paid)' : '(Unpaid)' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
                <div class="form-group" style="flex: 1; min-width: 220px;">
                    <label for="start_date" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Start Date *</label>
                    <input type="date" id="start_date" name="start_date" required onchange="calculateHoursPreview()" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                </div>
                <div class="form-group" style="flex: 1; min-width: 220px;">
                    <label for="end_date" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">End Date *</label>
                    <input type="date" id="end_date" name="end_date" required onchange="calculateHoursPreview()" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                </div>
            </div>

            <!-- STYLED TOGGLE 1: HOURS CALCULATION METHOD -->
            <div style="margin-bottom: 1.5rem;">
                <label style="font-weight: 600; font-size: 0.85rem; color: #4a5568; margin-bottom: 0.5rem; display: block;">Hours Calculation Method *</label>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
                    
                    <label style="display: flex; align-items: flex-start; gap: 0.75rem; background: #f8fafc; border: 2px solid #e2e8f0; padding: 0.9rem; border-radius: 6px; cursor: pointer; transition: all 0.2s;" id="label_calc_auto">
                        <input type="radio" name="calculation_mode" value="auto" checked onchange="toggleHoursMode()" style="margin-top: 0.2rem;">
                        <div>
                            <strong style="color: #2b6cb0; font-size: 0.9rem; display: block;">Auto-Calculate from Business Schedule</strong>
                            <span style="font-size: 0.8rem; color: #718096; display: block; margin-top: 0.15rem;">Automatically computes net hours based on daily company operating hours minus lunch break.</span>
                        </div>
                    </label>

                    <label style="display: flex; align-items: flex-start; gap: 0.75rem; background: #f8fafc; border: 2px solid #e2e8f0; padding: 0.9rem; border-radius: 6px; cursor: pointer; transition: all 0.2s;" id="label_calc_manual">
                        <input type="radio" name="calculation_mode" value="manual" onchange="toggleHoursMode()" style="margin-top: 0.2rem;">
                        <div>
                            <strong style="color: #2d3748; font-size: 0.9rem; display: block;">Manual Hours Override</strong>
                            <span style="font-size: 0.8rem; color: #718096; display: block; margin-top: 0.15rem;">Enter a specific total hour amount manually for custom, partial, or flexible shift absences.</span>
                        </div>
                    </label>

                </div>
            </div>

            <!-- DYNAMIC HOURS OUTPUT / INPUT CONTAINER -->
            <div style="background: #ebf8ff; border: 1px solid #bee3f8; border-radius: 6px; padding: 1.25rem; margin-bottom: 1.5rem;">
                <div id="auto_preview_box" style="text-align: center;">
                    <span style="font-size: 0.85rem; color: #2b6cb0; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Calculated Working Hours</span>
                    <div id="hours_preview" style="font-size: 1.8rem; font-weight: 700; color: #2b6cb0; margin: 0.2rem 0;">0.0 hrs</div>
                    <small style="color: #4a5568;">Includes configured operating hours minus unpaid meal breaks.</small>
                </div>

                <div id="manual_hours_box" style="display: none;">
                    <label for="manual_hours" style="font-weight: 600; font-size: 0.85rem; color: #2b6cb0;">Specify Total Hours *</label>
                    <input type="number" step="0.5" min="0.5" id="manual_hours" name="manual_hours" value="8.0" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px; font-size: 1rem; margin-top: 0.3rem;">
                </div>
            </div>

            <!-- STYLED TOGGLE 2: APPROVAL DISPOSITION -->
            <div style="margin-bottom: 1.5rem;">
                <label style="font-weight: 600; font-size: 0.85rem; color: #4a5568; margin-bottom: 0.5rem; display: block;">Approval & Ledger Disposition *</label>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
                    
                    <label style="display: flex; align-items: flex-start; gap: 0.75rem; background: #f8fafc; border: 2px solid #e2e8f0; padding: 0.9rem; border-radius: 6px; cursor: pointer; transition: all 0.2s;" id="label_app_instant">
                        <input type="radio" name="approval_status" value="approved" checked onchange="updateRadioStyles()" style="margin-top: 0.2rem;">
                        <div>
                            <strong style="color: #2b6cb0; font-size: 0.9rem; display: block;">Immediate Approval & Balance Deduction</strong>
                            <span style="font-size: 0.8rem; color: #718096; display: block; margin-top: 0.15rem;">Approve immediately and automatically deduct hours from employee's leave balance ledger.</span>
                        </div>
                    </label>

                    <label style="display: flex; align-items: flex-start; gap: 0.75rem; background: #f8fafc; border: 2px solid #e2e8f0; padding: 0.9rem; border-radius: 6px; cursor: pointer; transition: all 0.2s;" id="label_app_pending">
                        <input type="radio" name="approval_status" value="pending" onchange="updateRadioStyles()" style="margin-top: 0.2rem;">
                        <div>
                            <strong style="color: #2d3748; font-size: 0.9rem; display: block;">Submit to Pending Approval Queue</strong>
                            <span style="font-size: 0.8rem; color: #718096; display: block; margin-top: 0.15rem;">Place in the supervisor review queue without altering employee balances until formal approval.</span>
                        </div>
                    </label>

                </div>
            </div>

            <div class="form-group" style="margin-bottom: 1.75rem;">
                <label for="reason" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Reason / Manager Audit Notes</label>
                <textarea id="reason" name="reason" rows="3" placeholder="Optional notes for payroll, HR, or audit log..." style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;"></textarea>
            </div>

            <button type="submit" class="btn-submit" style="width: 100%; padding: 0.75rem; font-size: 1rem;">Save Employee Time Off</button>
        </form>
    </div>
</div>

<script>
const businessSchedule = <?= json_encode($businessHours) ?>;

function updateRadioStyles() {
    const calcAuto   = document.querySelector('input[name="calculation_mode"][value="auto"]').checked;
    const appInstant = document.querySelector('input[name="approval_status"][value="approved"]').checked;

    document.getElementById('label_calc_auto').style.borderColor   = calcAuto ? '#3182ce' : '#e2e8f0';
    document.getElementById('label_calc_auto').style.background    = calcAuto ? '#ebf8ff' : '#f8fafc';
    document.getElementById('label_calc_manual').style.borderColor = !calcAuto ? '#3182ce' : '#e2e8f0';
    document.getElementById('label_calc_manual').style.background  = !calcAuto ? '#ebf8ff' : '#f8fafc';

    document.getElementById('label_app_instant').style.borderColor = appInstant ? '#3182ce' : '#e2e8f0';
    document.getElementById('label_app_instant').style.background  = appInstant ? '#ebf8ff' : '#f8fafc';
    document.getElementById('label_app_pending').style.borderColor = !appInstant ? '#3182ce' : '#e2e8f0';
    document.getElementById('label_app_pending').style.background  = !appInstant ? '#ebf8ff' : '#f8fafc';
}

function toggleHoursMode() {
    const calcAuto = document.querySelector('input[name="calculation_mode"][value="auto"]').checked;
    document.getElementById('auto_preview_box').style.display = calcAuto ? 'block' : 'none';
    document.getElementById('manual_hours_box').style.display = calcAuto ? 'none' : 'block';
    updateRadioStyles();
}

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

// Initial style sync
updateRadioStyles();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
