<?php
// includes/auth.php - Session Guard & Permission Helpers
require_once __DIR__ . '/../config/db.php';

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function hasRole($roleName) {
    $userRoles = $_SESSION['roles'] ?? [];
    return in_array($roleName, $userRoles, true);
}

function hasPermission($pdo, $permissionCode) {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    if (hasRole('admin')) {
        return true;
    }

    $stmt = $pdo->prepare('
        SELECT COUNT(*) 
        FROM user_account_roles uar
        JOIN role_permissions rp ON uar.role_id = rp.role_id
        JOIN permissions p ON rp.permission_id = p.permission_id
        WHERE uar.user_id = :user_id AND p.permission_code = :code
    ');
    $stmt->execute([
        'user_id' => $_SESSION['user_id'],
        'code'    => $permissionCode
    ]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Authenticates user credentials directly against the configured LDAP server
 */
function authenticateLdapUser($username, $password) {
    if (empty($password)) return false;
    
    $config = require __DIR__ . '/../config/ldap.php';
    putenv('LDAPTLS_REQCERT=never'); // Bypass self-signed TLS errors
    
    $ldapConn = @ldap_connect($config['host'], $config['port']);
    if (!$ldapConn) return false;

    ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldapConn, LDAP_OPT_REFERRALS, 0);

    if (strpos($config['host'], 'ldaps://') === false) {
        @ldap_start_tls($ldapConn);
    }

    // Bind using service account to locate user DN
    $serviceBind = @ldap_bind($ldapConn, $config['bind_dn'], $config['bind_pass']);
    if (!$serviceBind) return false;

    $usernameAttr = $config['attr_map']['username'] ?? 'uid';
    $filter = "(" . $usernameAttr . "=" . ldap_escape($username, "", LDAP_ESCAPE_FILTER) . ")";
    $search = @ldap_search($ldapConn, $config['base_dn'], $filter);
    
    if ($search) {
        $entries = ldap_get_entries($ldapConn, $search);
        if (($entries['count'] ?? 0) > 0) {
            $userDn = $entries[0]['dn'];
            // Re-bind as the specific user with supplied password
            $userBind = @ldap_bind($ldapConn, $userDn, $password);
            ldap_unbind($ldapConn);
            return (bool)$userBind;
        }
    }
    
    ldap_unbind($ldapConn);
    return false;
}
