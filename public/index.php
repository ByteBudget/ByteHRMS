<?php
// public/index.php - Base URL Entry Router
require_once __DIR__ . '/../includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

header('Location: login.php');
exit;
