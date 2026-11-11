<?php
// public/business_hours.php - Operating Business Hours & Break Settings
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

if (!hasRole('admin')) {
    http_response_code(403);
    $pageTitle = 'Access Denied';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container"><div class="alert-danger"><strong>Access Denied:</strong> Admin privileges required.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$configFile = __DIR__ . '/../config/business_hours.php';

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

if (file_exists($configFile)) {
    $businessHours = require $configFile;
} else {
    $businessHours = [
        'Monday'    => ['is_open' => true,  'open_time' => '08:00', 'close_time' => '17:00', 'break_duration' => 60, 'break_start' => '12:00', 'break_end' => '13:00'],
        'Tuesday'   => ['is_open' => true,  'open_time' => '08:00', 'close_time' => '17:00', 'break_duration' => 60, 'break_start' => '12:00', 'break_end' => '13:00'],
        'Wednesday' => ['is_open' => true,  'open_time' => '08:00', 'close_time' => '17:00', 'break_duration' => 60, 'break_start' => '12:00', 'break_end' => '13:00'],
        'Thursday'  => ['is_open' => true,  'open_time' => '08:00', 'close_time' => '17:00', 'break_duration' => 60, 'break_start' => '12:00', 'break_end' => '13:00'],
        'Friday'    => ['is_open' => true,  'open_time' => '08:00', 'close_time' => '17:00', 'break_duration' => 60, 'break_start' => '12:00', 'break_end' => '13:00'],
        'Saturday'  => ['is_open' => false, 'open_time' => '09:00', 'close_time' => '14:00', 'break_duration' => 0,  'break_start' => '',      'break_end' => ''],
        'Sunday'    => ['is_open' => false, 'open_time' => '09:00', 'close_time' => '14:00', 'break_duration' => 0,  'break_start' => '',      'break_end' => ''],
    ];
}

$error   = '';
$success = '';

// Handle Form Submissions (Update Business Hours & Break Time)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updatedHours = [];

    foreach ($days as $day) {
        $isOpen        = isset($_POST["is_open_{$day}"]);
        $openTime      = $_POST["open_time_{$day}"] ?? '08:00';
        $closeTime     = $_POST["close_time_{$day}"] ?? '17:00';
        $breakDuration = (int)($_POST["break_duration_{$day}"] ?? 0);
        $breakStart    = $_POST["break_start_{$day}"] ?? '';
        $breakEnd      = $_POST["break_end_{$day}"] ?? '';

        $updatedHours[$day] = [
            'is_open'        => $isOpen,
            'open_time'      => $openTime,
            'close_time'     => $closeTime,
            'break_duration' => $breakDuration,
            'break_start'    => $breakStart,
            'break_end'      => $breakEnd,
        ];
    }

    $exportCode = "<?php\n// config/business_hours.php - Operating Business Hours & Break Schedules\n\nreturn " . var_export($updatedHours, true) . ";\n";

    if (@file_put_contents($configFile, $exportCode) !== false) {
        $businessHours = $updatedHours;
        $success = 'Business hours and lunch break settings updated successfully.';
    } else {
        $error = 'Failed to write to `config/business_hours.php`. Please check server permissions.';
    }
}

$pageTitle = 'Business Hours & Break Configuration';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header" style="margin-top: 1.5rem; margin-bottom: 1.5rem;">
        <div>
            <h2>Business Hours & Operating Schedule</h2>
            <p class="subtitle">Configure daily open/close times, lunch break intervals, and unpaid break durations.</p>
        </div>
    </div>

    <!-- Two-column layout: Left sidebar + Right settings content -->
    <div style="display: flex; gap: 2rem; align-items: flex-start; margin-top: 1rem; width: 100%;">
        
        <!-- LEFT COLUMN: Side Navigation Bar -->
        <div style="width: 260px; min-width: 260px; flex-shrink: 0;">
            <?php require_once __DIR__ . '/../includes/settings_sidebar.php'; ?>
        </div>

        <!-- RIGHT COLUMN: Business Hours & Break Form -->
        <div style="flex: 1 1 auto; min-width: 0;">
            <?php if ($error): ?>
                <div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <div class="card">
                <h3 style="margin-top: 0; color: #2b6cb0;">Operating Hours & Lunch Break Schedule</h3>
                <form method="POST" action="business_hours.php">
                    <div class="table-responsive">
                        <table class="table" style="font-size: 0.9rem; width: 100%;">
                            <thead>
                                <tr>
                                    <th>Day of Week</th>
                                    <th>Status</th>
                                    <th>Opening Time</th>
                                    <th>Closing Time</th>
                                    <th>Break (Mins)</th>
                                    <th>Lunch Window</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($days as $day): 
                                    $dayData = $businessHours[$day] ?? [
                                        'is_open' => false, 
                                        'open_time' => '08:00', 
                                        'close_time' => '17:00',
                                        'break_duration' => 60,
                                        'break_start' => '12:00',
                                        'break_end' => '13:00'
                                    ];
                                ?>
                                    <tr>
                                        <td><strong><?= $day ?></strong></td>
                                        <td>
                                            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                                                <input type="checkbox" name="is_open_<?= $day ?>" value="1" <?= ($dayData['is_open'] ?? false) ? 'checked' : '' ?>>
                                                <span>Open</span>
                                            </label>
                                        </td>
                                        <td>
                                            <input type="time" name="open_time_<?= $day ?>" value="<?= htmlspecialchars($dayData['open_time'] ?? '08:00') ?>" style="padding: 0.3rem 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                                        </td>
                                        <td>
                                            <input type="time" name="close_time_<?= $day ?>" value="<?= htmlspecialchars($dayData['close_time'] ?? '17:00') ?>" style="padding: 0.3rem 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                                        </td>
                                        <td>
                                            <input type="number" name="break_duration_<?= $day ?>" min="0" max="240" step="5" value="<?= htmlspecialchars($dayData['break_duration'] ?? 60) ?>" placeholder="Mins" style="width: 70px; padding: 0.3rem 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.3rem; align-items: center;">
                                                <input type="time" name="break_start_<?= $day ?>" value="<?= htmlspecialchars($dayData['break_start'] ?? '12:00') ?>" style="padding: 0.3rem 0.4rem; border: 1px solid #cbd5e0; border-radius: 4px; font-size: 0.85rem;">
                                                <span>to</span>
                                                <input type="time" name="break_end_<?= $day ?>" value="<?= htmlspecialchars($dayData['break_end'] ?? '13:00') ?>" style="padding: 0.3rem 0.4rem; border: 1px solid #cbd5e0; border-radius: 4px; font-size: 0.85rem;">
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <button type="submit" class="btn-submit" style="margin-top: 1.5rem;">Save Hours & Break Settings</button>
                </form>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
