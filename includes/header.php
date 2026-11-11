<?php
// includes/header.php - Global Layout Header & Navigation
require_once __DIR__ . '/auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'HR Management System') ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<?php if (isset($_SESSION['user_id'])): ?>
<nav class="navbar">
    <div class="nav-brand">
        <a href="dashboard.php" style="color: white; text-decoration: none; font-weight: 700;">HR Management Portal</a>
    </div>

    <div class="nav-menu">
        <a href="dashboard.php" class="nav-link">Dashboard</a>
        <a href="time_clock.php" class="nav-link">Time Clock</a>
        <a href="schedule.php" class="nav-link">Schedule</a>
        <a href="request_leave.php" class="nav-link">Request Time Off</a>
        
        <!-- Manager Role Dropdown -->
        <?php if (hasRole('manager') || hasRole('admin') || hasRole('hr_specialist')): ?>
            <div class="dropdown">
                <button class="dropdown-toggle">Manager ▼</button>
		<div class="dropdown-menu">
		    <a href="admin_time_entry.php">Timesheets & Time Entry</a>
		    <a href="payroll_report.php">Payroll & Wage Summary</a>
		    <a href="kiosk_clock.php" target="_blank">Launch Kiosk PIN Terminal ↗</a>
		    <a href="schedule.php">Schedules Roster</a>
		    <a href="employees.php">Staff Directory</a>
		    <div class="dropdown-divider"></div>
		    <a href="admin_add_leave.php">Add Employee Time Off</a>
		    <a href="approve_leave.php">Approve Time Off</a>
		    <a href="leave_calendar.php">Leave Calendar</a>
		</div>
            </div>
        <?php endif; ?>
    </div>

    <div class="user-info">
        Signed in as <strong><?= htmlspecialchars($_SESSION['first_name'] ?? 'User') ?></strong>
        <a href="settings.php" class="nav-link" style="margin-left: 1rem;">Settings</a>
        <a href="logout.php" class="btn-logout" style="margin-left: 0.5rem;">Logout</a>
    </div>
</nav>
<?php endif; ?>

<main class="main-content">
