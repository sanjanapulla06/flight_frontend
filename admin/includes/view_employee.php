<?php
// view_employee.php
session_start();
require_once _DIR_ . '/includes/auth_check.php';
require_once _DIR_ . '/../includes/db.php';

$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id) {
    $_SESSION['employee_flash'] = "Unauthorized access. Please log in again.";
    header('Location: admin_login.php');
    exit;
}

$id = $_GET['id'] ?? null;
$q  = trim($_GET['q'] ?? '');

// If an ID is provided, fetch that employee (restricted to this admin)
if ($id && is_numeric($id)) {
    $stmt = $mysqli->prepare("SELECT * FROM employee WHERE employee_id = ? AND admin_id = ? LIMIT 1");
    $stmt->bind_param('ii', $id, $admin_id);
    $stmt->execute();
    $employee = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Employee Directory</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container">

    <?php if ($id && !empty($employee)): ?>
        <!-- Profile Mode -->
        <h2>Employee Profile</h2>

        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-primary text-white d-flex align-items-center">
                <!-- Profile photo (placeholder if none) -->
                <img src="<?= $employee['photo'] ?? 'https://via.placeholder.com/80' ?>" 
                     class="rounded-circle me-3" width="80" height="80" alt="Profile Photo">
                <div>
                    <h4 class="mb-0"><?= htmlspecialchars($employee['first_name'].' '.$employee['last_name']) ?></h4>
                    <small><?= htmlspecialchars($employee['position']) ?> • 
                            <span class="badge bg-success">Active</span></small>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <!-- Personal Info -->
                    <div class="col-md-6">
                        <h6 class="text-muted">Personal Information</h6>
                        <p><strong>Age:</strong> <?= htmlspecialchars($employee['age'] ?? 'N/A') ?></p>
                        <p><strong>Sex:</strong> <?= htmlspecialchars($employee['sex'] ?? 'N/A') ?></p>
                        <p><strong>Phone:</strong> 📞 <?= htmlspecialchars($employee['phone'] ?? 'N/A') ?></p>
                        <p><strong>Address:</strong> 🏠 <?= htmlspecialchars($employee['address'] ?? 'N/A') ?></p>
                    </div>
                    <!-- Job Info -->
                    <div class="col-md-6">
                        <h6 class="text-muted">Job Information</h6>
                        <p><strong>Department:</strong> <?= htmlspecialchars($employee['department'] ?? 'N/A') ?></p>
                        <p><strong>Salary:</strong> 💰 <?= htmlspecialchars($employee['salary']) ?></p>
                        <p><strong>Hire Date:</strong> 📅 <?= htmlspecialchars($employee['hire_date'] ?? 'N/A') ?></p>
                    </div>
                </div>
            </div>
        </div>

        <a href="edit_employee.php?id=<?= $employee['employee_id'] ?>" class="btn btn-primary">Edit</a>
        <a href="delete_employee.php?id=<?= $employee['employee_id'] ?>" class="btn btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
        <a href="view_employee.php" class="btn btn-secondary">Back to Directory</a>
        <a href="manage_employee.php" class="btn btn-warning">Back to Manage Employees</a>
        <!-- New Back to Admin Dashboard button -->
        <a href="admin_dashboard.php" class="btn btn-dark">Back to Admin Dashboard</a>

    <?php else: ?>
        <!-- Directory Mode -->
        <h2>Employee Directory</h2>

        <!-- Search form -->
        <form method="get" class="mb-3">
            <div class="input-group">
                <input type="text" name="q" class="form-control" placeholder="Search by name or position"
                       value="<?= htmlspecialchars($q) ?>">
                <button type="submit" class="btn btn-primary">Search</button>
                <?php if ($q !== ''): ?>
                    <a href="view_employee.php" class="btn btn-secondary">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <table class="table table-bordered">
            <thead>
                <tr><th>ID</th><th>Name</th><th>Position</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php
                if ($q !== '') {
                    $stmt = $mysqli->prepare("
                        SELECT employee_id, first_name, last_name, position
                        FROM employee
                        WHERE admin_id = ?
                          AND (first_name LIKE CONCAT('%', ?, '%')
                           OR last_name LIKE CONCAT('%', ?, '%')
                           OR CONCAT(first_name, ' ', last_name) LIKE CONCAT('%', ?, '%')
                           OR position LIKE CONCAT('%', ?, '%'))
                    ");
                    $stmt->bind_param('issss', $admin_id, $q, $q, $q, $q);
                    $stmt->execute();
                    $res = $stmt->get_result();
                } else {
                    $stmt = $mysqli->prepare("SELECT employee_id, first_name, last_name, position FROM employee WHERE admin_id = ?");
                    $stmt->bind_param('i', $admin_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                }

                if ($res && $res->num_rows > 0):
                    while ($row = $res->fetch_assoc()):
                ?>
                    <tr>
                        <td><?= $row['employee_id'] ?></td>
                        <td><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></td>
                        <td><?= htmlspecialchars($row['position']) ?></td>
                        <td>
                            <a href="view_employee.php?id=<?= $row['employee_id'] ?>" class="btn btn-sm btn-info">View Profile</a>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="4">No employees found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Navigation buttons -->
        <a href="manage_employee.php" class="btn btn-warning mt-3">Back to Manage Employees</a>
        <a href="admin_dashboard.php" class="btn btn-dark mt-3">Back to Admin Dashboard</a>

    <?php endif; ?>
</div>
</body>
</html>