<?php
// install/install_admin.php - Step 2: Create Administrator Account
session_start();

$configFile = __DIR__ . '/../config/db.php';
if (!file_exists($configFile)) {
    header('Location: install.php');
    exit;
}

require_once $configFile;

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminUser  = trim($_POST['admin_user'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass  = $_POST['admin_pass'] ?? '';
    $adminFirst = trim($_POST['admin_first_name'] ?? 'System');
    $adminLast  = trim($_POST['admin_last_name'] ?? 'Admin');

    if (empty($adminUser) || empty($adminEmail) || empty($adminPass)) {
        $error = 'Username, email address, and password are required.';
    } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Insert Administrator User Account (is_ldap_user = 0)
            $hashedPass = password_hash($adminPass, PASSWORD_BCRYPT);
            $uStmt = $pdo->prepare('
                INSERT INTO users (username, email, password_hash, is_active, is_ldap_user) 
                VALUES (:u, :e, :p, 1, 0)
            ');
            $uStmt->execute([
                'u' => $adminUser,
                'e' => $adminEmail,
                'p' => $hashedPass
            ]);
            $adminUserId = $pdo->lastInsertId();

            // 2. Fetch Admin Role ID
            $roleLookup = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = "admin" LIMIT 1');
            $roleLookup->execute();
            $adminRoleId = $roleLookup->fetchColumn() ?: 1;

            // 3. Assign Admin Role in Junction Table
            $rStmt = $pdo->prepare('INSERT INTO user_account_roles (user_id, role_id) VALUES (:uid, :rid)');
            $rStmt->execute([
                'uid' => $adminUserId,
                'rid' => $adminRoleId
            ]);

            // 4. Create Linked Employee Profile
            $eStmt = $pdo->prepare('
                INSERT INTO employees (user_id, first_name, last_name, work_email, hire_date, employment_status, employment_type)
                VALUES (:uid, :fn, :ln, :email, CURRENT_DATE(), "active", "full_time")
            ');
            $eStmt->execute([
                'uid'   => $adminUserId,
                'fn'    => $adminFirst,
                'ln'    => $adminLast,
                'email' => $adminEmail
            ]);
            $adminEmployeeId = $pdo->lastInsertId();

            // 5. Seed PTO Balance
            $bStmt = $pdo->prepare('
                INSERT INTO leave_balances (employee_id, leave_type_id, fiscal_year, total_allocated)
                VALUES (:emp_id, 1, :year, 120.00)
            ');
            $bStmt->execute(['emp_id' => $adminEmployeeId, 'year' => (int)date('Y')]);

            $pdo->commit();
            $success = 'Super-Admin account initialized successfully!';
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Account Creation Failed: ' . $ex->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HRMS Setup - Step 2: Create Super-Admin</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #edf2f7; margin: 0; padding: 2rem 1rem; }
        .box { max-width: 550px; margin: 0 auto; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        h1 { margin-top: 0; color: #2b6cb0; font-size: 1.5rem; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; font-weight: 600; font-size: 0.85rem; margin-bottom: 0.3rem; color: #4a5568; }
        input[type="text"], input[type="password"], input[type="email"] { width: 100%; padding: 0.6rem; border: 1px solid #cbd5e0; border-radius: 4px; box-sizing: border-box; }
        .btn { background: #3182ce; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 4px; font-weight: 700; cursor: pointer; width: 100%; font-size: 1rem; }
        .btn:hover { background: #2b6cb0; }
        .alert-danger { background: #fed7d7; color: #9b2c2c; padding: 0.75rem; border-radius: 4px; margin-bottom: 1rem; }
        .alert-success { background: #c6f6d5; color: #22543d; padding: 0.75rem; border-radius: 4px; margin-bottom: 1rem; }
    </style>
</head>
<body>
<div class="box">
    <h1>Step 2: Create Administrator Account</h1>

    <?php if ($error): ?><div class="alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-success"><?= htmlspecialchars($success) ?></div>
        <a href="../public/login.php" class="btn" style="display: block; text-align: center; text-decoration: none; margin-top: 1rem;">Complete Setup & Log In</a>
    <?php else: ?>
        <form method="POST" action="install_admin.php">
            <div style="display: flex; gap: 1rem;">
                <div class="form-group" style="flex: 1;">
                    <label>First Name</label>
                    <input type="text" name="admin_first_name" value="System">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>Last Name</label>
                    <input type="text" name="admin_last_name" value="Admin">
                </div>
            </div>
            <div class="form-group">
                <label>Admin Username *</label>
                <input type="text" name="admin_user" required value="admin">
            </div>
            <div class="form-group">
                <label>Email Address *</label>
                <input type="email" name="admin_email" required value="admin@company.com">
            </div>
            <div class="form-group">
                <label>Password *</label>
                <input type="password" name="admin_pass" required minlength="6">
            </div>
            <button type="submit" class="btn">Create Administrator & Finish Setup</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
