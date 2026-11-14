<?php
// public/schedule.php - Shift Roster & Bulk Date Range Scheduling
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
            $success = 'Shift assignment removed successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete shift: ' . $e->getMessage();
        }
    } elseif ($action === 'create_schedule_range') {
        $rawEmpIds   = $_POST['employee_ids'] ?? [];
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', (array)$rawEmpIds))));
        $workingDays = array_map('intval', (array)($_POST['working_days'] ?? []));

        $shiftTemplateId = !empty($_POST['shift_template_id']) ? (int)$_POST['shift_template_id'] : null;
        $startDateStr    = $_POST['start_date'] ?? '';
        $endDateStr      = $_POST['end_date'] ?? '';
        $startTimeStr    = $_POST['start_time'] ?? '';
        $endTimeStr      = $_POST['end_time'] ?? '';

        $selectedLocation = trim($_POST['location_select'] ?? '');
        $customLocation   = trim($_POST['custom_location'] ?? '');
        $location         = ($selectedLocation === 'CUSTOM') ? $customLocation : $selectedLocation;
        if (empty($location)) {
            $location = 'Main Office';
        }

        if (empty($employeeIds) || empty($startDateStr) || empty($endDateStr) || empty($startTimeStr) || empty($endTimeStr)) {
            $error = 'Please select at least one employee, start/end dates, and start/end times.';
        } elseif (empty($workingDays)) {
            $error = 'Please keep at least one working day selected.';
        } elseif (strtotime($endDateStr) < strtotime($startDateStr)) {
            $error = 'End date cannot be earlier than start date.';
        } else {
            try {
                $pdo->beginTransaction();

                $checkShiftStmt = $pdo->prepare('
                    SELECT 1 FROM shift_assignments
                    WHERE employee_id = :employee_id AND shift_date = :shift_date
                    LIMIT 1
                ');

                $checkLeaveStmt = $pdo->prepare('
                    SELECT 1 FROM leave_requests
                    WHERE employee_id = :employee_id
                      AND status = "approved"
                      AND :shift_date BETWEEN start_date AND end_date
                    LIMIT 1
                ');

                $insertStmt = $pdo->prepare('
                    INSERT INTO shift_assignments (employee_id, shift_template_id, shift_date, start_time, end_time, location)
                    VALUES (:employee_id, :template_id, :shift_date, :start_time, :end_time, :location)
                ');

                $startTimeSql = $startTimeStr . ':00';
                $endTimeSql   = $endTimeStr . ':00';

                $insertedCount   = 0;
                $skippedLeave    = 0;
                $skippedExisting = 0;

                foreach ($employeeIds as $empId) {
                    $currentCur = new DateTime($startDateStr);
                    $endCur     = new DateTime($endDateStr);
                    $endCur->modify('+1 day');

                    while ($currentCur < $endCur) {
                        $isoDayOfWeek = (int)$currentCur->format('N'); // 1 (Mon) .. 7 (Sun)

                        if (in_array($isoDayOfWeek, $workingDays, true)) {
                            $dateFormatted = $currentCur->format('Y-m-d');

                            // Skip if employee has approved time off on this date
                            $checkLeaveStmt->execute([
                                'employee_id' => $empId,
                                'shift_date'  => $dateFormatted,
                            ]);
                            if ($checkLeaveStmt->fetch()) {
                                $skippedLeave++;
                                $currentCur->modify('+1 day');
                                continue;
                            }

                            // Skip if employee already has a shift assigned on this date
                            $checkShiftStmt->execute([
                                'employee_id' => $empId,
                                'shift_date'  => $dateFormatted,
                            ]);
                            if ($checkShiftStmt->fetch()) {
                                $skippedExisting++;
                                $currentCur->modify('+1 day');
                                continue;
                            }

                            $insertStmt->execute([
                                'employee_id' => $empId,
                                'template_id' => $shiftTemplateId,
                                'shift_date'  => $dateFormatted,
                                'start_time'  => $startTimeSql,
                                'end_time'    => $endTimeSql,
                                'location'    => $location,
                            ]);
                            $insertedCount++;
                        }

                        $currentCur->modify('+1 day');
                    }
                }

                $pdo->commit();
                $empCount = count($employeeIds);
                $success = "Scheduled {$insertedCount} shift(s) across {$empCount} employee(s).";
                if ($skippedLeave > 0) {
                    $success .= " Skipped {$skippedLeave} day(s) due to approved time off.";
                }
                if ($skippedExisting > 0) {
                    $success .= " Skipped {$skippedExisting} already-scheduled shift(s).";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Failed to generate schedule: ' . $e->getMessage();
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

$daysOfWeek = [
    1 => 'Mon',
    2 => 'Tue',
    3 => 'Wed',
    4 => 'Thu',
    5 => 'Fri',
    6 => 'Sat',
    7 => 'Sun',
];

$pageTitle = 'Shift Roster & Scheduling';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="widescreen-container" style="max-width: 1440px; margin: 0 auto; padding: 0 1.5rem 2rem;">

    <!-- TOP SYSTEM DIRECTORY HEADER -->
    <div class="directory-header" style="margin-top: 1.5rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2>Shift Roster & Schedule Generator</h2>
            <p class="subtitle">Weekly roster view • <?= date('M j, Y', strtotime($mondayDate)) ?> – <?= date('M j, Y', strtotime($sundayDate)) ?></p>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <a href="schedule.php?week=<?= $weekOffset - 1 ?>" class="btn-reset" style="text-decoration: none; font-size: 0.85rem;">← Prev Week</a>
            <a href="schedule.php?week=0" class="btn-reset" style="text-decoration: none; font-size: 0.85rem;">Current Week</a>
            <a href="schedule.php?week=<?= $weekOffset + 1 ?>" class="btn-reset" style="text-decoration: none; font-size: 0.85rem;">Next Week →</a>
        </div>
    </div>

    <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <?php if ($canManageSchedule): ?>
        <!-- SCHEDULE WORK DATE RANGE FORM -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #edf2f7; padding-bottom: 0.5rem; margin-bottom: 1.25rem;">
                <h3 style="margin: 0; color: #2b6cb0;">Schedule Work Date Range</h3>
                <span style="font-size: 0.82rem; color: #718096;">Hold Ctrl (Windows) or Cmd (Mac) to select multiple employees</span>
            </div>

            <form method="POST" action="schedule.php?week=<?= $weekOffset ?>">
                <input type="hidden" name="action" value="create_schedule_range">

                <!-- ROW 1: MULTI-SELECT EMPLOYEES, DATE RANGE & DAYS OFF -->
                <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap; align-items: flex-start;">
                    <div class="form-group" style="flex: 1.5; min-width: 240px;">
                        <label for="employee_ids" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Employees *</label>
                        <select id="employee_ids" name="employee_ids[]" multiple required size="5" style="width: 100%; padding: 0.45rem; border: 1px solid #cbd5e0; border-radius: 4px; background: #fff;">
                            <?php foreach ($employeesList as $emp): ?>
                                <option value="<?= $emp['employee_id'] ?>" style="padding: 0.25rem 0.4rem;">
                                    <?= htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']) ?> (<?= htmlspecialchars($emp['department_name'] ?? 'Unassigned') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="flex: 2; min-width: 300px; display: flex; flex-direction: column; gap: 1rem;">
                        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                            <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                <label for="start_date" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Range Start Date *</label>
                                <input type="date" id="start_date" name="start_date" required value="<?= date('Y-m-d') ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                            </div>

                            <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                <label for="end_date" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Range End Date *</label>
                                <input type="date" id="end_date" name="end_date" required value="<?= date('Y-m-d', strtotime('+13 days')) ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="font-weight: 600; font-size: 0.85rem; color: #4a5568; display: block; margin-bottom: 0.35rem;">Scheduled Days (Deselect Days Off) *</label>
                            <div style="display: flex; flex-wrap: wrap; gap: 0.45rem;">
                                <?php foreach ($daysOfWeek as $isoNum => $shortName): 
                                    $isWeekday = ($isoNum <= 5);
                                ?>
                                    <label style="display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.45rem 0.7rem; border: 2px solid <?= $isWeekday ? '#3182ce' : '#e2e8f0' ?>; border-radius: 6px; background: <?= $isWeekday ? '#ebf8ff' : '#f8fafc' ?>; color: <?= $isWeekday ? '#2b6cb0' : '#4a5568' ?>; cursor: pointer; font-size: 0.85rem; font-weight: 600; transition: all 0.15s;">
                                        <input type="checkbox" name="working_days[]" value="<?= $isoNum ?>" <?= $isWeekday ? 'checked' : '' ?> onchange="styleDayBox(this)">
                                        <?= $shortName ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ROW 2: TEMPLATE, TIMES & LOCATION -->
                <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1.4; min-width: 220px;">
                        <label for="shift_template_id" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Shift Template (Optional)</label>
                        <select id="shift_template_id" name="shift_template_id" onchange="applyTemplate(this)" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                            <option value="" data-start="" data-end="">-- Custom Shift Hours --</option>
                            <?php foreach ($shiftTemplates as $tpl): ?>
                                <option value="<?= $tpl['shift_template_id'] ?>" 
                                        data-start="<?= date('H:i', strtotime($tpl['default_start_time'])) ?>" 
                                        data-end="<?= date('H:i', strtotime($tpl['default_end_time'])) ?>">
                                    <?= htmlspecialchars($tpl['template_name']) ?> (<?= date('g:i A', strtotime($tpl['default_start_time'])) ?> – <?= date('g:i A', strtotime($tpl['default_end_time'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="flex: 0.8; min-width: 130px;">
                        <label for="start_time" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Start Time *</label>
                        <input type="time" id="start_time" name="start_time" required value="08:00" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                    </div>

                    <div class="form-group" style="flex: 0.8; min-width: 130px;">
                        <label for="end_time" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">End Time *</label>
                        <input type="time" id="end_time" name="end_time" required value="17:00" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                    </div>

                    <div class="form-group" style="flex: 1.2; min-width: 200px;">
                        <label for="location_select" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Location *</label>
                        <select id="location_select" name="location_select" onchange="toggleCustomLocation(this.value)" required style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
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
                <div class="form-group" id="custom_location_wrapper" style="display: none; margin-bottom: 1.25rem;">
                    <label for="custom_location" style="font-weight: 600; font-size: 0.85rem; color: #4a5568;">Custom Location Name *</label>
                    <input type="text" id="custom_location" name="custom_location" placeholder="e.g. Remote / Client Site" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                </div>

                <button type="submit" class="btn-submit" style="padding: 0.65rem 1.5rem; font-size: 0.95rem; width: auto;">Generate Range Schedule</button>
            </form>
        </div>
    <?php endif; ?>

    <!-- WEEKLY ROSTER GRID -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(185px, 1fr)); gap: 1rem;">
        <?php 
        for ($i = 0; $i < 7; $i++):
            $currentDayObj = clone $startDateObj;
            $currentDayObj->modify("+$i day");
            $dayDateStr = $currentDayObj->format('Y-m-d');
            $dayName    = $currentDayObj->format('l');
            $dayDisplay = $currentDayObj->format('M j');
            $isToday    = ($dayDateStr === date('Y-m-d'));
            $dayShifts  = $shiftsByDay[$dayDateStr] ?? [];
        ?>
            <div class="card" style="margin: 0; padding: 1rem; <?= $isToday ? 'border: 1px solid #bee3f8;' : '' ?>">
                <div style="border-bottom: 2px solid <?= $isToday ? '#3182ce' : '#edf2f7' ?>; padding-bottom: 0.5rem; margin-bottom: 0.85rem; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong style="color: <?= $isToday ? '#2b6cb0' : '#2d3748' ?>; font-size: 0.95rem;"><?= $dayName ?></strong>
                        <?php if ($isToday): ?>
                            <span class="badge badge-active" style="margin-left: 0.25rem; font-size: 0.7rem;">Today</span>
                        <?php endif; ?>
                    </div>
                    <span style="font-size: 0.82rem; color: #718096; font-weight: 600;"><?= $dayDisplay ?></span>
                </div>

                <?php if (!empty($dayShifts)): ?>
                    <?php foreach ($dayShifts as $s): ?>
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-left: 3px solid #3182ce; padding: 0.65rem; border-radius: 6px; margin-bottom: 0.6rem; font-size: 0.85rem;">
                            <div style="font-weight: 700; color: #1a202c;">
                                <?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?>
                            </div>

                            <?php if (!empty($s['template_name'])): ?>
                                <div style="margin-top: 0.25rem;">
                                    <span class="badge badge-shift" style="font-size: 0.72rem;">
                                        <?= htmlspecialchars($s['template_name']) ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <div style="color: #2b6cb0; font-weight: 600; margin-top: 0.35rem; font-size: 0.82rem;">
                                <?= date('g:i A', strtotime($s['start_time'])) ?> – <?= date('g:i A', strtotime($s['end_time'])) ?>
                            </div>

                            <div style="color: #718096; font-size: 0.78rem; margin-top: 0.15rem;">
                                <?= htmlspecialchars($s['location'] ?? 'Main Office') ?>
                            </div>

                            <?php if ($canManageSchedule): ?>
                                <form method="POST" action="schedule.php?week=<?= $weekOffset ?>" style="margin-top: 0.45rem; padding-top: 0.35rem; border-top: 1px solid #edf2f7; text-align: right;" onsubmit="return confirm('Remove this shift assignment?');">
                                    <input type="hidden" name="action" value="delete_shift">
                                    <input type="hidden" name="shift_id" value="<?= $s['shift_id'] ?>">
                                    <button type="submit" style="background: none; border: none; color: #e53e3e; font-size: 0.75rem; font-weight: 600; cursor: pointer; padding: 0;">Remove</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 1.75rem 0.5rem; color: #a0aec0; font-size: 0.85rem;">
                        No shifts assigned.
                    </div>
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

function styleDayBox(checkbox) {
    const label = checkbox.parentElement;
    label.style.borderColor = checkbox.checked ? '#3182ce' : '#e2e8f0';
    label.style.background  = checkbox.checked ? '#ebf8ff' : '#f8fafc';
    label.style.color       = checkbox.checked ? '#2b6cb0' : '#4a5568';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
