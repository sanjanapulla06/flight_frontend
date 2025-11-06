<?php
// edit_employee.php
session_start();
require_once __DIR__ . '/includes/auth_check.php';  // Check if the admin is logged in
require_once __DIR__ . '/../includes/db.php';      // Database connection

$admin_id = $_SESSION['admin_id'] ?? null; // logged-in admin ID
if (!$admin_id) {
    header('Location: admin_login.php');
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) { 
    header('Location: manage_employee.php'); 
    exit; 
}

// ✅ Fetch employee but restrict to this admin
$stmt = $mysqli->prepare("SELECT * FROM employee WHERE employee_id = ? AND admin_id = ? LIMIT 1");
$stmt->bind_param('ii', $id, $admin_id);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$employee) { 
    // Employee not found or doesn’t belong to this admin
    header('Location: manage_employee.php'); 
    exit; 
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = $_POST['first_name'];
    $last_name  = $_POST['last_name'];
    $position   = $_POST['position'];
    $salary     = $_POST['salary'];
    $department = $_POST['department'];
    $hire_date  = $_POST['hire_date'];
    $address    = $_POST['address'];
    $phone      = $_POST['phone'];
    $age        = $_POST['age'];
    $sex        = $_POST['sex'];

    // ✅ Update only if employee belongs to this admin
    $upd = $mysqli->prepare("UPDATE employee 
        SET first_name=?, last_name=?, position=?, salary=?, department=?, hire_date=?, address=?, phone=?, age=?, sex=? 
        WHERE employee_id=? AND admin_id=?");
    $upd->bind_param(
        'ssssssssssii',
        $first_name, $last_name, $position, $salary, $department, $hire_date,
        $address, $phone, $age, $sex, $id, $admin_id
    );
    if ($upd->execute()) {
        $_SESSION['employee_flash'] = "Employee updated successfully!";
        header('Location: manage_employee.php');
        exit;
    } else {
        $error = "Update failed: " . $upd->error;
    }
    $upd->close();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Employee</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container">
    <h2>Edit Employee</h2>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="row g-3">
        <div class="col-md-6">
            <label>First Name</label>
            <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($employee['first_name']) ?>" required>
        </div>
        <div class="col-md-6">
            <label>Last Name</label>
            <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($employee['last_name']) ?>" required>
        </div>
        <div class="col-md-6">
            <label>Position</label>
            <input type="text" name="position" class="form-control" value="<?= htmlspecialchars($employee['position']) ?>" required>
        </div>
        <div class="col-md-6">
            <label>Salary</label>
            <input type="text" name="salary" class="form-control" value="<?= htmlspecialchars($employee['salary']) ?>">
        </div>
        <div class="col-md-6">
            <label>Department</label>
            <input type="text" name="department" class="form-control" value="<?= htmlspecialchars($employee['department']) ?>">
        </div>
        <div class="col-md-6">
            <label>Hire Date</label>
            <input type="date" name="hire_date" class="form-control" value="<?= htmlspecialchars($employee['hire_date']) ?>">
        </div>
        <div class="col-md-6">
            <label>Address</label>
            <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($employee['address']) ?>">
        </div>
        <div class="col-md-6">
            <label>Phone</label>
            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($employee['phone']) ?>">
        </div>
        <div class="col-md-3">
            <label>Age</label>
            <input type="number" name="age" class="form-control" value="<?= htmlspecialchars($employee['age']) ?>">
        </div>
        <div class="col-md-3">
            <label>Sex</label>
            <select name="sex" class="form-control">
                <option value="Male" <?= $employee['sex']=='Male'?'selected':'' ?>>Male</option>
                <option value="Female" <?= $employee['sex']=='Female'?'selected':'' ?>>Female</option>
                <option value="Other" <?= $employee['sex']=='Other'?'selected':'' ?>>Other</option>
            </select>
        </div>
        <div class="col-12">
            <button type="submit" class="btn btn-primary">Update Employee</button>
            <a href="manage_employee.php" class="btn btn-secondary">Back to Employees</a>
        </div>
    </form>
</div>
</body>
</html>