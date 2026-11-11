<?php
// public/master_calendar.php - Combined Master Roster & Time-Off Calendar
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

// Determine Selected Month & Year
$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?? (int)date('m');
$year  = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? (int)date('Y');

$firstDayOfMonth = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth     = date('t', $firstDayOfMonth);
$monthName       = date('F', $firstDayOfMonth);
$dayOfWeekOffset = date('w', $firstDayOfMonth); // 0 = Sun, 6 = Sat

$startDateStr = date('Y-m-01', $firstDayOfMonth);
$endDateStr   = date('Y-m-t', $firstDayOfMonth);

// Navigation Links
$prevMonth = $month - 1;
$prevYear  = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }

$nextMonth = $month + 1;
$nextYear  = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

// 1. Fetch Time-Off Requests for selected month
$leaveStmt = $pdo->prepare('
    SELECT lr.*, e.first_name, e.last_name, lt.type_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.employee_id
    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
    WHERE lr.status IN ("approved", "pending")
      AND (lr.start_date <= :end_date AND lr.end_date >= :start_date)
    ORDER BY lr.start_date ASC
');
$leaveStmt->execute(['start_date' => $startDateStr, 'end_date' => $endDateStr]);
$monthLeave = $leaveStmt->fetchAll();

// 2. Fetch Shift Assignments for selected month
$shiftStmt = $pdo->prepare('
    SELECT sa.*, e.first_name, e.last_name
    FROM shift_assignments sa
    JOIN employees e ON sa.employee_id = e.employee_id
    WHERE sa.shift_date BETWEEN :start_date AND :end_date
    ORDER BY sa.start_time ASC
');
$shiftStmt->execute(['start_date' => $startDateStr, 'end_date' => $endDateStr]);
$monthShifts = $shiftStmt->fetchAll();

// Map events to specific dates
$calendarEvents = [];

// Map Leave Requests
foreach ($monthLeave as $req) {
    $cur  = strtotime($req['start_date']);
    $last = strtotime($req['end_date']);

    while ($cur <= $last) {
        $curDateStr = date('Y-m-d', $cur);
        if ($curDateStr >= $startDateStr && $curDateStr <= $endDateStr) {
            $calendarEvents[$curDateStr][] = [
                'type'       => 'leave',
                'status'     => $req['status'],
                'first_name' => $req['first_name'],
                'last_name'  => $req['last_name'],
                'label'      => $req['type_name']
            ];
        }
        $cur = strtotime('+1 day', $cur);
    }
}

// Map Shift Assignments
foreach ($monthShifts as $shift) {
    $curDateStr = $shift['shift_date'];
    $calendarEvents[$curDateStr][] = [
        'type'       => 'shift',
        'first_name' => $shift['first_name'],
        'last_name'  => $shift['last_name'],
        'start_time' => date('g:i A', strtotime($shift['start_time'])),
        'end_time'   => date('g:i A', strtotime($shift['end_time'])),
        'location'   => $shift['location'] ?? 'Main Office'
    ];
}

$pageTitle = 'Master Roster & Leave Calendar';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 1px;
    background: #cbd5e0;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
    overflow: hidden;
}

.calendar-header-day {
    background: #ebf8ff;
    color: #2b6cb0;
    font-weight: 700;
    text-align: center;
    padding: 0.5rem;
    font-size: 0.85rem;
}

.calendar-cell {
    background: white;
    min-height: 120px;
    padding: 0.4rem;
    box-sizing: border-box;
}

.calendar-cell.empty {
    background: #f7fafc;
}

.day-number {
    font-weight: 700;
    font-size: 0.85rem;
    color: #4a5568;
    margin-bottom: 0.3rem;
}

.event-tag {
    font-size: 0.72rem;
    padding: 0.2rem 0.4rem;
    border-radius: 4px;
    margin-bottom: 0.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Shift Tag Styling */
.event-shift {
    background: #ebf8ff;
    color: #2b6cb0;
    border-left: 3px solid #3182ce;
}

/* Leave Tag Styling */
.event-approved-leave {
    background: #fed7d7;
    color: #9b2c2c;
    border-left: 3px solid #e53e3e;
}

.event-pending-leave {
    background: #feebc8;
    color: #7b341e;
    border-left: 3px solid #dd6b20;
}
</style>

<div class="container">
    <div class="directory-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <div>
            <h2>Master Roster & Time-Off Calendar</h2>
            <p class="subtitle">Combined view of working shift schedules and employee absences.</p>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <a href="master_calendar.php?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="btn-reset" style="text-decoration: none;">← Prev</a>
            <strong style="font-size: 1.1rem; min-width: 140px; text-align: center;"><?= $monthName . ' ' . $year ?></strong>
            <a href="master_calendar.php?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="btn-reset" style="text-decoration: none;">Next →</a>
        </div>
    </div>

    <!-- Calendar Legend -->
    <div style="display: flex; gap: 1.5rem; margin-bottom: 1rem; font-size: 0.85rem;">
        <div style="display: flex; align-items: center; gap: 0.4rem;">
            <span style="display: inline-block; width: 12px; height: 12px; background: #ebf8ff; border-left: 3px solid #3182ce; border-radius: 2px;"></span>
            <span>Working Shift</span>
        </div>
        <div style="display: flex; align-items: center; gap: 0.4rem;">
            <span style="display: inline-block; width: 12px; height: 12px; background: #fed7d7; border-left: 3px solid #e53e3e; border-radius: 2px;"></span>
            <span>Approved Leave</span>
        </div>
        <div style="display: flex; align-items: center; gap: 0.4rem;">
            <span style="display: inline-block; width: 12px; height: 12px; background: #feebc8; border-left: 3px solid #dd6b20; border-radius: 2px;"></span>
            <span>Pending Leave Request</span>
        </div>
    </div>

    <div class="card" style="padding: 0;">
        <div class="calendar-grid">
            <!-- Day Columns -->
            <div class="calendar-header-day">Sun</div>
            <div class="calendar-header-day">Mon</div>
            <div class="calendar-header-day">Tue</div>
            <div class="calendar-header-day">Wed</div>
            <div class="calendar-header-day">Thu</div>
            <div class="calendar-header-day">Fri</div>
            <div class="calendar-header-day">Sat</div>

            <!-- Offset Leading Days -->
            <?php for ($i = 0; $i < $dayOfWeekOffset; $i++): ?>
                <div class="calendar-cell empty"></div>
            <?php endfor; ?>

            <!-- Calendar Days -->
            <?php for ($day = 1; $day <= $daysInMonth; $day++): 
                $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $hasEvents = isset($calendarEvents[$dateKey]);
            ?>
                <div class="calendar-cell">
                    <div class="day-number"><?= $day ?></div>
                    <?php if ($hasEvents): ?>
                        <?php foreach ($calendarEvents[$dateKey] as $ev): ?>
                            <?php if ($ev['type'] === 'shift'): ?>
                                <div class="event-tag event-shift" title="<?= htmlspecialchars($ev['first_name'] . ' ' . $ev['last_name'] . ' (' . $ev['start_time'] . ' - ' . $ev['end_time'] . ')') ?>">
                                    <strong><?= htmlspecialchars($ev['first_name']) ?></strong>: <?= $ev['start_time'] ?> - <?= $ev['end_time'] ?>
                                </div>
                            <?php else: ?>
                                <div class="event-tag <?= $ev['status'] === 'approved' ? 'event-approved-leave' : 'event-pending-leave' ?>" 
                                     title="<?= htmlspecialchars($ev['first_name'] . ' ' . $ev['last_name'] . ' - ' . $ev['label']) ?>">
                                    🌴 <strong><?= htmlspecialchars($ev['first_name']) ?></strong> (<?= htmlspecialchars($ev['label']) ?>)
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>

            <!-- Trailing Fill -->
            <?php 
                $totalCells = $dayOfWeekOffset + $daysInMonth;
                $trailingCells = (7 - ($totalCells % 7)) % 7;
                for ($j = 0; $j < $trailingCells; $j++):
            ?>
                <div class="calendar-cell empty"></div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
