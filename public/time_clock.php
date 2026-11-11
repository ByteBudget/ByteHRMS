<?php
// public/time_clock.php - Self-Service Time Attendance & Break Logging Terminal
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$userId     = $_SESSION['user_id'] ?? null;
$employeeId = $_SESSION['employee_id'] ?? null;

// Fallback: Resolve Employee ID if not set in session
if (!$employeeId && $userId) {
    $findEmp = $pdo->prepare('SELECT employee_id FROM employees WHERE user_id = :user_id LIMIT 1');
    $findEmp->execute(['user_id' => $userId]);
    if ($empRow = $findEmp->fetch()) {
        $employeeId = $empRow['employee_id'];
        $_SESSION['employee_id'] = $employeeId;
    }
}

if (!$employeeId) {
    http_response_code(403);
    $pageTitle = 'Access Denied';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container"><div class="alert-danger"><strong>Access Denied:</strong> No employee record associated with your user account.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$error   = '';
$success = '';

// Fetch Current Active Punch Record
$logStmt = $pdo->prepare('
    SELECT * FROM time_attendance_logs 
    WHERE employee_id = :emp_id AND clock_out IS NULL 
    ORDER BY clock_in DESC LIMIT 1
');
$logStmt->execute(['emp_id' => $employeeId]);
$activeLog = $logStmt->fetch();

// Fetch Today's Scheduled Shift
$todayShiftStmt = $pdo->prepare('
    SELECT sa.*, st.template_name 
    FROM shift_assignments sa
    LEFT JOIN shift_templates st ON sa.shift_template_id = st.shift_template_id
    WHERE sa.employee_id = :emp_id AND sa.shift_date = CURRENT_DATE()
    LIMIT 1
');
$todayShiftStmt->execute(['emp_id' => $employeeId]);
$todayShift = $todayShiftStmt->fetch();

// Handle Punch Actions (Clock In, Start Break, End Break, Clock Out)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'clock_in') {
            if ($activeLog) {
                $error = 'You are already clocked in.';
            } else {
                $shiftId = $todayShift['shift_id'] ?? null;
                $ins = $pdo->prepare('
                    INSERT INTO time_attendance_logs (employee_id, shift_id, clock_in, log_status)
                    VALUES (:emp_id, :shift_id, NOW(), "clocked_in")
                ');
                $ins->execute(['emp_id' => $employeeId, 'shift_id' => $shiftId]);
                $success = 'Successfully clocked IN at ' . date('g:i A') . '.';
            }
        } elseif ($action === 'start_break') {
            if (!$activeLog || $activeLog['log_status'] === 'on_break') {
                $error = 'Cannot start break. You must be actively clocked in.';
            } else {
                $upd = $pdo->prepare('
                    UPDATE time_attendance_logs 
                    SET break_start = NOW(), log_status = "on_break" 
                    WHERE log_id = :id
                ');
                $upd->execute(['id' => $activeLog['log_id']]);
                $success = 'Break started at ' . date('g:i A') . '.';
            }
        } elseif ($action === 'end_break') {
            if (!$activeLog || $activeLog['log_status'] !== 'on_break') {
                $error = 'You are not currently recorded as being on break.';
            } else {
                $bStart    = strtotime($activeLog['break_start']);
                $bEnd      = time();
                $addedMins = round(($bEnd - $bStart) / 60);
                $totalMins = (int)$activeLog['break_duration_minutes'] + $addedMins;

                $upd = $pdo->prepare('
                    UPDATE time_attendance_logs 
                    SET break_end = NOW(), 
                        break_duration_minutes = :b_mins, 
                        log_status = "clocked_in" 
                    WHERE log_id = :id
                ');
                $upd->execute(['b_mins' => $totalMins, 'id' => $activeLog['log_id']]);
                $success = 'Break ended at ' . date('g:i A') . '.';
            }
        } elseif ($action === 'clock_out') {
            if (!$activeLog) {
                $error = 'You are not currently clocked in.';
            } else {
                $clockInTs  = strtotime($activeLog['clock_in']);
                $clockOutTs = time();
                $breakMins  = (int)$activeLog['break_duration_minutes'];

                // If clocking out while still on break, finalize break minutes
                if ($activeLog['log_status'] === 'on_break' && $activeLog['break_start']) {
                    $breakMins += round(($clockOutTs - strtotime($activeLog['break_start'])) / 60);
                }

                $totalSec   = $clockOutTs - $clockInTs;
                $totalHours = round(($totalSec / 3600) - ($breakMins / 60), 2);
                if ($totalHours < 0) { $totalHours = 0.00; }
                $overtimeHours = ($totalHours > 8.0) ? round($totalHours - 8.0, 2) : 0.00;

                $upd = $pdo->prepare('
                    UPDATE time_attendance_logs 
                    SET clock_out = NOW(), 
                        break_duration_minutes = :b_mins,
                        total_hours_worked = :total_h, 
                        overtime_hours = :ot_h, 
                        log_status = "clocked_out" 
                    WHERE log_id = :id
                ');
                $upd->execute([
                    'b_mins'  => $breakMins,
                    'total_h' => $totalHours,
                    'ot_h'    => $overtimeHours,
                    'id'      => $activeLog['log_id']
                ]);
                $success = "Successfully clocked OUT at " . date('g:i A') . ". Total Net Hours: {$totalHours} hrs.";
            }
        }

        // Refresh Active Punch Log Status after action execution
        $logStmt->execute(['emp_id' => $employeeId]);
        $activeLog = $logStmt->fetch();

    } catch (Exception $e) {
        $error = 'Time clock operation failed: ' . $e->getMessage();
    }
}

// Fetch Personal Attendance Log History (Last 15 Records)
$historyStmt = $pdo->prepare('
    SELECT * FROM time_attendance_logs 
    WHERE employee_id = :emp_id 
    ORDER BY clock_in DESC LIMIT 15
');
$historyStmt->execute(['emp_id' => $employeeId]);
$recentLogs = $historyStmt->fetchAll();

$pageTitle = 'Time Clock Terminal';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 0 1rem;">
    
    <div class="directory-header" style="margin-top: 1.5rem; margin-bottom: 1.5rem;">
        <div>
            <h2>Time Clock Terminal</h2>
            <p class="subtitle">Log shift clock-ins, lunch break intervals, and clock-outs.</p>
        </div>
    </div>

    <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div style="display: flex; gap: 1.5rem; align-items: flex-start; flex-wrap: wrap;">
        
        <!-- PUNCH ACTION PANEL -->
        <div class="card" style="flex: 1; min-width: 320px;">
            <h3 style="margin-top: 0; color: #2b6cb0;">Punch Terminal</h3>

            <!-- Real-Time Clock & Status -->
            <div style="background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 1.25rem; text-align: center; margin-bottom: 1.25rem;">
                <div style="font-size: 0.85rem; color: #718096; font-weight: 600; text-transform: uppercase;">Current Time</div>
                <div id="liveClock" style="font-size: 2rem; font-weight: 700; color: #2d3748; margin: 0.2rem 0;">
                    <?= date('g:i:s A') ?>
                </div>
                <div style="font-size: 0.85rem; color: #4a5568;"><?= date('l, F j, Y') ?></div>

                <div style="margin-top: 1rem; padding-top: 0.75rem; border-top: 1px dashed #cbd5e0;">
                    <span style="font-size: 0.8rem; color: #718096; font-weight: 600;">ACTIVE STATUS:</span>
                    <div style="font-size: 1.1rem; font-weight: 700; margin-top: 0.25rem;">
                        <?php if (!$activeLog): ?>
                            <span style="color: #718096;">Clocked Out</span>
                        <?php elseif ($activeLog['log_status'] === 'on_break'): ?>
                            <span style="color: #dd6b20;">On Break ☕</span>
                            <div style="font-size: 0.78rem; color: #718096; font-weight: normal; margin-top: 0.2rem;">
                                Started: <?= date('g:i A', strtotime($activeLog['break_start'])) ?>
                            </div>
                        <?php else: ?>
                            <span style="color: #38a169;">Clocked In 🟢</span>
                            <div style="font-size: 0.78rem; color: #718096; font-weight: normal; margin-top: 0.2rem;">
                                Clocked In: <?= date('g:i A', strtotime($activeLog['clock_in'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Action Buttons Form -->
            <form method="POST" action="time_clock.php">
                <?php if (!$activeLog): ?>
                    <input type="hidden" name="action" value="clock_in">
                    <button type="submit" class="btn-submit" style="width: 100%; background: #38a169; padding: 0.85rem; font-size: 1rem;">
                        🟢 Clock IN
                    </button>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <?php if ($activeLog['log_status'] === 'on_break'): ?>
                            <button type="submit" name="action" value="end_break" class="btn-submit" style="background: #3182ce; padding: 0.8rem;">
                                ▶️ End Break
                            </button>
                        <?php else: ?>
                            <button type="submit" name="action" value="start_break" class="btn-submit" style="background: #dd6b20; padding: 0.8rem;">
                                ☕ Start Break
                            </button>
                        <?php endif; ?>

                        <button type="submit" name="action" value="clock_out" class="btn-submit" style="background: #e53e3e; padding: 0.8rem;">
                            🔴 Clock OUT
                        </button>
                    </div>
                <?php endif; ?>
            </form>

            <!-- Shift Information Card -->
            <?php if ($todayShift): ?>
                <div style="margin-top: 1.25rem; background: #ebf8ff; border: 1px solid #bee3f8; border-radius: 6px; padding: 0.75rem; font-size: 0.85rem; color: #2b6cb0;">
                    <strong>Scheduled Shift Today:</strong><br>
                    <?= htmlspecialchars($todayShift['template_name'] ?? 'Assigned Shift') ?> 
                    (<?= date('g:i A', strtotime($todayShift['start_time'])) ?> – <?= date('g:i A', strtotime($todayShift['end_time'])) ?>)
                </div>
            <?php endif; ?>
        </div>

        <!-- RECENT ATTENDANCE HISTORY TABLE -->
        <div class="card" style="flex: 1.5; min-width: 340px;">
            <h3 style="margin-top: 0; color: #2b6cb0;">Recent Attendance Logs</h3>

            <?php if (!empty($recentLogs)): ?>
                <div class="table-responsive">
                    <table class="table" style="width: 100%; font-size: 0.88rem;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Clock In</th>
                                <th>Clock Out</th>
                                <th>Break (Mins)</th>
                                <th>Net Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentLogs as $log): 
                                $inTime  = strtotime($log['clock_in']);
                                $outTime = $log['clock_out'] ? strtotime($log['clock_out']) : null;
                            ?>
                                <tr>
                                    <td><strong><?= date('M j, Y', $inTime) ?></strong></td>
                                    <td><?= date('g:i A', $inTime) ?></td>
                                    <td><?= $outTime ? date('g:i A', $outTime) : '<span style="color:#38a169; font-weight:600;">Active</span>' ?></td>
                                    <td><?= (int)$log['break_duration_minutes'] ?> mins</td>
                                    <td>
                                        <?php if ($log['total_hours_worked'] !== null): ?>
                                            <strong><?= number_format($log['total_hours_worked'], 2) ?> hrs</strong>
                                        <?php else: ?>
                                            <span style="color:#a0aec0;">--</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="color: #a0aec0; padding: 1rem 0;">No attendance records logged yet.</p>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
function updateClock() {
    const clockEl = document.getElementById('liveClock');
    if (!clockEl) return;
    const now = new Date();
    clockEl.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
setInterval(updateClock, 1000);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
