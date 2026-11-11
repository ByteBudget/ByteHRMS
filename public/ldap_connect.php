<?php
// public/ldap_connect.php - Manage & Test LDAP Server Settings
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

$configFile = __DIR__ . '/../config/ldap.php';

if (file_exists($configFile)) {
    $config = require $configFile;
} else {
    $config = [
        'host'              => 'ldap://10.0.0.2/24',
        'port'              => 389,
        'bind_dn'           => 'uid=ldap-join,cn=users,dc=company,dc=com',
        'bind_pass'         => '',
        'base_dn'           => 'dc=company,dc=com',
        'user_filter'       => '(&(objectClass=person)(memberOf=cn=users,cn=groups,dc=comapny,dc=com))',
        'attr_map'          => [
            'username'   => 'uid',
            'first_name' => 'givenname',
            'last_name'  => 'sn',
            'email'      => 'mail',
            'phone'      => 'telephonenumber',
            'guid'       => 'entryuuid',
        ],
        'default_role_name' => 'employee'
    ];
}

$error   = '';
$success = '';
$testLog = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $host        = trim($_POST['host'] ?? '');
    $port        = (int)($_POST['port'] ?? 389);
    $bindDn      = trim($_POST['bind_dn'] ?? '');
    $bindPass    = $_POST['bind_pass'] ?? '';
    $baseDn      = trim($_POST['base_dn'] ?? '');
    $userFilter  = trim($_POST['user_filter'] ?? '');
    $defaultRole = trim($_POST['default_role_name'] ?? 'employee');

    $attrUsername  = trim($_POST['attr_username'] ?? 'uid');
    $attrFirstName = trim($_POST['attr_first_name'] ?? 'givenname');
    $attrLastName  = trim($_POST['attr_last_name'] ?? 'sn');
    $attrEmail     = trim($_POST['attr_email'] ?? 'mailprimaryaddress');
    $attrPhone     = trim($_POST['attr_phone'] ?? 'telephonenumber');
    $attrGuid      = trim($_POST['attr_guid'] ?? 'entryuuid');

    $newConfig = [
        'host'         => $host,
        'port'         => $port,
        'bind_dn'      => $bindDn,
        'bind_pass'    => $bindPass,
        'base_dn'      => $baseDn,
        'user_filter'  => $userFilter,
        'attr_map'     => [
            'username'   => $attrUsername,
            'first_name' => $attrFirstName,
            'last_name'  => $attrLastName,
            'email'      => $attrEmail,
            'phone'      => $attrPhone,
            'guid'       => $attrGuid,
        ],
        'default_role_name' => $defaultRole
    ];

    $config = $newConfig;

    if ($action === 'test_connection' || $action === 'save_config') {
        if (!extension_loaded('ldap')) {
            $error = 'PHP LDAP extension (`ext-ldap`) is not installed or enabled on this server.';
        } else {
            putenv('LDAPTLS_REQCERT=never');

            $ldapConn = @ldap_connect($host, $port);
            if ($ldapConn) {
                ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3);
                ldap_set_option($ldapConn, LDAP_OPT_REFERRALS, 0);

                if (strpos($host, 'ldaps://') === false) {
                    if (@ldap_start_tls($ldapConn)) {
                        $testLog[] = "INFO: Successfully initiated StartTLS encryption.";
                    } else {
                        $testLog[] = "WARNING: StartTLS initiation failed/skipped: " . ldap_error($ldapConn);
                    }
                }

                $bind = @ldap_bind($ldapConn, $bindDn, $bindPass);
                if ($bind) {
                    $testLog[] = "SUCCESS: Authenticated (bound) successfully to {$host}:{$port}";
                    
                    $search = @ldap_search($ldapConn, $baseDn, $userFilter);
                    if ($search) {
                        $entries = ldap_get_entries($ldapConn, $search);
                        $count = $entries['count'] ?? 0;
                        $testLog[] = "SUCCESS: Search query executed. Found {$count} matching LDAP user record(s).";
                    } else {
                        $testLog[] = "WARNING: Search query failed with error: " . ldap_error($ldapConn);
                    }
                    ldap_unbind($ldapConn);
                } else {
                    $error = 'LDAP Bind Failed: ' . ldap_error($ldapConn);
                    $testLog[] = "ERROR: " . $error;
                }
            } else {
                $error = "Could not connect to LDAP host {$host}:{$port}";
                $testLog[] = "ERROR: " . $error;
            }
        }
    }

    if ($action === 'save_config' && empty($error)) {
        $exportCode = "<?php\n// config/ldap.php - LDAP Connection & Directory Settings\n\nreturn " . var_export($newConfig, true) . ";\n";
        
        if (@file_put_contents($configFile, $exportCode) !== false) {
            $success = 'LDAP configuration updated and saved successfully!';
        } else {
            $error = 'Failed to write to file `config/ldap.php`. Please verify server directory write permissions.';
        }
    }
}

