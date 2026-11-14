<?php
// public/approve_leave.php - Approve or Deny Employee Time-Off Requests
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

if (!hasRole('admin') && !hasRole('manager') && !hasRole('hr_specialist')) {
    http_response_code(403);
    $pageTitle = 'Access Denied';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container"><div class="alert-danger"><strong>Access Denied:</strong> Supervisory privileges required.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$error   = '';
$success = '';
$approverEmployeeId = $_SESSION['employee_id'] ?? null;

// Handle Approval / Deny Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $action    = $_POST['action'] ?? '';

    if ($requestId && ($action === 'approved' || $action === 'rejected')) {
        try {
            $pdo->beginTransaction();

            // Fetch request details
            $reqStmt = $pdo->prepare('SELECT * FROM leave_requests WHERE request_id = :id AND status = "pending" LIMIT 1');
            $reqStmt->execute(['id' => $requestId]);
            $req = $reqStmt->fetch();

            if ($req) {
                // Update request status
                $updateStmt = $pdo->prepare('
                    UPDATE leave_requests 
                    SET status = :status, approver_id = :approver_id, updated_at = CURRENT_TIMESTAMP 
                    WHERE request_id = :id
                ');
                $updateStmt->execute([
                    'status'      => $action,
                    'approver_id' => $approverEmployeeId,
                    'id'          => $requestId
                ]);

                $removedShifts = 0;

                // If approved, update leave_balances and remove overlapping shifts from roster
                if ($action === 'approved') {
                    $year = (int)date('Y', strtotime($req['start_date']));
                    $balStmt = $pdo->prepare('
                        UPDATE leave_balances 
                        SET used_hours = used_hours + :hrs 
                        WHERE employee_id = :emp_id AND leave_type_id = :type_id AND fiscal_year = :year
                    ');
                    $balStmt->execute([
                        'hrs'     => $req['total_hours'],
                        'emp_id'  => $req['employee_id'],
                        'type_id' => $req['leave_type_id'],
                        'year'    => $year
                    ]);

                    $removeShiftsStmt = $pdo->prepare('
                        DELETE FROM shift_assignments
                        WHERE employee_id = :emp_id
                          AND shift_date BETWEEN :start_date AND :end_date
                    ');
                    $removeShiftsStmt->execute([
                        'emp_id'     => $req['employee_id'],
                        'start_date' => $req['start_date'],
                        'end_date'   => $req['end_date']
                    ]);
                    $removedShifts = $removeShiftsStmt->rowCount();
                }

                $pdo->commit();
                $success = 'Request #' . $requestId . ' has been marked as ' . ucfirst($action) . '.';
                if ($action === 'approved' && $removedShifts > 0) {
                    $success .= ' Removed ' . $removedShifts . ' conflicting shift(s) from the roster.';
                }
            } else {
                $pdo->rollBack();
                $error = 'Request not found or already processed.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Failed to process request: ' . $e->getMessage();
        }
    }
}

// Query Pending Requests
$pendingRequests = $pdo->query('
    SELECT lr.*, e.first_name, e.last_name, e.work_email, lt.type_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.employee_id
    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
    WHERE lr.status = "pending"
    ORDER BY lr.start_date ASC
')->fetchAll();

// Query Processed Requests History
$processedRequests = $pdo->query('
    SELECT lr.*, e.first_name, e.last_name, lt.type_name, 
           app.first_name AS app_first, app.last_name AS app_last
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.employee_id
    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
    LEFT JOIN employees app ON lr.approver_id = app.employee_id
    WHERE lr.status IN ("approved", "rejected")
    ORDER BY lr.updated_at DESC
    LIMIT 20
')->fetchAll();

$pageTitle = 'Approve Time Off Requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header">
        <div>
            <h2>Manage & Approve Leave Requests</h2>
            <p class="subtitle">Review pending employee time-off applications and issue decision approvals.</p>
        </div>
    </div>

    <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <!-- Pending Requests Card -->
    <div class="card" style="margin-bottom: 1.5rem;">
        <h3 style="margin-top: 0; color: #2b6cb0;">Pending Approvals (<?= count($pendingRequests) ?>)</h3>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Hours</th>
                        <th>Reason</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($pendingRequests)): ?>
                        <?php foreach ($pendingRequests as $pr): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($pr['first_name'] . ' ' . $pr['last_name']) ?></strong><br>
                                    <small style="color: #718096;"><?= htmlspecialchars($pr['work_email']) ?></small>
                                </td>
                                <td><strong><?= htmlspecialchars($pr['type_name']) ?></strong></td>
                                <td><?= date('M j, Y', strtotime($pr['start_date'])) ?> – <?= date('M j, Y', strtotime($pr['end_date'])) ?></td>
                                <td><?= number_format($pr['total_hours'], 1) ?> hrs</td>
                                <td><?= htmlspecialchars($pr['reason'] ?? 'N/A') ?></td>
                                <td>
                                    <div style="display: flex; gap: 0.5rem;">
                                        <form method="POST" action="approve_leave.php" style="margin: 0;">
                                            <input type="hidden" name="request_id" value="<?= $pr['request_id'] ?>">
                                            <input type="hidden" name="action" value="approved">
                                            <button type="submit" class="btn-submit" style="padding: 0.25rem 0.75rem; font-size: 0.85rem;">Approve</button>
                                        </form>
                                        <form method="POST" action="approve_leave.php" style="margin: 0;">
                                            <input type="hidden" name="request_id" value="<?= $pr['request_id'] ?>">
                                            <input type="hidden" name="action" value="rejected">
                                            <button type="submit" style="background: #e53e3e; color: white; border: none; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.85rem; cursor: pointer;">Deny</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #a0aec0; padding: 1.5rem;">No pending leave requests requiring approval.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Decisions Log -->
    <div class="card">
        <h3 style="margin-top: 0; color: #2b6cb0;">Recent Decisions Log</h3>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Status</th>
                        <th>Processed By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($processedRequests as $rec): ?>
                        <tr>
                            <td><?= htmlspecialchars($rec['first_name'] . ' ' . $rec['last_name']) ?></td>
                            <td><?= htmlspecialchars($rec['type_name']) ?></td>
                            <td><?= date('M j, Y', strtotime($rec['start_date'])) ?> – <?= date('M j, Y', strtotime($rec['end_date'])) ?></td>
                            <td>
                                <?php if ($rec['status'] === 'approved'): ?>
                                    <span class="badge badge-active">Approved</span>
                                <?php else: ?>
                                    <span class="badge badge-admin">Denied</span>
                                <?php endif; ?>
                            </td>
                            <td><small style="color: #718096;"><?= htmlspecialchars(($rec['app_first'] ?? 'System') . ' ' . ($rec['app_last'] ?? '')) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
