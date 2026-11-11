<?php
// public/admin_time_entry.php - Manager/Admin Manual Time Entry & Removal Portal
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

// Permission check
if (!hasRole('admin') && !hasRole('manager')) {
    die("Access Denied: You do not have permission to manage timesheets.");
}

$error   = '';
$success = '';

// Handle Manual Time Log Submission / Deletion / Force Clear
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $employeeId = filter_input(INPUT_POST, 'employee_id', FILTER_VALIDATE_INT);
    $clockInStr  = $_POST['clock_in'] ?? '';
    $clockOutStr = $_POST['clock_out'] ?? '';
    $breakMins   = (int)($_POST['break_duration_minutes'] ?? 0);

    if ($action === 'create_manual_entry') {
        if (!$employeeId || empty($clockInStr) || empty($clockOutStr)) {
            $error = 'Employee, clock in, and clock out dates/times are required.';
        } elseif (strtotime($clockOutStr) <= strtotime($clockInStr)) {
            $error = 'Clock Out time must be later than Clock In time.';
        } else {
            try {
                $clockInTs  = strtotime($clockInStr);
                $clockOutTs = strtotime($clockOutStr);
                $totalSec   = $clockOutTs - $clockInTs;
                $totalHours = round(($totalSec / 3600) - ($breakMins / 60), 2);
                if ($totalHours < 0) { $totalHours = 0.00; }

                $overtimeHours = ($totalHours > 8.0) ? round($totalHours - 8.0, 2) : 0.00;

                // Match against scheduled shift on that day
                $dateStr = date('Y-m-d', $clockInTs);
                $shiftMatch = $pdo->prepare('SELECT shift_id FROM shift_assignments WHERE employee_id = :e AND shift_date = :d LIMIT 1');
                $shiftMatch->execute(['e' => $employeeId, 'd' => $dateStr]);
                $shiftId = $shiftMatch->fetchColumn() ?: null;

                $stmt = $pdo->prepare('
                    INSERT INTO time_attendance_logs (employee_id, shift_id, clock_in, clock_out, break_duration_minutes, total_hours_worked, overtime_hours, log_status)
                    VALUES (:employee_id, :shift_id, :clock_in, :clock_out, :break_mins, :total_hours, :overtime, "clocked_out")
                ');
                $stmt->execute([
                    'employee_id' => $employeeId,
                    'shift_id'    => $shiftId,
                    'clock_in'    => date('Y-m-d H:i:s', $clockInTs),
                    'clock_out'   => date('Y-m-d H:i:s', $clockOutTs),
                    'break_mins'  => $breakMins,
                    'total_hours' => $totalHours,
                    'overtime'    => $overtimeHours
                ]);

                $success = 'Manual time entry logged successfully!';
            } catch (Exception $e) {
                $error = 'Failed to create time log: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_entry') {
        $logId = filter_input(INPUT_POST, 'log_id', FILTER_VALIDATE_INT);
        if ($logId) {
            try {
                $delStmt = $pdo->prepare('DELETE FROM time_attendance_logs WHERE log_id = :id');
                $delStmt->execute(['id' => $logId]);
                $success = 'Attendance record removed successfully.';
            } catch (Exception $e) {
                $error = 'Failed to delete record: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'clear_all_employee_hours') {
        // Bulk action: removes all punch records for a specific selected employee
        if ($employeeId) {
            try {
                $clearStmt = $pdo->prepare('DELETE FROM time_attendance_logs WHERE employee_id = :id');
                $clearStmt->execute(['id' => $employeeId]);
                $success = 'All attendance records for the selected employee have been cleared.';
            } catch (Exception $e) {
                $error = 'Failed to clear employee hours: ' . $e->getMessage();
            }
        } else {
            $error = 'Please select an employee to clear hours for.';
        }
    }
}

// Fetch Active Employees Dropdown
$employees = $pdo->query('
    SELECT e.employee_id, e.first_name, e.last_name, d.department_name
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    WHERE e.employment_status = "active"
    ORDER BY e.last_name ASC, e.first_name ASC
')->fetchAll();

// Fetch Recent Company-Wide Attendance Logs (Limit 50) including Active/Open Sessions
$logsStmt = $pdo->query('
    SELECT tal.*, e.first_name, e.last_name, d.department_name
    FROM time_attendance_logs tal
    JOIN employees e ON tal.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    ORDER BY tal.clock_in DESC LIMIT 50
');
$allLogs = $logsStmt->fetchAll();

$pageTitle = 'Manual Time Entry & Management';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="widescreen-container" style="max-width: 1440px; margin: 0 auto; padding: 0 1.5rem;">
    <div class="directory-header" style="margin-top: 1.5rem; margin-bottom: 1.5rem;">
        <h2>Timesheet & Manual Time Entry</h2>
        <p class="subtitle">Enter, adjust, or remove staff punch logs and overtime entries.</p>
    </div>

    <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <!-- Manual Entry Form Card -->
    <div class="card" style="margin-bottom: 1.5rem;">
        <h3 style="margin-top: 0; color: #2b6cb0; border-bottom: 2px solid #edf2f7; padding-bottom: 0.5rem;">Log Manual Time Entry</h3>
        <form method="POST" action="admin_time_entry.php">
            <input type="hidden" name="action" value="create_manual_entry">

            <div class="form-row" style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem;">
                <div class="form-group" style="flex: 1.5; min-width: 240px;">
                    <label for="employee_id">Employee *</label>
                    <select id="employee_id" name="employee_id" required style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['employee_id'] ?>">
                                <?= htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']) ?> (<?= htmlspecialchars($emp['department_name'] ?? 'Unassigned') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="flex: 1; min-width: 200px;">
                    <label for="clock_in">Clock In Date & Time *</label>
                    <input type="datetime-local" id="clock_in" name="clock_in" required style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                </div>

                <div class="form-group" style="flex: 1; min-width: 200px;">
                    <label for="clock_out">Clock Out Date & Time *</label>
                    <input type="datetime-local" id="clock_out" name="clock_out" required style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                </div>

                <div class="form-group" style="flex: 0.8; min-width: 150px;">
                    <label for="break_duration_minutes">Unpaid Break (Mins)</label>
                    <input type="number" id="break_duration_minutes" name="break_duration_minutes" value="30" min="0" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                </div>
            </div>

            <button type="submit" class="btn-submit" style="max-width: 220px;">Submit Timesheet Log</button>
        </form>
    </div>

    <!-- Clear Employee Punch Logs Card -->
    <div class="card" style="margin-bottom: 1.5rem; background: #fff5f5; border: 1px solid #feb2b2;">
        <h3 style="margin-top: 0; color: #9b2c2c; border-bottom: 2px solid #fed7d7; padding-bottom: 0.5rem;">Wipe Employee Attendance Logs</h3>
        <p style="font-size: 0.85rem; color: #742a2a; margin-bottom: 1rem;">Use this option to purge stuck, orphaned, or corrupted punch logs for a given employee.</p>
        <form method="POST" action="admin_time_entry.php" onsubmit="return confirm('WARNING: Are you sure you want to permanently delete ALL attendance logs for this employee?');" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="action" value="clear_all_employee_hours">
            
            <select name="employee_id" required style="max-width: 300px; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                <option value="">-- Select Employee to Clear --</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['employee_id'] ?>">
                        <?= htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn-submit" style="background: #e53e3e; max-width: 220px;">Purge Employee Logs</button>
        </form>
    </div>

    <!-- Company Punch Audit Log Table -->
    <div class="card">
        <h3 style="margin-top: 0; color: #2b6cb0; border-bottom: 2px solid #edf2f7; padding-bottom: 0.5rem;">Recent Master Timesheet Entries</h3>
        <?php if (!empty($allLogs)): ?>
            <div class="table-responsive">
                <table class="table" style="width: 100%; font-size: 0.88rem;">
                    <thead>
                        <tr>
                            <th>Log ID</th>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Clock In</th>
                            <th>Clock Out</th>
                            <th>Break</th>
                            <th>Hours Worked</th>
                            <th>Overtime</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allLogs as $log): ?>
                            <tr>
                                <td>#<?= $log['log_id'] ?></td>
                                <td><strong><?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?></strong></td>
                                <td><?= htmlspecialchars($log['department_name'] ?? 'Unassigned') ?></td>
                                <td><?= date('M j, Y g:i A', strtotime($log['clock_in'])) ?></td>
                                <td>
                                    <?php if ($log['clock_out']): ?>
                                        <?= date('M j, Y g:i A', strtotime($log['clock_out'])) ?>
                                    <?php elseif (($log['log_status'] ?? '') === 'on_break'): ?>
                                        <span style="color:#dd6b20; font-weight:600;">On Break</span>
                                    <?php else: ?>
                                        <span style="color:#38a169; font-weight:600;">Active Punch</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int)($log['break_duration_minutes'] ?? 0) ?> mins</td>
                                <td><?= $log['total_hours_worked'] !== null ? number_format($log['total_hours_worked'], 2) . ' hrs' : '-' ?></td>
                                <td><?= ($log['overtime_hours'] > 0) ? '<span style="color:#e53e3e; font-weight:600;">' . number_format($log['overtime_hours'], 2) . ' hrs</span>' : '0.00' ?></td>
                                <td>
                                    <form method="POST" action="admin_time_entry.php" onsubmit="return confirm('Are you sure you want to delete log #<?= $log['log_id'] ?>?');">
                                        <input type="hidden" name="action" value="delete_entry">
                                        <input type="hidden" name="log_id" value="<?= $log['log_id'] ?>">
                                        <button type="submit" style="background:none; border:none; color:#e53e3e; cursor:pointer; text-decoration:underline; font-size:0.8rem;">Delete Log</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="color: #a0aec0; padding: 1rem 0;">No attendance logs registered in system.</p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
