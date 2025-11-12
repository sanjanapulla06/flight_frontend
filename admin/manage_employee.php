<?php
// manage_employee.php
session_start();
require_once __DIR__ . '/includes/auth_check.php'; // Check if the admin is logged in
require_once __DIR__ . '/../includes/db.php';      // Database connection

$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id) {
    $_SESSION['employee_flash'] = "Unauthorized access. Please log in again.";
    header('Location: admin_login.php');
    exit;
}

// Capture search query and department filter
$q = trim($_GET['q'] ?? '');
$dept = trim($_GET['department'] ?? '');

// Build base query (always scoped to this admin)
$sql = "SELECT * FROM employee WHERE admin_id = ?";
$params = [$admin_id];
$types  = "i";

// Add search filter
if ($q !== '') {
    $sql .= " AND (first_name LIKE CONCAT('%', ?, '%')
                OR last_name LIKE CONCAT('%', ?, '%')
                OR CONCAT(first_name, ' ', last_name) LIKE CONCAT('%', ?, '%')
                OR position LIKE CONCAT('%', ?, '%')
                OR department LIKE CONCAT('%', ?, '%'))";
    $params = array_merge($params, [$q, $q, $q, $q, $q]);
    $types .= "sssss";
}

// Add department filter
if ($dept !== '') {
    $sql .= " AND department = ?";
    $params[] = $dept;
    $types .= "s";
}

// Prepare and execute
$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch distinct departments for filter dropdown (scoped to this admin)
$deptStmt = $mysqli->prepare("SELECT DISTINCT department FROM employee WHERE admin_id = ? AND department IS NOT NULL AND department <> '' ORDER BY department ASC");
$deptStmt->bind_param('i', $admin_id);
$deptStmt->execute();
$deptRes = $deptStmt->get_result();
$departments = $deptRes ? $deptRes->fetch_all(MYSQLI_ASSOC) : [];
$deptStmt->close();

// Flash message
$flash = $_SESSION['employee_flash'] ?? null;
unset($_SESSION['employee_flash']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Employees</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container">
    <h2>Manage Employees</h2>

    <?php if ($flash): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <!-- Back to Admin Dashboard -->
    <a href="admin_dashboard.php" class="btn btn-warning mb-3">Back to Admin Dashboard</a>
    <a href="add_employee.php" class="btn btn-success mb-3">Add New Employee</a>

    <!-- Search + Department Filter -->
    <form method="get" class="row g-2 mb-3">
        <div class="col-md-5">
            <input type="text" name="q" class="form-control" placeholder="Search by name, position, or department"
                   value="<?= htmlspecialchars($q) ?>">
        </div>
        <div class="col-md-4">
            <select name="department" class="form-select">
                <option value="">-- All Departments --</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= htmlspecialchars($d['department']) ?>" 
                        <?= ($dept === $d['department']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($d['department']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex">
            <button type="submit" class="btn btn-primary me-2">Filter</button>
            <a href="manage_employee.php" class="btn btn-secondary">Clear</a>
        </div>
    </form>

    <table class="table table-bordered table-striped">
        <thead class="table-light">
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Position</th>
                <th>Salary</th>
                <th>Department</th>
                <th>Hire Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($employees)): ?>
                <tr><td colspan="7">No employees found.</td></tr>
            <?php else: ?>
                <?php foreach ($employees as $emp): ?>
                    <tr>
                        <td><?= $emp['employee_id'] ?></td>
                        <td><?= htmlspecialchars($emp['first_name'].' '.$emp['last_name']) ?></td>
                        <td><?= htmlspecialchars($emp['position']) ?></td>
                        <td><?= htmlspecialchars($emp['salary']) ?></td>
                        <td><?= htmlspecialchars($emp['department'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($emp['hire_date'] ?? 'N/A') ?></td>
                        <td>
                            <a href="view_employee.php?id=<?= $emp['employee_id'] ?>" class="btn btn-sm btn-info">View</a>
                            <a href="edit_employee.php?id=<?= $emp['employee_id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                            <a href="delete_employee.php?id=<?= $emp['employee_id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>