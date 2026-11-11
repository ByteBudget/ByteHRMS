<?php
// public/leave_calendar.php - Interactive Company Leave Calendar
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

// Query Leave Requests active in this month range
$stmt = $pdo->prepare('
    SELECT lr.*, e.first_name, e.last_name, lt.type_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.employee_id
    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
    WHERE lr.status IN ("approved", "pending")
      AND (lr.start_date <= :end_date AND lr.end_date >= :start_date)
    ORDER BY lr.start_date ASC
');
$stmt->execute(['start_date' => $startDateStr, 'end_date' => $endDateStr]);
$monthRequests = $stmt->fetchAll();

// Map requests to specific days
$calendarEvents = [];
foreach ($monthRequests as $req) {
    $cur = strtotime($req['start_date']);
    $last = strtotime($req['end_date']);

    while ($cur <= $last) {
        $curDateStr = date('Y-m-d', $cur);
        if ($curDateStr >= $startDateStr && $curDateStr <= $endDateStr) {
            $calendarEvents[$curDateStr][] = $req;
        }
        $cur = strtotime('+1 day', $cur);
    }
}

$pageTitle = 'Time Off Calendar';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 1px;
    background: #e2e8f0;
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
    min-height: 100px;
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
    font-size: 0.75rem;
    padding: 0.2rem 0.4rem;
    border-radius: 4px;
    margin-bottom: 0.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.event-approved {
    background: #c6f6d5;
    color: #22543d;
}

.event-pending {
    background: #feebc8;
    color: #7b341e;
}
</style>

<div class="container">
    <div class="directory-header" style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2>Company Time-Off Calendar</h2>
            <p class="subtitle">Overview of scheduled team absences and pending requests.</p>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <a href="leave_calendar.php?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="btn-reset" style="text-decoration: none;">← Prev</a>
            <strong style="font-size: 1.1rem; min-width: 140px; text-align: center;"><?= $monthName . ' ' . $year ?></strong>
            <a href="leave_calendar.php?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="btn-reset" style="text-decoration: none;">Next →</a>
        </div>
    </div>

    <div class="card">
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
                            <div class="event-tag <?= $ev['status'] === 'approved' ? 'event-approved' : 'event-pending' ?>" 
                                 title="<?= htmlspecialchars($ev['first_name'] . ' ' . $ev['last_name'] . ' - ' . $ev['type_name']) ?>">
                                <strong><?= htmlspecialchars($ev['first_name']) ?></strong> (<?= htmlspecialchars($ev['type_name']) ?>)
                            </div>
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
