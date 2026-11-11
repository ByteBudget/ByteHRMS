<?php
// public/schedule.php - Shift Roster & Continuous Date Range Scheduling
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$error   = '';
$success = '';

// Permission Check (Admin or Manager)
$canManageSchedule = hasRole('admin') || hasRole('manager');

// Calculate Week Navigation Date Range (Monday to Sunday)
$weekOffset = filter_input(INPUT_GET, 'week', FILTER_VALIDATE_INT) ?? 0;
$startDateObj = new DateTime('monday this week');
if ($weekOffset !== 0) {
    $startDateObj->modify("{$weekOffset} week");
}
$mondayDate = $startDateObj->format('Y-m-d');
$endDateObj = clone $startDateObj;
$endDateObj->modify('+6 days');
$sundayDate = $endDateObj->format('Y-m-d');

// Handle Schedule Actions (Create Range or Delete Shift)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageSchedule) {
    $action  = $_POST['action'] ?? '';
    $shiftId = filter_input(INPUT_POST, 'shift_id', FILTER_VALIDATE_INT);

    if ($action === 'delete_shift' && $shiftId) {
        try {
            $stmt = $pdo->prepare('DELETE FROM shift_assignments WHERE shift_id = :id');
            $stmt->execute(['id' => $shiftId]);
            $success = 'Shift assignment removed successfully!';
        } catch (Exception $e) {
            $error = 'Failed to delete shift: ' . $e->getMessage();
        }
    } elseif ($action === 'create_schedule_range') {
        $employeeId      = filter_input(INPUT_POST, 'employee_id', FILTER_VALIDATE_INT);
        $shiftTemplateId = !empty($_POST['shift_template_id']) ? (int)$_POST['shift_template_id'] : null;
        $startDateStr    = $_POST['start_date'] ?? '';
        $endDateStr      = $_POST['end_date'] ?? '';
        $startTimeStr    = $_POST['start_time'] ?? '';
        $endTimeStr      = $_POST['end_time'] ?? '';
        
        // Location dropdown handling
        $selectedLocation = trim($_POST['location_select'] ?? '');
        $customLocation   = trim($_POST['custom_location'] ?? '');
        $location         = ($selectedLocation === 'CUSTOM') ? $customLocation : $selectedLocation;
        if (empty($location)) {
            $location = 'Main Office';
        }

        if (!$employeeId || empty($startDateStr) || empty($endDateStr) || empty($startTimeStr) || empty($endTimeStr)) {
            $error = 'Employee, start/end dates, and start/end times are required.';
        } elseif (strtotime($endDateStr) < strtotime($startDateStr)) {
            $error = 'End Date cannot be earlier than Start Date.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('
                    INSERT INTO shift_assignments (employee_id, shift_template_id, shift_date, start_time, end_time, location)
                    VALUES (:employee_id, :template_id, :shift_date, :start_time, :end_time, :location)
                ');

                $currentCur = new DateTime($startDateStr);
                $endCur     = new DateTime($endDateStr);
                $endCur->modify('+1 day'); // inclusive loop limit

                $insertedCount = 0;

                // Loop through every day in the date range inclusive
                while ($currentCur < $endCur) {
                    $dateFormatted = $currentCur->format('Y-m-d');
                    $startDateTime = $dateFormatted . ' ' . $startTimeStr . ':00';
                    $endDateTime   = $dateFormatted . ' ' . $endTimeStr . ':00';

                    // Support overnight shifts crossing midnight
                    if (strtotime($endDateTime) <= strtotime($startDateTime)) {
                        $endDateTime = date('Y-m-d H:i:s', strtotime($endDateTime . ' +1 day'));
                    }

                    $stmt->execute([
                        'employee_id' => $employeeId,
                        'template_id' => $shiftTemplateId,
                        'shift_date'  => $dateFormatted,
                        'start_time'  => $startDateTime,
                        'end_time'    => $endDateTime,
                        'location'    => $location
                    ]);
                    $insertedCount++;

                    $currentCur->modify('+1 day');
                }

                $pdo->commit();
                $success = "Successfully scheduled {$insertedCount} shift(s) across the date range!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Failed to generate shift range: ' . $e->getMessage();
            }
        }
    }
}

