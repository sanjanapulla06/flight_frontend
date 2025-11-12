<?php
// auth_check.php - put under /FLIGHT_FRONTEND/admin/includes/auth_check.php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    // redirect to login and preserve return URL
    $ret = urlencode($_SERVER['REQUEST_URI']);
    header("Location: /FLIGHT_FRONTEND/admin/admin_login.php?return={$ret}");
    exit;
}
