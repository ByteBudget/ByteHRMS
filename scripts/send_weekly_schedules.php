<?php
// scripts/send_weekly_schedules.php - CLI Sunday Cron Job for Upcoming Week Schedules
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI execution only.');
}

require_once __DIR__ . '/../includes/db.php'; // Ensure $pdo is initialized here
require_once __DIR__ . '/../includes/schedule_mailer.php';

// When run on Sunday, "monday next week" is tomorrow (Monday) through Sunday (+6 days)
$nextMondayObj = new DateTime('monday next week');
$nextSundayObj = (clone $nextMondayObj)->modify('+6 days');

$mondayDate = $nextMondayObj->format('Y-m-d');
$sundayDate = $nextSundayObj->format('Y-m-d');

echo "[" . date('Y-m-d H:i:s') . "] Dispatching schedules for {$mondayDate} to {$sundayDate}...\n";

$summary = sendWeeklyScheduleEmails($pdo, $mondayDate, $sundayDate, false);

if ($summary['already_sent']) {
    echo "Skipped: Schedules for week of {$mondayDate} were already sent.\n";
    exit(0);
}

echo "Sent: {$summary['sent']} | Skipped (no email): {$summary['skipped_no_email']} | Errors: " . count($summary['errors']) . "\n";
foreach ($summary['errors'] as $err) {
    echo "  ERROR: {$err}\n";
}