// Fetch Shift Templates
$shiftTemplates = $pdo->query('SELECT * FROM shift_templates ORDER BY default_start_time ASC')->fetchAll();

// Fetch Active Employees
$employeesList = $pdo->query('
    SELECT e.employee_id, e.first_name, e.last_name, d.department_name 
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    WHERE e.employment_status = "active"
    ORDER BY e.last_name ASC, e.first_name ASC
')->fetchAll();

// Fetch Active Locations
$activeLocations = $pdo->query('SELECT location_id, location_name FROM locations WHERE is_active = TRUE ORDER BY location_name ASC')->fetchAll();

// Fetch Weekly Roster Shifts
$rosterStmt = $pdo->prepare('
    SELECT sa.*, e.first_name, e.last_name, d.department_name, st.template_name
    FROM shift_assignments sa
    JOIN employees e ON sa.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN shift_templates st ON sa.shift_template_id = st.shift_template_id
    WHERE sa.shift_date BETWEEN :monday AND :sunday
    ORDER BY sa.shift_date ASC, sa.start_time ASC
');
$rosterStmt->execute(['monday' => $mondayDate, 'sunday' => $sundayDate]);
$weekShifts = $rosterStmt->fetchAll();

// Group shifts by day
$shiftsByDay = [];
foreach ($weekShifts as $shift) {
    $shiftsByDay[$shift['shift_date']][] = $shift;
}

$pageTitle = 'Shift Roster & Scheduling';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header">
        <div>
            <h2>Shift Roster & Schedule Generator</h2>
            <p class="subtitle">Weekly schedule view (<?= date('M j, Y', strtotime($mondayDate)) ?> – <?= date('M j, Y', strtotime($sundayDate)) ?>)</p>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <a href="schedule.php?week=<?= $weekOffset - 1 ?>" class="btn-reset">← Prev Week</a>
            <a href="schedule.php?week=0" class="btn-reset">Current Week</a>
            <a href="schedule.php?week=<?= $weekOffset + 1 ?>" class="btn-reset">Next Week →</a>
        </div>
    </div>

    <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <?php if ($canManageSchedule): ?>
        <!-- Continuous Date Range Schedule Assignment Form -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <h3 style="margin-top: 0; color: #2b6cb0;">Schedule Work Date Range</h3>
            <form method="POST" action="schedule.php?week=<?= $weekOffset ?>">
                <input type="hidden" name="action" value="create_schedule_range">

                <div class="form-row">
                    <div class="form-group" style="flex: 1.5;">
                        <label for="employee_id">Employee *</label>
                        <select id="employee_id" name="employee_id" required>
                            <option value="">-- Select Employee --</option>
                            <?php foreach ($employeesList as $emp): ?>
                                <option value="<?= $emp['employee_id'] ?>">
                                    <?= htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']) ?> (<?= htmlspecialchars($emp['department_name'] ?? 'Unassigned') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="flex: 1;">
                        <label for="start_date">Range Start Date *</label>
                        <input type="date" id="start_date" name="start_date" required value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="form-group" style="flex: 1;">
                        <label for="end_date">Range End Date *</label>
                        <input type="date" id="end_date" name="end_date" required value="<?= date('Y-m-d', strtotime('+4 days')) ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="shift_template_id">Shift Template (Optional)</label>
                        <select id="shift_template_id" name="shift_template_id" onchange="applyTemplate(this)">
                            <option value="" data-start="" data-end="">-- Custom Hours --</option>
                            <?php foreach ($shiftTemplates as $tpl): ?>
                                <option value="<?= $tpl['shift_template_id'] ?>" 
                                        data-start="<?= date('H:i', strtotime($tpl['default_start_time'])) ?>" 
                                        data-end="<?= date('H:i', strtotime($tpl['default_end_time'])) ?>">
                                    <?= htmlspecialchars($tpl['template_name']) ?> (<?= date('g:i A', strtotime($tpl['default_start_time'])) ?> - <?= date('g:i A', strtotime($tpl['default_end_time'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="start_time">Start Time *</label>
                        <input type="time" id="start_time" name="start_time" required value="08:00">
                    </div>

                    <div class="form-group">
                        <label for="end_time">End Time *</label>
                        <input type="time" id="end_time" name="end_time" required value="16:30">
                    </div>

                    <div class="form-group">
                        <label for="location_select">Location *</label>
                        <select id="location_select" name="location_select" onchange="toggleCustomLocation(this.value)" required>
                            <option value="">-- Select Location --</option>
                            <?php foreach ($activeLocations as $loc): ?>
                                <option value="<?= htmlspecialchars($loc['location_name']) ?>">
                                    <?= htmlspecialchars($loc['location_name']) ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="CUSTOM">-- Other / Custom Location --</option>
                        </select>
                    </div>
                </div>

                <!-- Custom Location Text Wrapper -->
                <div class="form-group" id="custom_location_wrapper" style="display: none; margin-bottom: 1rem;">
                    <label for="custom_location">Custom Location Name</label>
                    <input type="text" id="custom_location" name="custom_location" placeholder="e.g. Remote / Client Site">
                </div>

                <button type="submit" class="btn-submit" style="max-width: 240px;">Generate Range Schedule</button>
            </form>
        </div>
    <?php endif; ?>

    <!-- Roster Grid -->
    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
        <?php 
        for ($i = 0; $i < 7; $i++):
            $currentDayObj = clone $startDateObj;
            $currentDayObj->modify("+$i day");
            $dayDateStr = $currentDayObj->format('Y-m-d');
            $dayName    = $currentDayObj->format('l');
            $dayDisplay = $currentDayObj->format('M j');
            $dayShifts  = $shiftsByDay[$dayDateStr] ?? [];
        ?>
            <div class="card" style="padding: 1rem;">
                <div style="border-bottom: 2px solid #3182ce; padding-bottom: 0.4rem; margin-bottom: 0.75rem; display: flex; justify-content: space-between; align-items: center;">
                    <strong style="color: #2d3748;"><?= $dayName ?></strong>
                    <span style="font-size: 0.85rem; color: #718096;"><?= $dayDisplay ?></span>
                </div>

                <?php if (!empty($dayShifts)): ?>
                    <?php foreach ($dayShifts as $s): ?>
                        <div style="background: #f7fafc; border-left: 4px solid #3182ce; padding: 0.6rem; border-radius: 4px; margin-bottom: 0.5rem; font-size: 0.85rem;">
                            <div style="font-weight: 600; color: #1a202c;">
                                <?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?>
                            </div>
                            <div style="color: #4a5568; margin-top: 0.2rem;">
                                🕒 <?= date('g:i A', strtotime($s['start_time'])) ?> - <?= date('g:i A', strtotime($s['end_time'])) ?>
                            </div>
                            <div style="color: #718096; font-size: 0.8rem; margin-top: 0.1rem;">
                                📍 <?= htmlspecialchars($s['location'] ?? 'Main Office') ?>
                            </div>

                            <?php if ($canManageSchedule): ?>
                                <form method="POST" action="schedule.php?week=<?= $weekOffset ?>" style="margin-top: 0.4rem; text-align: right;" onsubmit="return confirm('Remove this shift assignment?');">
                                    <input type="hidden" name="action" value="delete_shift">
                                    <input type="hidden" name="shift_id" value="<?= $s['shift_id'] ?>">
                                    <button type="submit" style="background: none; border: none; color: #e53e3e; font-size: 0.75rem; cursor: pointer; text-decoration: underline;">Remove</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color: #a0aec0; font-size: 0.85rem; text-align: center; margin: 1.5rem 0;">No shifts assigned.</p>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>
</div>

<script>
function applyTemplate(select) {
    const selectedOption = select.options[select.selectedIndex];
    const startTime = selectedOption.getAttribute('data-start');
    const endTime = selectedOption.getAttribute('data-end');

    if (startTime && endTime) {
        document.getElementById('start_time').value = startTime;
        document.getElementById('end_time').value = endTime;
    }
}

function toggleCustomLocation(value) {
    const customWrapper = document.getElementById('custom_location_wrapper');
    if (value === 'CUSTOM') {
        customWrapper.style.display = 'block';
        document.getElementById('custom_location').required = true;
    } else {
        customWrapper.style.display = 'none';
        document.getElementById('custom_location').required = false;
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
