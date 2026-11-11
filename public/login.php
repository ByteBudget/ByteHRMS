<?php
// public/login.php - Hybrid Local & LDAP Authentication Handler
require_once __DIR__ . '/../includes/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        // Query user record using distinct named parameters
        $stmt = $pdo->prepare('
            SELECT user_id, username, password_hash, is_active, is_ldap_user 
            FROM users 
            WHERE username = :username OR email = :email 
            LIMIT 1
        ');
        
        // Pass both parameter keys to match the SQL placeholders
        $stmt->execute([
            'username' => $username,
            'email'    => $username
        ]);
        $user = $stmt->fetch();

        if ($user && $user['is_active']) {
            $isAuthenticated = false;

            // 1. Authenticate LDAP Synced Account
            if (!empty($user['is_ldap_user'])) {
                $isAuthenticated = authenticateLdapUser($user['username'], $password);
            } 
            // 2. Authenticate Local Account
            else {
                $isAuthenticated = password_verify($password, $user['password_hash']);
            }

            if ($isAuthenticated) {
                // Fetch User Roles
                $roleStmt = $pdo->prepare('
                    SELECT r.role_name 
                    FROM roles r 
                    JOIN user_account_roles uar ON r.role_id = uar.role_id 
                    WHERE uar.user_id = :uid
                ');
                $roleStmt->execute(['uid' => $user['user_id']]);
                $roles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);

                // Fetch Employee First Name for session
                $empStmt = $pdo->prepare('SELECT first_name FROM employees WHERE user_id = :uid LIMIT 1');
                $empStmt->execute(['uid' => $user['user_id']]);
                $emp = $empStmt->fetch();

                // Set Session State
                $_SESSION['user_id']    = $user['user_id'];
                $_SESSION['username']   = $user['username'];
                $_SESSION['first_name'] = $emp['first_name'] ?? $user['username'];
                $_SESSION['roles']      = $roles;

                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Invalid credentials provided.';
            }
        } else {
            $error = 'Invalid credentials or account disabled.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - HR System</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body style="background: #f7fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0;">
    <div class="card" style="width: 100%; max-width: 400px; padding: 2rem;">
        <h2 style="margin-top: 0; color: #2b6cb0; text-align: center;">HR Management Portal</h2>
        
        <?php if ($error): ?>
            <div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="form-group">
                <label for="username">Username or Email</label>
                <input type="text" id="username" name="username" required style="width: 100%;" autofocus>
            </div>

            <div class="form-group" style="margin-top: 1rem;">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required style="width: 100%;">
            </div>

            <button type="submit" class="btn-submit" style="width: 100%; margin-top: 1.5rem;">Sign In</button>
        </form>
    </div>
</body>
</html>
