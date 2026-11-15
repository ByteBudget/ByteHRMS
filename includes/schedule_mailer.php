<?php
// includes/schedule_mailer.php - Weekly Shift Schedule Email Dispatcher
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Sends upcoming Monday–Sunday shift schedules to all active scheduled employees.
 *
 * @param PDO    $pdo
 * @param string $mondayDate YYYY-MM-DD (Monday of target week)
 * @param string $sundayDate YYYY-MM-DD (Sunday of target week)
 * @param bool   $forceResend Skip duplicate-week check when triggered manually
 * @return array{sent: int, skipped_no_email: int, already_sent: bool, errors: array}
 */
function sendWeeklyScheduleEmails(PDO $pdo, string $mondayDate, string $sundayDate, bool $forceResend = false): array {
    $mailConfig = require __DIR__ . '/../config/mail.php';
    $stateFile  = __DIR__ . '/../config/schedule_mail_state.json';

    $result = [
        'sent'             => 0,
        'skipped_no_email' => 0,
        'already_sent'     => false,
        'errors'           => [],
    ];

    // Duplicate-send guard for automated Sunday runs
    $state = file_exists($stateFile) ? (json_decode(file_get_contents($stateFile), true) ?: []) : [];
    if (!$forceResend && !empty($state[$mondayDate])) {
        $result['already_sent'] = true;
        return $result;
    }

    // Fetch all shifts for the target Monday–Sunday week
    $stmt = $pdo->prepare('
        SELECT
            sa.employee_id,
            sa.shift_date,
            sa.start_time,
            sa.end_time,
            sa.location,
            st.template_name,
            e.first_name,
            e.last_name,
            COALESCE(NULLIF(TRIM(e.work_email), ""), NULLIF(TRIM(e.personal_email), "")) AS target_email
        FROM shift_assignments sa
        JOIN employees e ON sa.employee_id = e.employee_id
        LEFT JOIN shift_templates st ON sa.shift_template_id = st.shift_template_id
        WHERE e.employment_status = "active"
          AND sa.shift_date BETWEEN :monday AND :sunday
        ORDER BY e.employee_id ASC, sa.shift_date ASC, sa.start_time ASC
    ');
    $stmt->execute([
        'monday' => $mondayDate,
        'sunday' => $sundayDate,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return $result;
    }

    // Group shifts by employee_id
    $byEmployee = [];
    foreach ($rows as $r) {
        $empId = (int)$r['employee_id'];
        if (!isset($byEmployee[$empId])) {
            $byEmployee[$empId] = [
                'first_name' => $r['first_name'],
                'last_name'  => $r['last_name'],
                'email'      => $r['target_email'],
                'shifts'     => [],
            ];
        }
        $byEmployee[$empId]['shifts'][] = $r;
    }

    $weekLabel = date('M j, Y', strtotime($mondayDate)) . ' – ' . date('M j, Y', strtotime($sundayDate));
    $portalUrl = htmlspecialchars($mailConfig['portal_url'] ?? 'dashboard.php');

    foreach ($byEmployee as $empId => $empData) {
        if (empty($empData['email'])) {
            $result['skipped_no_email']++;
            continue;
        }

        // Build shift table rows
        $rowsHtml = '';
        $plainLines = [];
        foreach ($empData['shifts'] as $s) {
            $dayStr   = date('l, M j', strtotime($s['shift_date']));
            $timeStr  = date('g:i A', strtotime($s['start_time'])) . ' – ' . date('g:i A', strtotime($s['end_time']));
            $shiftLbl = htmlspecialchars($s['template_name'] ?? 'Work Shift');
            $locStr   = htmlspecialchars($s['location'] ?? 'Main Office');

            $rowsHtml .= "
                <tr>
                    <td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0; font-weight: 600; color: #2d3748;'>{$dayStr}</td>
                    <td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0; color: #2b6cb0; font-weight: 600;'>{$timeStr}</td>
                    <td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0; color: #4a5568;'>{$shiftLbl}</td>
                    <td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0; color: #718096;'>{$locStr}</td>
                </tr>";

            $plainLines[] = "- {$dayStr}: {$timeStr} ({$shiftLbl} @ {$locStr})";
        }

        $firstName = htmlspecialchars($empData['first_name']);
        $htmlBody = "
        <div style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif; max-width: 640px; margin: 0 auto; color: #2d3748;'>
            <div style='background: #2b6cb0; color: #ffffff; padding: 18px 24px; border-radius: 8px 8px 0 0;'>
                <h2 style='margin: 0; font-size: 18px;'>Your Work Schedule: {$weekLabel}</h2>
            </div>
            <div style='border: 1px solid #e2e8f0; border-top: none; padding: 20px 24px; border-radius: 0 0 8px 8px; background: #ffffff;'>
                <p style='margin-top: 0;'>Hi {$firstName},</p>
                <p>Here are your scheduled shifts for the upcoming week (<strong>{$weekLabel}</strong>):</p>

                <table style='width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 14px;'>
                    <thead>
                        <tr style='background: #f8fafc; text-align: left;'>
                            <th style='padding: 10px 12px; border-bottom: 2px solid #cbd5e0; color: #4a5568;'>Day</th>
                            <th style='padding: 10px 12px; border-bottom: 2px solid #cbd5e0; color: #4a5568;'>Hours</th>
                            <th style='padding: 10px 12px; border-bottom: 2px solid #cbd5e0; color: #4a5568;'>Shift</th>
                            <th style='padding: 10px 12px; border-bottom: 2px solid #cbd5e0; color: #4a5568;'>Location</th>
                        </tr>
                    </thead>
                    <tbody>{$rowsHtml}</tbody>
                </table>

                <p style='margin: 20px 0 12px;'>
                    <a href='{$portalUrl}' style='display: inline-block; background: #3182ce; color: #ffffff; text-decoration: none; padding: 10px 18px; border-radius: 6px; font-weight: 600; font-size: 14px;'>
                        View Live Dashboard Calendar →
                    </a>
                </p>
                <p style='font-size: 12px; color: #718096; margin-bottom: 0;'>
                    Approved time off is automatically excluded. Check your live dashboard if mid-week adjustments occur.
                </p>
            </div>
        </div>";

        $plainBody = "Hi {$empData['first_name']},\n\n"
                   . "Here are your scheduled shifts for {$weekLabel}:\n\n"
                   . implode("\n", $plainLines) . "\n\n"
                   . "View your live calendar: " . ($mailConfig['portal_url'] ?? '') . "\n";

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $mailConfig['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $mailConfig['smtp_user'];
            $mail->Password   = $mailConfig['smtp_pass'];
            $mail->SMTPSecure = $mailConfig['smtp_secure'];
            $mail->Port       = (int)$mailConfig['smtp_port'];

            $mail->setFrom($mailConfig['from_email'], $mailConfig['from_name']);
            $mail->addAddress($empData['email'], $empData['first_name'] . ' ' . $empData['last_name']);

            $mail->isHTML(true);
            $mail->Subject = "Your Work Schedule ({$weekLabel})";
            $mail->Body    = $htmlBody;
            $mail->AltBody = $plainBody;

            $mail->send();
            $result['sent']++;
        } catch (MailException $e) {
            $result['errors'][] = "Employee #{$empId} ({$empData['email']}): " . $mail->ErrorInfo;
        }
    }

    // Record sent timestamp so duplicate Sunday runs won't re-send
    if ($result['sent'] > 0) {
        $state[$mondayDate] = date('c');
        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }

    return $result;
}
