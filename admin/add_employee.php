<?php
session_start();
require_once __DIR__ . '/includes/auth_check.php'; // Check if the admin is logged in
require_once __DIR__ . '/../includes/db.php';      // Database connection

// Initialize error
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $position   = trim($_POST['position'] ?? '');
    $salary     = trim($_POST['salary'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $hire_date  = trim($_POST['hire_date'] ?? '');
    $address    = trim($_POST['address'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $age        = trim($_POST['age'] ?? '');
    $sex        = trim($_POST['sex'] ?? '');

    // Get the logged-in admin_id from session
    $admin_id = $_SESSION['admin_id'] ?? null;

    // Basic validation
    if ($first_name === '' || $last_name === '' || $position === '') {
        $error = "First Name, Last Name, and Position are required.";
    } elseif (!$admin_id) {
        $error = "Admin not identified. Please log in again.";
    } else {
        // Prepare SQL query with admin_id
        $stmt = $mysqli->prepare(
            "INSERT INTO employee 
             (first_name, last_name, position, salary, department, hire_date, address, phone, age, sex, admin_id) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param(
                'ssssssssssi',
                $first_name, $last_name, $position, $salary, $department, $hire_date,
                $address, $phone, $age, $sex, $admin_id
            );
            if ($stmt->execute()) {
                $_SESSION['employee_flash'] = "Employee added successfully!";
                header('Location: manage_employee.php');
                exit;
            } else {
                $error = "Database Error: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Database Prepare Error: " . $mysqli->error;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Add Employee</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container">
    <h2>Add New Employee</h2>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="" class="row g-3">
        <div class="col-md-6">
            <label>First Name</label>
            <input type="text" name="first_name" class="form-control" required>
        </div>
        <div class="col-md-6">
            <label>Last Name</label>
            <input type="text" name="last_name" class="form-control" required>
        </div>
        <div class="col-md-6">
            <label>Position</label>
            <input type="text" name="position" class="form-control" required>
        </div>
        <div class="col-md-6">
            <label>Salary</label>
            <input type="text" name="salary" class="form-control">
        </div>
        <div class="col-md-6">
            <label>Department</label>
            <input type="text" name="department" class="form-control">
        </div>
        <div class="col-md-6">
            <label>Hire Date</label>
            <input type="date" name="hire_date" class="form-control">
        </div>
        <div class="col-md-6">
            <label>Address</label>
            <input type="text" name="address" class="form-control">
        </div>
        <div class="col-md-6">
            <label>Phone</label>
            <input type="text" name="phone" class="form-control">
        </div>
        <div class="col-md-3">
            <label>Age</label>
            <input type="number" name="age" class="form-control">
        </div>
        <div class="col-md-3">
            <label>Sex</label>
            <select name="sex" class="form-control">
                <option value="">Select Sex</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Other">Other</option>
            </select>
        </div>
        <div class="col-12">
            <button type="submit" class="btn btn-primary">Add Employee</button>
            <a href="manage_employee.php" class="btn btn-secondary">Back to Employees</a>
        </div>
    </form>
</div>
</body>
</html>