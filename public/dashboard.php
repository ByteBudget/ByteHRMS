<?php
// public/dashboard.php - Classic System Command Center Dashboard
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$userId     = $_SESSION['user_id'] ?? null;
$employeeId = $_SESSION['employee_id'] ?? null;
$userRoles  = $_SESSION['roles'] ?? [];

// Fallback Resolution: Find Employee ID if not set in session
if (!$employeeId && $userId) {
    $findEmp = $pdo->prepare('SELECT employee_id FROM employees WHERE user_id = :user_id LIMIT 1');
    $findEmp->execute(['user_id' => $userId]);
    if ($empRow = $findEmp->fetch()) {
        $employeeId = $empRow['employee_id'];
        $_SESSION['employee_id'] = $employeeId;
    }
}

// 1. Fetch Employee Profile & Manager Details
$empDetails = null;
if ($employeeId) {
    $empStmt = $pdo->prepare('
        SELECT e.*, d.department_name, jp.job_title,
               m.first_name AS mgr_first, m.last_name AS mgr_last,
               ch.pay_rate, pt.type_name AS pay_type_name
        FROM employees e
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN job_positions jp ON e.position_id = jp.position_id
        LEFT JOIN employees m ON e.manager_id = m.employee_id
        LEFT JOIN compensation_history ch ON e.employee_id = ch.employee_id AND (ch.end_date IS NULL OR ch.end_date >= CURRENT_DATE())
        LEFT JOIN pay_types pt ON ch.pay_type_id = pt.pay_type_id
        WHERE e.employee_id = :employee_id
        LIMIT 1
    ');
    $empStmt->execute(['employee_id' => $employeeId]);
    $empDetails = $empStmt->fetch();
}

// 2. Fetch Active Time Attendance Status
$activeLog = null;
if ($employeeId) {
    $logStmt = $pdo->prepare('
        SELECT * FROM time_attendance_logs 
        WHERE employee_id = :emp_id AND clock_out IS NULL 
        ORDER BY clock_in DESC LIMIT 1
    ');
    $logStmt->execute(['emp_id' => $employeeId]);
    $activeLog = $logStmt->fetch();
}

// 3. Fetch Worked Hours Current Pay Period (Last 14 days)
$periodHours = 0.00;
$periodOvertime = 0.00;
if ($employeeId) {
    $hoursStmt = $pdo->prepare('
        SELECT SUM(total_hours_worked) AS total_worked, SUM(overtime_hours) AS total_ot
        FROM time_attendance_logs
        WHERE employee_id = :emp_id AND clock_in >= DATE_SUB(CURRENT_DATE(), INTERVAL 14 DAY)
    ');
    $hoursStmt->execute(['emp_id' => $employeeId]);
    if ($hRow = $hoursStmt->fetch()) {
        $periodHours    = (float)($hRow['total_worked'] ?? 0.00);
        $periodOvertime = (float)($hRow['total_ot'] ?? 0.00);
    }
}

// 4. Fetch Upcoming Assigned Shifts
$upcomingShifts = [];
if ($employeeId) {
    $shiftStmt = $pdo->prepare('
        SELECT sa.*, st.template_name 
        FROM shift_assignments sa
        LEFT JOIN shift_templates st ON sa.shift_template_id = st.shift_template_id
        WHERE sa.employee_id = :employee_id AND sa.shift_date >= CURRENT_DATE()
        ORDER BY sa.shift_date ASC LIMIT 6
    ');
    $shiftStmt->execute(['employee_id' => $employeeId]);
    $upcomingShifts = $shiftStmt->fetchAll();
}

// 5. Fetch Leave Balances for Current Year
$currentYear = date('Y');
$leaveBalances = [];
if ($employeeId) {
    $lbStmt = $pdo->prepare('
        SELECT lb.*, lt.type_name
        FROM leave_balances lb
        JOIN leave_types lt ON lb.leave_type_id = lt.leave_type_id
        WHERE lb.employee_id = :employee_id AND lb.fiscal_year = :year
    ');
    $lbStmt->execute(['employee_id' => $employeeId, 'year' => $currentYear]);
    $leaveBalances = $lbStmt->fetchAll();
}

// 6. Fetch Pending Approvals Queue for Supervisors
$pendingApprovals = [];
if (hasRole('admin') || hasRole('manager') || hasRole('hr_specialist')) {
    $appStmt = $pdo->query('
        SELECT lr.*, e.first_name, e.last_name, lt.type_name
        FROM leave_requests lr
        JOIN employees e ON lr.employee_id = e.employee_id
        JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
        WHERE lr.status = "pending"
        ORDER BY lr.created_at ASC LIMIT 6
    ');
    $pendingApprovals = $appStmt->fetchAll();
}

$pageTitle = 'Dashboard - Command Center';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="widescreen-container" style="max-width: 1440px; margin: 0 auto; padding: 0 1.5rem 2rem;">

    <!-- TOP SYSTEM DIRECTORY HEADER -->
    <div class="directory-header" style="margin-top: 1.5rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2>Welcome back, <?= htmlspecialchars($_SESSION['first_name'] ?? 'Employee') ?> <?= htmlspecialchars($_SESSION['last_name'] ?? '') ?>!</h2>
            <p class="subtitle"><?= date('l, F j, Y') ?> • <?= htmlspecialchars($empDetails['job_title'] ?? 'Staff Member') ?> (<?= htmlspecialchars($empDetails['department_name'] ?? 'Unassigned') ?>)</p>
        </div>
        <div>
            <?php foreach ($userRoles as $role): ?>
                <span class="badge <?= ($role === 'admin') ? 'badge-admin' : (($role === 'manager') ? 'badge-manager' : 'badge-shift') ?>" style="text-transform: capitalize; padding: 0.35rem 0.75rem; font-size: 0.85rem;">
                    <?= htmlspecialchars($role) ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- METRICS OVERVIEW CARDS -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
        
        <!-- Metric 1: Time Clock Status -->
        <div class="card" style="margin: 0; padding: 1.25rem; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <small style="color: #718096; font-weight: 600; text-transform: uppercase; font-size: 0.75rem;">Punch Status</small>
                <div style="font-size: 1.25rem; font-weight: 700; margin-top: 0.2rem;">
                    <?php if (!$activeLog): ?>
                        <span style="color: #718096;">Clocked Out</span>
                    <?php elseif ($activeLog['log_status'] === 'on_break'): ?>
                        <span style="color: #dd6b20;">On Break</span>
                    <?php else: ?>
                        <span style="color: #38a169;">Clocked In</span>
                    <?php endif; ?>
                </div>
            </div>
            <a href="time_clock.php" class="btn-submit" style="padding: 0.45rem 0.85rem; font-size: 0.85rem; text-decoration: none; width: auto;">Launch Clock →</a>
        </div>

        <!-- Metric 2: Worked Hours -->
        <div class="card" style="margin: 0; padding: 1.25rem;">
            <small style="color: #718096; font-weight: 600; text-transform: uppercase; font-size: 0.75rem;">Hours Logged (14 Days)</small>
            <div style="font-size: 1.35rem; font-weight: 700; color: #2b6cb0; margin-top: 0.2rem;">
                <?= number_format($periodHours, 2) ?> <span style="font-size: 0.85rem; font-weight: 400; color: #4a5568;">hrs</span>
                <?php if ($periodOvertime > 0): ?>
                    <span style="font-size: 0.8rem; color: #e53e3e; font-weight: 600;">(+<?= number_format($periodOvertime, 2) ?> OT)</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Metric 3: Primary Pay Rate -->
        <div class="card" style="margin: 0; padding: 1.25rem;">
            <small style="color: #718096; font-weight: 600; text-transform: uppercase; font-size: 0.75rem;">Pay Rate / Model</small>
            <div style="font-size: 1.35rem; font-weight: 700; color: #2b6cb0; margin-top: 0.2rem;">
                $<?= number_format($empDetails['pay_rate'] ?? 0, 2) ?>
                <span style="font-size: 0.85rem; font-weight: 400; color: #4a5568;">/ <?= htmlspecialchars(strtolower($empDetails['pay_type_name'] ?? 'hourly')) ?></span>
            </div>
        </div>

    </div>

    <!-- MAIN DASHBOARD CONTENT GRID -->
    <div style="display: grid; grid-template-columns: 2fr 1fr 280px; gap: 1.25rem; align-items: flex-start; margin-bottom: 1.5rem;">
        
        <!-- COLUMN 1: UPCOMING SCHEDULE TABLE -->
        <div class="card" style="margin: 0;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #edf2f7; padding-bottom: 0.5rem; margin-bottom: 1rem;">
                <h3 style="margin: 0; color: #2b6cb0;">Upcoming Shift Schedule</h3>
                <a href="schedule.php" class="btn-reset" style="font-size: 0.85rem; text-decoration: none;">Full Roster →</a>
            </div>

            <?php if (!empty($upcomingShifts)): ?>
                <div class="table-responsive">
                    <table class="table" style="width: 100%; font-size: 0.88rem;">
                        <thead>
                            <tr>
                                <th>Date & Day</th>
                                <th>Shift Pattern</th>
                                <th>Schedule Hours</th>
                                <th>Location</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcomingShifts as $shift): 
                                $isToday = ($shift['shift_date'] === date('Y-m-d'));
                            ?>
                                <tr style="<?= $isToday ? 'background: #ebf8ff; font-weight: 600;' : '' ?>">
                                    <td>
                                        <strong><?= date('M j, Y', strtotime($shift['shift_date'])) ?></strong> (<?= date('D', strtotime($shift['shift_date'])) ?>)
                                        <?php if ($isToday): ?>
                                            <span class="badge badge-active" style="margin-left: 0.3rem;">Today</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-shift">
                                            <?= htmlspecialchars($shift['template_name'] ?? 'Standard Shift') ?>
                                        </span>
                                    </td>
                                    <td><?= date('g:i A', strtotime($shift['start_time'])) ?> – <?= date('g:i A', strtotime($shift['end_time'])) ?></td>
                                    <td><?= htmlspecialchars($shift['location'] ?? 'Headquarters') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 2rem 1rem; color: #a0aec0;">
                    <p style="font-size: 0.95rem; margin: 0;">No upcoming shifts scheduled.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- COLUMN 2: LEAVE BALANCES TABLE -->
        <div class="card" style="margin: 0;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #edf2f7; padding-bottom: 0.5rem; margin-bottom: 0.75rem;">
                <h3 style="margin: 0; color: #2b6cb0;">Leave Balances (<?= $currentYear ?>)</h3>
                <a href="request_leave.php" style="font-size: 0.82rem; color: #3182ce; font-weight: 600;">Request PTO</a>
            </div>

            <?php if (!empty($leaveBalances)): ?>
                <table class="table" style="width: 100%; font-size: 0.85rem;">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Allocated</th>
                            <th>Remaining</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leaveBalances as $b): 
                            $remaining = ($b['total_allocated'] + $b['carryover_hours']) - $b['used_hours'];
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($b['type_name']) ?></strong></td>
                                <td><?= number_format($b['total_allocated'], 1) ?>h</td>
                                <td><strong style="color: #2b6cb0;"><?= number_format($remaining, 1) ?>h</strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="color: #a0aec0; font-size: 0.88rem;">No leave balances found.</p>
            <?php endif; ?>
        </div>

        <!-- COLUMN 3: QUICK NAVIGATION CARDS -->
        <div style="display: flex; flex-direction: column; gap: 1rem;">
            
            <div class="card" style="margin: 0; padding: 1.1rem;">
                <h3 style="margin-top: 0; color: #2b6cb0; font-size: 0.95rem;">Quick Navigation</h3>
                <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.75rem;">
                    <a href="request_leave.php" class="btn-reset" style="text-align: left; padding: 0.5rem 0.75rem; font-size: 0.85rem; text-decoration: none;">Request Time Off</a>
                    <?php if (hasRole('admin') || hasRole('manager') || hasRole('hr_specialist')): ?>
                        <a href="admin_time_entry.php" class="btn-reset" style="text-align: left; padding: 0.5rem 0.75rem; font-size: 0.85rem; text-decoration: none;">Timesheets Entry</a>
                        <a href="payroll_report.php" class="btn-reset" style="text-align: left; padding: 0.5rem 0.75rem; font-size: 0.85rem; text-decoration: none;">Payroll Summary Report</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card" style="margin: 0; padding: 1.1rem;">
                <h3 style="margin-top: 0; color: #2b6cb0; font-size: 0.95rem;">Profile Summary</h3>
                <div style="font-size: 0.85rem; display: flex; flex-direction: column; gap: 0.4rem; margin-top: 0.5rem;">
                    <div><span style="color: #718096;">Manager:</span> <?= htmlspecialchars($empDetails['mgr_first'] ? $empDetails['mgr_first'].' '.$empDetails['mgr_last'] : 'None') ?></div>
                    <div><span style="color: #718096;">Hire Date:</span> <?= $empDetails ? date('M j, Y', strtotime($empDetails['hire_date'])) : 'N/A' ?></div>
                    <div><span style="color: #718096;">Status:</span> <span class="badge badge-active"><?= htmlspecialchars(ucwords($empDetails['employment_status'] ?? 'active')) ?></span></div>
                </div>
            </div>

        </div>

    </div>

    <!-- BOTTOM SECTION: SUPERVISOR APPROVAL QUEUE -->
    <?php if (hasRole('admin') || hasRole('manager') || hasRole('hr_specialist')): ?>
        <div class="card" style="margin-bottom: 1rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #edf2f7; padding-bottom: 0.5rem; margin-bottom: 1rem;">
                <h3 style="margin: 0; color: #2b6cb0;">Pending Leave Approvals Queue</h3>
                <a href="approve_leave.php" class="btn-reset" style="font-size: 0.85rem; text-decoration: none;">Manage All Approvals →</a>
            </div>

            <?php if (!empty($pendingApprovals)): ?>
                <div class="table-responsive">
                    <table class="table" style="width: 100%; font-size: 0.88rem;">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Category</th>
                                <th>Dates</th>
                                <th>Total Hours</th>
                                <th>Reason / Notes</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingApprovals as $req): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($req['type_name']) ?></td>
                                    <td><?= date('M j, Y', strtotime($req['start_date'])) ?> – <?= date('M j, Y', strtotime($req['end_date'])) ?></td>
                                    <td><strong><?= number_format($req['total_hours'], 1) ?> hrs</strong></td>
                                    <td><?= htmlspecialchars($req['reason'] ?? 'N/A') ?></td>
                                    <td>
                                        <a href="approve_leave.php" style="color: #3182ce; font-weight: 600; font-size: 0.82rem;">Review Request →</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="color: #a0aec0; padding: 0.5rem 0; font-size: 0.9rem;">No pending leave requests requiring approval.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
