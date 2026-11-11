<?php
// public/sync_ldap.php - LDAP Directory Synchronization Process
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

$config = require __DIR__ . '/../config/ldap.php';

$error           = '';
$syncLog         = [];
$importedCount   = 0;
$updatedCount    = 0;
$createdEmpCount = 0;
$skippedCount    = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_sync') {
    if (!extension_loaded('ldap')) {
        $error = 'PHP LDAP extension (`ext-ldap`) is missing on the server.';
    } else {
        putenv('LDAPTLS_REQCERT=never');
        $ldapConn = @ldap_connect($config['host'], $config['port']);

        if ($ldapConn) {
            ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldapConn, LDAP_OPT_REFERRALS, 0);

            if (strpos($config['host'], 'ldaps://') === false) {
                @ldap_start_tls($ldapConn);
            }

            $bind = @ldap_bind($ldapConn, $config['bind_dn'], $config['bind_pass']);

            if ($bind) {
                $search = @ldap_search($ldapConn, $config['base_dn'], $config['user_filter']);
                if ($search) {
                    $entries = ldap_get_entries($ldapConn, $search);

                    $roleStmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = :rname LIMIT 1');
                    $roleStmt->execute(['rname' => $config['default_role_name'] ?? 'employee']);
                    $defaultRoleId = $roleStmt->fetchColumn() ?: 1;

                    for ($i = 0; $i < $entries['count']; $i++) {
                        $entry = $entries[$i];

                        $getAttr = function($key) use ($entry) {
                            if (empty($key)) return null;
                            $key = strtolower($key);
                            return isset($entry[$key][0]) ? trim($entry[$key][0]) : null;
                        };

                        $username  = $getAttr($config['attr_map']['username'] ?? 'uid');
                        $email     = $getAttr($config['attr_map']['email'] ?? 'mailprimaryaddress') 
                                  ?? $getAttr('mail') 
                                  ?? $getAttr('userprincipalname');
                        $phone     = $getAttr($config['attr_map']['phone'] ?? 'telephonenumber');
                        $cn        = $getAttr('cn');

                        $firstName = $getAttr($config['attr_map']['first_name'] ?? 'givenname');
                        $lastName  = $getAttr($config['attr_map']['last_name'] ?? 'sn');

                        if (empty($firstName) && !empty($cn)) {
                            $parts = explode(' ', $cn, 2);
                            $firstName = $parts[0] ?? '';
                            $lastName  = $parts[1] ?? '';
                        }

                        if (empty($firstName)) {
                            $firstName = !empty($username) ? ucfirst($username) : 'LDAP';
                        }
                        if (empty($lastName)) {
                            $lastName = 'User';
                        }

                        if (empty($username) || empty($email)) {
                            $skippedCount++;
                            $syncLog[] = "Skipped entry (missing username or email): " . ($entry['dn'] ?? 'Unknown DN');
                            continue;
                        }

                        try {
                            $pdo->beginTransaction();

                            $uCheck = $pdo->prepare('SELECT user_id FROM users WHERE username = :u OR email = :e LIMIT 1');
                            $uCheck->execute(['u' => $username, 'e' => $email]);
                            $existingUser = $uCheck->fetch();

                            if ($existingUser) {
                                $userId = $existingUser['user_id'];
                                $uUpdate = $pdo->prepare('UPDATE users SET is_ldap_user = 1, is_active = 1 WHERE user_id = :uid');
                                $uUpdate->execute(['uid' => $userId]);
                                $updatedCount++;
                            } else {
                                $randHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
                                $uIns = $pdo->prepare('INSERT INTO users (username, email, password_hash, is_active, is_ldap_user) VALUES (:u, :e, :h, 1, 1)');
                                $uIns->execute(['u' => $username, 'e' => $email, 'h' => $randHash]);
                                $userId = $pdo->lastInsertId();

                                $rIns = $pdo->prepare('INSERT IGNORE INTO user_account_roles (user_id, role_id) VALUES (:uid, :rid)');
                                $rIns->execute(['uid' => $userId, 'rid' => $defaultRoleId]);

                                $importedCount++;
                            }

                            $eCheck = $pdo->prepare('SELECT employee_id FROM employees WHERE user_id = :uid OR work_email = :email LIMIT 1');
                            $eCheck->execute(['uid' => $userId, 'email' => $email]);
                            $existingEmp = $eCheck->fetch();

                            if ($existingEmp) {
                                $eUpdate = $pdo->prepare('
                                    UPDATE employees 
                                    SET user_id = :uid, first_name = :fn, last_name = :ln, work_email = :email, phone_number = :phone 
                                    WHERE employee_id = :eid
                                ');
                                $eUpdate->execute([
                                    'uid'   => $userId,
                                    'fn'    => $firstName,
                                    'ln'    => $lastName,
                                    'email' => $email,
                                    'phone' => $phone,
                                    'eid'   => $existingEmp['employee_id']
                                ]);
                            } else {
                                $eIns = $pdo->prepare('
                                    INSERT INTO employees (user_id, first_name, last_name, work_email, phone_number, hire_date, employment_status, employment_type) 
                                    VALUES (:uid, :fn, :ln, :email, :phone, CURRENT_DATE(), "active", "full_time")
                                ');
                                $eIns->execute([
                                    'uid'   => $userId,
                                    'fn'    => $firstName,
                                    'ln'    => $lastName,
                                    'email' => $email,
                                    'phone' => $phone
                                ]);
                                $createdEmpCount++;
                            }

                            $pdo->commit();
                            $syncLog[] = "Synced LDAP user & employee record: {$username} ({$firstName} {$lastName})";
                        } catch (Exception $ex) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $skippedCount++;
                            $syncLog[] = "Error processing {$username}: " . $ex->getMessage();
                        }
                    }
                } else {
                    $error = "LDAP Search Failed: " . ldap_error($ldapConn);
                }
                ldap_unbind($ldapConn);
            } else {
                $error = "LDAP Bind Failed: " . ldap_error($ldapConn);
            }
        } else {
            $error = "Could not connect to LDAP Server.";
        }
    }
}

