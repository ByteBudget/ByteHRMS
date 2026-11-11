<?php
// public/settings.php - User Account Preferences, Password & PIN Management
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$userId  = $_SESSION['user_id'];
$error   = '';
$success = '';

// Handle Settings Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Password Update Logic
    if ($action === 'update_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword     = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = 'All password fields are required.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New password and confirmation password do not match.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'New password must be at least 8 characters long.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = :id LIMIT 1');
                $stmt->execute(['id' => $userId]);
                $user = $stmt->fetch();

                if ($user && password_verify($currentPassword, $user['password_hash'])) {
                    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $updateStmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :id');
                    $updateStmt->execute([
                        'hash' => $newHash,
                        'id'   => $userId
                    ]);
                    $success = 'Your password has been updated successfully!';
                } else {
                    $error = 'Your current password was entered incorrectly.';
                }
            } catch (Exception $e) {
                $error = 'Failed to update password: ' . $e->getMessage();
            }
        }
    } 
    // 2. PIN Update Logic
    elseif ($action === 'update_pin') {
        $pin        = trim($_POST['pin_code'] ?? '');
        $pinConfirm = trim($_POST['pin_code_confirm'] ?? '');

        if (!preg_match('/^\d{4}$/', $pin)) {
            $error = 'PIN must be exactly 4 numeric digits.';
        } elseif ($pin !== $pinConfirm) {
            $error = 'PIN and PIN confirmation do not match.';
        } else {
            try {
                $pinHash = password_hash($pin, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('UPDATE users SET pin_code = :pin, is_pin_set = TRUE WHERE user_id = :id');
                $stmt->execute([
                    'pin' => $pinHash,
                    'id'  => $userId
                ]);
                $success = 'Your 4-digit Quick Punch PIN has been updated successfully!';
            } catch (Exception $e) {
                $error = 'Failed to update PIN: ' . $e->getMessage();
            }
        }
    }
}

// Check current user status for PIN setup
$userStmt = $pdo->prepare('SELECT is_pin_set FROM users WHERE user_id = :id LIMIT 1');
$userStmt->execute(['id' => $userId]);
$userRecord = $userStmt->fetch();
$isPinSet = (bool)($userRecord['is_pin_set'] ?? false);

$pageTitle = 'Account Settings & Administration';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header" style="margin-top: 1.5rem; margin-bottom: 1.5rem;">
        <div>
            <h2>Settings & System Administration</h2>
            <p class="subtitle">Manage account security, credentials, quick punch PIN, and preferences.</p>
        </div>
    </div>

    <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="settings-layout">
        <!-- Reusable Side Navigation Bar -->
        <?php require_once __DIR__ . '/../includes/settings_sidebar.php'; ?>

        <!-- Main Content Area -->
        <main class="settings-content">
            
            <!-- SECTION 1: ACCOUNT PREFERENCES -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <h3 style="margin-top: 0; color: #2b6cb0;">Account Details</h3>
                <p style="color: #718096; font-size: 0.9rem;">Your account profile information linked to HR records.</p>
                <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 1rem 0;">
                
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label style="font-weight: 600; font-size: 0.9rem;">Username</label>
                    <input type="text" disabled value="<?= htmlspecialchars($_SESSION['username'] ?? '') ?>" style="background: #edf2f7; width: 100%; max-width: 400px; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                </div>

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label style="font-weight: 600; font-size: 0.9rem;">Email Address</label>
                    <input type="email" disabled value="<?= htmlspecialchars($_SESSION['email'] ?? '') ?>" style="background: #edf2f7; width: 100%; max-width: 400px; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                </div>

                <?php if (!empty($_SESSION['employee_id'])): ?>
                    <a href="edit_employee.php?id=<?= $_SESSION['employee_id'] ?>" class="btn-reset" style="text-decoration: none; font-size: 0.9rem;">View Employee Profile</a>
                <?php endif; ?>
            </div>

            <!-- SECTION 2: QUICK PUNCH PIN SETUP -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin-top: 0; color: #2b6cb0;">Quick Punch PIN Setup</h3>
                    <?php if ($isPinSet): ?>
                        <span style="background: #c6f6d5; color: #22543d; padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.8rem; font-weight: 600;">PIN Configured</span>
                    <?php else: ?>
                        <span style="background: #feebc8; color: #7b341e; padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.8rem; font-weight: 600;">No PIN Set</span>
                    <?php endif; ?>
                </div>
                <p style="color: #718096; font-size: 0.9rem; margin-top: 0.25rem;">Set or update your 4-digit PIN for punching in/out at kiosk terminals.</p>
                <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 1rem 0;">

                <form method="POST" action="settings.php" style="max-width: 400px;">
                    <input type="hidden" name="action" value="update_pin">
                    
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label for="pin_code" style="font-weight: 600; font-size: 0.9rem;">New 4-Digit PIN *</label>
                        <input type="password" id="pin_code" name="pin_code" maxlength="4" pattern="\d{4}" required placeholder="••••" style="letter-spacing: 0.3rem; text-align: center; font-size: 1.2rem; width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                    </div>

                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label for="pin_code_confirm" style="font-weight: 600; font-size: 0.9rem;">Confirm 4-Digit PIN *</label>
                        <input type="password" id="pin_code_confirm" name="pin_code_confirm" maxlength="4" pattern="\d{4}" required placeholder="••••" style="letter-spacing: 0.3rem; text-align: center; font-size: 1.2rem; width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                    </div>

                    <button type="submit" class="btn-submit">Save PIN</button>
                </form>
            </div>

            <!-- SECTION 3: CHANGE PASSWORD -->
            <div class="card">
                <h3 style="margin-top: 0; color: #2b6cb0;">Change Account Password</h3>
                <p style="color: #718096; font-size: 0.9rem;">Update your main web portal account password.</p>
                <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 1rem 0;">

                <form method="POST" action="settings.php" style="max-width: 400px;">
                    <input type="hidden" name="action" value="update_password">

                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label for="current_password" style="font-weight: 600; font-size: 0.9rem;">Current Password *</label>
                        <input type="password" id="current_password" name="current_password" required style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                    </div>

                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label for="new_password" style="font-weight: 600; font-size: 0.9rem;">New Password * (Min. 8 characters)</label>
                        <input type="password" id="new_password" name="new_password" minlength="8" required style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                    </div>

                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label for="confirm_password" style="font-weight: 600; font-size: 0.9rem;">Confirm New Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" minlength="8" required style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 4px;">
                    </div>

                    <button type="submit" class="btn-submit">Update Password</button>
                </form>
            </div>

        </main>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
