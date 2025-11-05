<?php
// admin_logout.php
// Path: /FLIGHT_FRONTEND/admin/admin_logout.php
if (session_status() === PHP_SESSION_NONE) session_start();

// remove only admin keys (do not destroy passenger session unless you want)
unset($_SESSION['is_admin'], $_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_username'], $_SESSION['admin_role']);

header('Location: /FLIGHT_FRONTEND/index.php');
exit;