$pageTitle = 'LDAP Synchronization';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header">
        <div>
            <h2>LDAP Directory Synchronization</h2>
            <p class="subtitle">Import users and generate missing employee profiles from LDAP.</p>
        </div>
    </div>

    <!-- Two-column layout: Left sidebar + Right settings content -->
    <div style="display: flex; gap: 2rem; align-items: flex-start; margin-top: 1rem; width: 100%;">
        
        <!-- LEFT COLUMN: Side Navigation Bar -->
        <div style="width: 260px; min-width: 260px; flex-shrink: 0;">
            <?php require_once __DIR__ . '/../includes/settings_sidebar.php'; ?>
        </div>

        <!-- RIGHT COLUMN: LDAP Sync Controls & Logs -->
        <div style="flex: 1 1 auto; min-width: 0;">
            <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <div class="card">
                <h3 style="margin-top: 0; color: #2b6cb0;">Run LDAP Directory Sync</h3>
                <p>Click below to execute synchronization with <strong><?= htmlspecialchars($config['host'] ?? '') ?></strong>.</p>
                <form method="POST" action="sync_ldap.php">
                    <input type="hidden" name="action" value="run_sync">
                    <button type="submit" class="btn-submit" style="margin-top: 1rem;">Execute Synchronize Now</button>
                </form>
            </div>

            <?php if (!empty($syncLog)): ?>
                <div class="card" style="margin-top: 1.5rem;">
                    <h3 style="margin-top: 0; color: #2b6cb0;">Sync Summary</h3>
                    <p><strong>Users Imported:</strong> <?= $importedCount ?> | <strong>Users Updated:</strong> <?= $updatedCount ?> | <strong>Employee Profiles Created:</strong> <?= $createdEmpCount ?> | <strong>Skipped:</strong> <?= $skippedCount ?></p>
                    <div style="background: #1a202c; color: #e2e8f0; font-family: monospace; padding: 1rem; border-radius: 6px; max-height: 300px; overflow-y: auto;">
                        <?php foreach ($syncLog as $msg): ?>
                            <div>> <?= htmlspecialchars($msg) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
