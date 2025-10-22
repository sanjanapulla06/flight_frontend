

<?php
require_once __DIR__ . '/../includes/helpers.php';
safe_start_session();
session_unset();
session_destroy();
header('Location: /FLIGHT_FRONTEND/');
exit;
