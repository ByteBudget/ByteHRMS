<?php
// public/kiosk_clock.php - 2-Step Employee Directory & PIN Punch Kiosk
require_once __DIR__ . '/../config/db.php';

$error = '';
$success = '';
$autoReset = false;

// Step Tracking: 1 = PIN Entry, 2 = Action Selection
$step = 1;
$employeeData = null;
$activeLog = null;

// Fetch all active employees who have configured a 4-digit PIN
$activeEmployeesStmt = $pdo->query('
    SELECT u.user_id, u.username, e.first_name, e.last_name, d.department_name
    FROM users u
    JOIN employees e ON u.user_id = e.user_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    WHERE u.is_active = TRUE AND u.is_pin_set = TRUE AND e.employment_status != "terminated"
    ORDER BY e.last_name ASC, e.first_name ASC
');
$employeeDirectory = $activeEmployeesStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postStep = (int)($_POST['step'] ?? 1);
    $userId   = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $pin      = trim($_POST['pin_code'] ?? '');

    if (!$userId) {
        $error = 'Please select your name from the employee list.';
    } elseif (!preg_match('/^\d{4}$/', $pin)) {
        $error = 'Invalid PIN format. PIN must be exactly 4 numeric digits.';
    } else {
        // Authenticate User & PIN
        $userStmt = $pdo->prepare('
            SELECT u.user_id, u.pin_code, e.employee_id, e.first_name, e.last_name 
            FROM users u
            JOIN employees e ON u.user_id = e.user_id
            WHERE u.user_id = :uid AND u.is_active = TRUE
            LIMIT 1
        ');
        $userStmt->execute(['uid' => $userId]);
        $employeeData = $userStmt->fetch();

        if (!$employeeData || !password_verify($pin, $employeeData['pin_code'])) {
            $error = 'Incorrect 4-digit PIN for the selected employee.';
        } else {
            $empId = $employeeData['employee_id'];

            // Fetch Current Active Attendance Status
            $logStmt = $pdo->prepare('
                SELECT * FROM time_attendance_logs 
                WHERE employee_id = :emp_id AND clock_out IS NULL 
                ORDER BY clock_in DESC LIMIT 1
            ');
            $logStmt->execute(['emp_id' => $empId]);
            $activeLog = $logStmt->fetch();

            if ($postStep === 1) {
                // Verified PIN successfully -> Advance to Step 2 Options Page
                $step = 2;
            } elseif ($postStep === 2) {
                // Execute Punch Action on Step 2
                $action = $_POST['action'] ?? '';
                $name   = htmlspecialchars($employeeData['first_name'] . ' ' . $employeeData['last_name']);

                try {
                    if ($action === 'clock_in') {
                        if ($activeLog) {
                            $error = "{$name} is already clocked in!";
                            $step  = 2;
                        } else {
                            $shiftStmt = $pdo->prepare('SELECT shift_id FROM shift_assignments WHERE employee_id = :e AND shift_date = CURRENT_DATE() LIMIT 1');
                            $shiftStmt->execute(['e' => $empId]);
                            $shiftId = $shiftStmt->fetchColumn() ?: null;

                            $ins = $pdo->prepare('
                                INSERT INTO time_attendance_logs (employee_id, shift_id, clock_in, log_status)
                                VALUES (:e, :s, NOW(), "clocked_in")
                            ');
                            $ins->execute(['e' => $empId, 's' => $shiftId]);
                            $success   = "Welcome, {$name}! Clocked IN at " . date('g:i A');
                            $autoReset = true;
                        }
                    } elseif ($action === 'start_break') {
                        if (!$activeLog || $activeLog['log_status'] === 'on_break') {
                            $error = "Cannot start break. You must be actively clocked in.";
                            $step  = 2;
                        } else {
                            $upd = $pdo->prepare('
                                UPDATE time_attendance_logs 
                                SET break_start = NOW(), log_status = "on_break" 
                                WHERE log_id = :id
                            ');
                            $upd->execute(['id' => $activeLog['log_id']]);
                            $success   = "{$name} started break at " . date('g:i A');
                            $autoReset = true;
                        }
                    } elseif ($action === 'end_break') {
                        if (!$activeLog || $activeLog['log_status'] !== 'on_break') {
                            $error = "You are not currently recorded as being on break.";
                            $step  = 2;
                        } else {
                            $bStart = strtotime($activeLog['break_start']);
                            $bEnd   = time();
                            $addedMins = round(($bEnd - $bStart) / 60);
                            $totalBreakMins = (int)$activeLog['break_duration_minutes'] + $addedMins;

                            $upd = $pdo->prepare('
                                UPDATE time_attendance_logs 
                                SET break_end = NOW(), 
                                    break_duration_minutes = :bMins, 
                                    log_status = "clocked_in" 
                                WHERE log_id = :id
                            ');
                            $upd->execute(['bMins' => $totalBreakMins, 'id' => $activeLog['log_id']]);
                            $success   = "Welcome back {$name}! Break ended at " . date('g:i A');
                            $autoReset = true;
                        }
                    } elseif ($action === 'clock_out') {
                        if (!$activeLog) {
                            $error = "{$name} is not clocked in!";
                            $step  = 2;
                        } else {
                            $clockInTs  = strtotime($activeLog['clock_in']);
                            $clockOutTs = time();
                            $breakMins  = (int)$activeLog['break_duration_minutes'];

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
                                    break_duration_minutes = :b,
                                    total_hours_worked = :t, 
                                    overtime_hours = :o, 
                                    log_status = "clocked_out" 
                                WHERE log_id = :id
                            ');
                            $upd->execute([
                                'b'  => $breakMins,
                                't'  => $totalHours,
                                'o'  => $overtimeHours,
                                'id' => $activeLog['log_id']
                            ]);
                            $success   = "Goodbye {$name}! Clocked OUT. Net Hours: {$totalHours} hrs.";
                            $autoReset = true;
                        }
                    }
                } catch (Exception $e) {
                    $error = 'Punch operation failed: ' . $e->getMessage();
                    $step  = 2;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Punch Terminal Kiosk</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #1a202c; color: white; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 1.5rem 1rem; box-sizing: border-box; }
        .kiosk-card { background: #2d3748; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); width: 100%; max-width: 520px; text-align: center; }
        .form-select { width: 100%; padding: 0.75rem; background: #1a202c; color: #63b3ed; border: 2px solid #4a5568; border-radius: 8px; font-size: 1rem; font-weight: 600; margin-bottom: 1.25rem; box-sizing: border-box; }
        .form-select:focus { outline: none; border-color: #3182ce; }
        .pin-display { font-size: 2.2rem; letter-spacing: 0.6rem; padding: 0.5rem; background: #1a202c; border: 2px solid #4a5568; border-radius: 8px; color: #63b3ed; width: 75%; margin: 0 auto 1.25rem auto; height: 48px; line-height: 48px; }
        .keypad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.6rem; margin-bottom: 1.5rem; max-width: 360px; margin-left: auto; margin-right: auto; }
        .key-btn { background: #4a5568; color: white; border: none; font-size: 1.4rem; font-weight: 700; padding: 0.85rem; border-radius: 8px; cursor: pointer; transition: background 0.15s; }
        .key-btn:active { background: #3182ce; }
        
        .action-cards-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.85rem; text-align: left; margin-bottom: 1.5rem; }
        .action-card { background: #1a202c; border: 2px solid #4a5568; border-radius: 8px; padding: 1rem; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: flex-start; gap: 0.65rem; }
        .action-card:hover { border-color: #63b3ed; }
        .action-card.selected { border-color: #3182ce; background: #2b6cb0; }
        .action-card input[type="radio"] { margin-top: 0.25rem; }
        .action-card-title { font-weight: 700; font-size: 0.95rem; color: #ffffff; display: block; }
        .action-card-desc { font-size: 0.78rem; color: #a0aec0; display: block; margin-top: 0.15rem; }
        .action-card.selected .action-card-desc { color: #ebf8ff; }

        .btn-submit-punch { width: 100%; padding: 0.85rem; background: #3182ce; color: white; border: none; border-radius: 8px; font-weight: 700; font-size: 1.05rem; cursor: pointer; transition: background 0.2s; }
        .btn-submit-punch:hover { background: #2b6cb0; }
        .btn-cancel { background: transparent; border: 1px solid #4a5568; color: #a0aec0; padding: 0.6rem; border-radius: 6px; text-decoration: none; display: inline-block; font-size: 0.85rem; font-weight: 600; margin-top: 1rem; width: 100%; box-sizing: border-box; }

        .alert-success { background: #276749; color: #c6f6d5; padding: 0.85rem; border-radius: 6px; margin-bottom: 1.25rem; font-weight: 600; font-size: 1rem; }
        .alert-danger { background: #9b2c2c; color: #fed7d7; padding: 0.75rem; border-radius: 6px; margin-bottom: 1.25rem; font-weight: 600; }
        #timer-msg { font-size: 0.85rem; color: #cbd5e0; margin-top: 1rem; }
    </style>
</head>
<body>

<div class="kiosk-card">
    <h2 style="margin-top: 0; color: #63b3ed; font-size: 1.5rem;">Employee Quick Punch Terminal</h2>
    <div style="font-size: 1.25rem; font-weight: 600; margin-bottom: 1.25rem; color: #a0aec0;" id="kiosk-clock"><?= date('g:i:s A') ?></div>

    <?php if ($error): ?><div class="alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-success"><?= htmlspecialchars($success) ?></div>
        <div id="timer-msg">Resetting terminal screen in <span id="countdown">5</span> seconds...</div>
    <?php endif; ?>

    <?php if (!$autoReset && $step === 1): ?>
        <!-- PAGE 1: EMPLOYEE SELECTION & PIN ENTRY -->
        <form id="kioskFormStep1" method="POST" action="kiosk_clock.php">
            <input type="hidden" name="step" value="1">
            <input type="hidden" name="pin_code" id="pin_code" value="">

            <div style="text-align: left; font-size: 0.85rem; font-weight: 600; color: #a0aec0; margin-bottom: 0.4rem;">Select Employee *</div>
            <select name="user_id" id="user_id" class="form-select" required>
                <option value="">-- Choose Employee --</option>
                <?php foreach ($employeeDirectory as $emp): ?>
                    <option value="<?= $emp['user_id'] ?>">
                        <?= htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']) ?> (<?= htmlspecialchars($emp['username']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <div style="text-align: left; font-size: 0.85rem; font-weight: 600; color: #a0aec0; margin-bottom: 0.4rem;">Enter 4-Digit PIN *</div>
            <div class="pin-display" id="pin-dots">••••</div>

            <div class="keypad">
                <?php for ($i = 1; $i <= 9; $i++): ?>
                    <button type="button" class="key-btn" onclick="pressKey('<?= $i ?>')"><?= $i ?></button>
                <?php endfor; ?>
                <button type="button" class="key-btn" style="background:#e53e3e;" onclick="clearPin()">C</button>
                <button type="button" class="key-btn" onclick="pressKey('0')">0</button>
                <button type="button" class="key-btn" style="background:#718096;" onclick="backspacePin()">⌫</button>
            </div>

            <button type="button" class="btn-submit-punch" onclick="submitStep1()">Verify PIN & Continue →</button>
        </form>

    <?php elseif (!$autoReset && $step === 2 && $employeeData): ?>
        <!-- PAGE 2: PUNCH ACTION SELECTION -->
        <div style="background: #1a202c; border: 1px solid #4a5568; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; text-align: left;">
            <div style="font-size: 1.1rem; font-weight: 700; color: #63b3ed;">
                Welcome, <?= htmlspecialchars($employeeData['first_name'] . ' ' . $employeeData['last_name']) ?>! 👋
            </div>
            <div style="font-size: 0.85rem; color: #a0aec0; margin-top: 0.25rem;">
                Current Status: 
                <strong style="color: <?= !$activeLog ? '#a0aec0' : ($activeLog['log_status'] === 'on_break' ? '#fbd38d' : '#6ee7b7') ?>;">
                    <?= !$activeLog ? 'Clocked Out' : ($activeLog['log_status'] === 'on_break' ? 'On Break ☕' : 'Clocked In 🟢') ?>
                </strong>
            </div>
        </div>

        <form id="kioskFormStep2" method="POST" action="kiosk_clock.php">
            <input type="hidden" name="step" value="2">
            <input type="hidden" name="user_id" value="<?= $employeeData['user_id'] ?>">
            <input type="hidden" name="pin_code" value="<?= htmlspecialchars($_POST['pin_code'] ?? '') ?>">

            <div style="text-align: left; font-size: 0.85rem; font-weight: 600; color: #a0aec0; margin-bottom: 0.5rem;">Select Punch Action *</div>
            
            <div class="action-cards-grid">
                
                <label class="action-card selected" id="card_clock_in" onclick="selectAction('clock_in')">
                    <input type="radio" name="action" value="clock_in" checked style="accent-color: #63b3ed;">
                    <div>
                        <span class="action-card-title">🟢 Clock IN</span>
                        <span class="action-card-desc">Begin work shift</span>
                    </div>
                </label>

                <label class="action-card" id="card_start_break" onclick="selectAction('start_break')">
                    <input type="radio" name="action" value="start_break" style="accent-color: #63b3ed;">
                    <div>
                        <span class="action-card-title">☕ Start Break</span>
                        <span class="action-card-desc">Unpaid meal/break</span>
                    </div>
                </label>

                <label class="action-card" id="card_end_break" onclick="selectAction('end_break')">
                    <input type="radio" name="action" value="end_break" style="accent-color: #63b3ed;">
                    <div>
                        <span class="action-card-title">▶️ End Break</span>
                        <span class="action-card-desc">Return to work</span>
                    </div>
                </label>

                <label class="action-card" id="card_clock_out" onclick="selectAction('clock_out')">
                    <input type="radio" name="action" value="clock_out" style="accent-color: #63b3ed;">
                    <div>
                        <span class="action-card-title">🔴 Clock OUT</span>
                        <span class="action-card-desc">End work shift</span>
                    </div>
                </label>

            </div>

            <button type="submit" class="btn-submit-punch">Record Punch Now →</button>
            <a href="kiosk_clock.php" class="btn-cancel">← Cancel / Start Over</a>
        </form>
    <?php endif; ?>
</div>

<script>
let currentPin = '';

function updateDisplay() {
    const dotsEl = document.getElementById('pin-dots');
    if (!dotsEl) return;
    const dots = '•'.repeat(currentPin.length) + '•'.repeat(4 - currentPin.length);
    dotsEl.innerText = currentPin.length > 0 ? currentPin : dots;
}

function pressKey(num) {
    if (currentPin.length < 4) {
        currentPin += num;
        updateDisplay();
    }
}

function clearPin() {
    currentPin = '';
    updateDisplay();
}

function backspacePin() {
    currentPin = currentPin.slice(0, -1);
    updateDisplay();
}

function selectAction(actionVal) {
    const actions = ['clock_in', 'start_break', 'end_break', 'clock_out'];
    actions.forEach(act => {
        const card = document.getElementById('card_' + act);
        if (!card) return;
        const radio = card.querySelector('input[type="radio"]');
        if (act === actionVal) {
            card.classList.add('selected');
            radio.checked = true;
        } else {
            card.classList.remove('selected');
            radio.checked = false;
        }
    });
}

function submitStep1() {
    const userSelect = document.getElementById('user_id');
    if (!userSelect || !userSelect.value) {
        alert('Please select your name from the employee list.');
        return;
    }
    if (currentPin.length !== 4) {
        alert('Please enter your 4-digit numeric PIN.');
        return;
    }
    document.getElementById('pin_code').value = currentPin;
    document.getElementById('kioskFormStep1').submit();
}

// Live Time Ticker
setInterval(() => {
    const clockEl = document.getElementById('kiosk-clock');
    if (clockEl) clockEl.innerText = new Date().toLocaleTimeString();
}, 1000);

// Auto-reset terminal screen after 5 seconds upon punch completion
<?php if ($autoReset): ?>
let secondsLeft = 5;
const timerInterval = setInterval(() => {
    secondsLeft--;
    const cdEl = document.getElementById('countdown');
    if (cdEl) cdEl.innerText = secondsLeft;
    if (secondsLeft <= 0) {
        clearInterval(timerInterval);
        window.location.href = 'kiosk_clock.php';
    }
}, 1000);
<?php endif; ?>
</script>
</body>
</html>