$pageTitle = 'LDAP Connection Settings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="directory-header">
        <div>
            <h2>LDAP Connection & Directory Settings</h2>
            <p class="subtitle">Configure Active Directory / OpenLDAP parameters and attribute mappings.</p>
        </div>
    </div>

    <!-- Two-column layout: Left sidebar + Right settings content -->
    <div style="display: flex; gap: 2rem; align-items: flex-start; margin-top: 1rem; width: 100%;">
        
        <!-- LEFT COLUMN: Side Navigation Bar -->
        <div style="width: 260px; min-width: 260px; flex-shrink: 0;">
            <?php require_once __DIR__ . '/../includes/settings_sidebar.php'; ?>
        </div>

        <!-- RIGHT COLUMN: LDAP Connection Form & Diagnostics -->
        <div style="flex: 1 1 auto; min-width: 0;">
            <?php if ($error): ?><div class="alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <div class="card">
                <form method="POST" action="ldap_connect.php">
                    <h3 style="margin-top: 0; color: #2b6cb0;">Server & Authentication Details</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="host">LDAP Host / Connection URI *</label>
                            <input type="text" id="host" name="host" required value="<?= htmlspecialchars($config['host'] ?? '') ?>" placeholder="e.g. ldap://192.168.100.27">
                        </div>
                        <div class="form-group">
                            <label for="port">Port *</label>
                            <input type="number" id="port" name="port" required value="<?= htmlspecialchars($config['port'] ?? 389) ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="bind_dn">Bind DN (Service Account) *</label>
                            <input type="text" id="bind_dn" name="bind_dn" required value="<?= htmlspecialchars($config['bind_dn'] ?? '') ?>" placeholder="e.g. uid=ldap-join,cn=users,dc=domain,dc=net">
                        </div>
                        <div class="form-group">
                            <label for="bind_pass">Bind Password *</label>
                            <input type="password" id="bind_pass" name="bind_pass" required value="<?= htmlspecialchars($config['bind_pass'] ?? '') ?>">
                        </div>
                    </div>

                    <h3 style="margin-top: 1.5rem; color: #2b6cb0;">Directory Base & Filters</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="base_dn">Search Base DN *</label>
                            <input type="text" id="base_dn" name="base_dn" required value="<?= htmlspecialchars($config['base_dn'] ?? '') ?>" placeholder="e.g. dc=company,dc=com">
                        </div>
                        <div class="form-group">
                            <label for="default_role_name">Default System Role *</label>
                            <input type="text" id="default_role_name" name="default_role_name" required value="<?= htmlspecialchars($config['default_role_name'] ?? 'employee') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="user_filter">User Search Filter *</label>
                        <input type="text" id="user_filter" name="user_filter" required value="<?= htmlspecialchars($config['user_filter'] ?? '') ?>">
                    </div>

                    <h3 style="margin-top: 1.5rem; color: #2b6cb0;">Attribute Mapping Configuration</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="attr_username">Username Attribute</label>
                            <input type="text" id="attr_username" name="attr_username" value="<?= htmlspecialchars($config['attr_map']['username'] ?? 'uid') ?>">
                        </div>
                        <div class="form-group">
                            <label for="attr_email">Email Attribute</label>
                            <input type="text" id="attr_email" name="attr_email" value="<?= htmlspecialchars($config['attr_map']['email'] ?? 'mailprimaryaddress') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="attr_first_name">First Name Attribute</label>
                            <input type="text" id="attr_first_name" name="attr_first_name" value="<?= htmlspecialchars($config['attr_map']['first_name'] ?? 'givenname') ?>">
                        </div>
                        <div class="form-group">
                            <label for="attr_last_name">Last Name Attribute</label>
                            <input type="text" id="attr_last_name" name="attr_last_name" value="<?= htmlspecialchars($config['attr_map']['last_name'] ?? 'sn') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="attr_phone">Phone Attribute</label>
                            <input type="text" id="attr_phone" name="attr_phone" value="<?= htmlspecialchars($config['attr_map']['phone'] ?? 'telephonenumber') ?>">
                        </div>
                        <div class="form-group">
                            <label for="attr_guid">GUID / UUID Attribute</label>
                            <input type="text" id="attr_guid" name="attr_guid" value="<?= htmlspecialchars($config['attr_map']['guid'] ?? 'entryuuid') ?>">
                        </div>
                    </div>

                    <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                        <button type="submit" name="action" value="test_connection" class="btn-reset" style="flex: 1; text-align: center;">Test LDAP Connection</button>
                        <button type="submit" name="action" value="save_config" class="btn-submit" style="flex: 1;">Save Settings to config/ldap.php</button>
                    </div>
                </form>
            </div>

            <?php if (!empty($testLog)): ?>
                <div class="card" style="margin-top: 1.5rem;">
                    <h3 style="margin-top: 0; color: #2b6cb0;">Diagnostic Test Output</h3>
                    <div style="background: #1a202c; color: #68d391; font-family: monospace; padding: 1rem; border-radius: 6px; max-height: 250px; overflow-y: auto;">
                        <?php foreach ($testLog as $log): ?>
                            <div>> <?= htmlspecialchars($log) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
