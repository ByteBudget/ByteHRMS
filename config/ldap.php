<?php
// config/ldap.php - Production LDAP / Active Directory Configuration

return [
    // Primary LDAP Server details
    'host'         => 'ldap://', // Use 'ldaps://' on port 636 if SSL is enforced
    'port'         => 389,                     // Standard: 389, SSL: 636, Custom: 7389
    
    // Service Account Bind Credentials
    'bind_dn'      => '',
    'bind_pass'    => '',
    
    // Search Base DN
    'base_dn'      => '',
    
    // Optimized User Filter:
    // Uses objectClass=person or inetOrgPerson to catch standard directory user accounts.
    // If group restriction returns 0 results, replace with: '(&(objectClass=person)(uid=*))'
    'user_filter'  => '',
    
    // Security and TLS Options
    'use_tls'      => true,  // Automatically attempts ldap_start_tls() over plain port 389/7389
    'req_cert'     => false, // Set to false (LDAPTLS_REQCERT=never) to accept self-signed certificates
    
    // Flexible LDAP Attribute Mapping -> HR Database Columns
    'attr_map' => [
        'username'    => 'samaccountname',                 // Active Directory: 'samaccountname'
        'first_name'  => 'givenname',
        'last_name'   => 'sn',
        'email'       => 'mail', // Univention UCS: 'mailprimaryaddress' | Standard OpenLDAP/AD: 'mail'
        'phone'       => 'telephonenumber',
        'guid'        => 'entryuuid',           // OpenLDAP: 'entryuuid' | Active Directory: 'objectguid'
    ],
    
    // System Access Settings
    'default_role_name' => 'employee'
];
