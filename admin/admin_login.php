<?php
// admin_login.php
// Path: /FLIGHT_FRONTEND/admin/admin_login.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$err = null;
$redirect = $_GET['return'] ?? '/FLIGHT_FRONTEND/admin/admin_dashboard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $err = "Username + password required.";
    } else {
        $stmt = $mysqli->prepare("SELECT admin_id, username, password_hash, name, role FROM admins WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row && password_verify($password, $row['password_hash'])) {
                // success: set admin session variables (separate from passenger session)
                session_regenerate_id(true);
                $_SESSION['is_admin'] = true;
                $_SESSION['admin_id'] = $row['admin_id'];
                $_SESSION['admin_name'] = $row['name'] ?: $row['username'];
                $_SESSION['admin_username'] = $row['username'];
                $_SESSION['admin_role'] = $row['role'];

                header("Location: " . $redirect);
                exit;
            } else {
                $err = "Invalid credentials.";
            }
        } else {
            $err = "DB error: " . $mysqli->error;
        }
    }
}

// render
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container my-4">
  <h2>Admin Login</h2>

  <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <form method="post" class="row g-3" style="max-width:480px;">
    <div class="col-12">
      <label class="form-label">Username</label>
      <input name="username" class="form-control" autofocus required>
    </div>
    <div class="col-12">
      <label class="form-label">Password</label>
      <input name="password" type="password" class="form-control" required>
    </div>
    <div class="col-12 d-flex justify-content-between align-items-center mt-3">
      <div>
        <button class="btn btn-primary" type="submit">Sign in</button>
        <a class="btn btn-link" href="/FLIGHT_FRONTEND/index.php">Back</a>
      </div>

      <!-- ⚙️ Optional Register button (remove after initial setup) -->
      <a class="btn btn-sm btn-outline-warning" href="/FLIGHT_FRONTEND/admin/admin_register.php" 
         title="Create a new admin account (for setup only)">
         ➕ Register Admin
      </a>
    </div>
  </form>

  <p class="text-muted small mt-3">
    ⚠️ Tip: After creating admin users, restrict or delete the <code>admin_register.php</code> file for security.
  </p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
