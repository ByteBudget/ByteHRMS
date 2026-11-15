<?php
// config/mail.php - SMTP & Email Notification Settings
return [
    'smtp_host'   => 'smtp.gmail.com',          // e.g. smtp.gmail.com, smtp.office365.com, email-smtp.us-east-1.amazonaws.com
    'smtp_port'   => 587,
    'smtp_secure' => 'tls',                     // 'tls' (587) or 'ssl' (465)
    'smtp_user'   => 'hr-noreply@yourdomain.com',
    'smtp_pass'   => 'your-app-password-here',
    'from_email'  => 'hr-noreply@yourdomain.com',
    'from_name'   => 'HR Management Portal',
    'portal_url'  => 'http://your-server-domain/dashboard.php',
];
