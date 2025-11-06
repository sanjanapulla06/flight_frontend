// delete_employee.php
<?php
session_start();
require_once _DIR_ . '/includes/auth_check.php';
require_once _DIR_ . '/../includes/db.php';

$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id) {
    $_SESSION['employee_flash'] = "Unauthorized access. Please log in again.";
    header('Location: admin_login.php');
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['employee_flash'] = "Invalid Employee ID.";
    header('Location: manage_employee.php');
    exit;
}

$employee_id = intval($_GET['id']);

// ✅ Fetch employee but restrict to this admin
$stmt = $mysqli->prepare("SELECT first_name, last_name FROM employee WHERE employee_id=? AND admin_id=? LIMIT 1");
$stmt->bind_param('ii', $employee_id, $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();
$stmt->close();

if (!$employee) {
    $_SESSION['employee_flash'] = "Employee not found or you don't have permission to delete this record.";
    header('Location: manage_employee.php');
    exit;
}

// ✅ Delete only if employee belongs to this admin
$stmt = $mysqli->prepare("DELETE FROM employee WHERE employee_id=? AND admin_id=?");
$stmt->bind_param('ii', $employee_id, $admin_id);
if ($stmt->execute()) {
    $_SESSION['employee_flash'] = "Employee {$employee['first_name']} {$employee['last_name']} deleted successfully.";
} else {
    $_SESSION['employee_flash'] = "Failed to delete employee.";
}
$stmt->close();

header('Location: manage_employee.php');
exit;