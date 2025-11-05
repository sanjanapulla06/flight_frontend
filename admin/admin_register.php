<?php
// admin_register.php
// Path: /FLIGHT_FRONTEND/admin/admin_register.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// helper to check if any admin exists
$existsRes = $mysqli->query("SELECT 1 FROM admins LIMIT 1");
$anyAdmin = ($existsRes && $existsRes->num_rows > 0);

// require superadmin to create new admins unless no admin exists (bootstrap)
$canRegister = true;
if (!$anyAdmin) {
    $canRegister = true; // initial bootstrap
} elseif (!empty($_SESSION['is_admin']) && !empty($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'superadmin') {
    $canRegister = true;
}

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canRegister) {
    $username = trim($_POST['username'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    $role = in_array($_POST['role'] ?? 'admin', ['admin','superadmin']) ? $_POST['role'] : 'admin';

    if ($username === '' || $password === '' || $password2 === '') {
        $errors[] = "Username and password are required.";
    } elseif ($password !== $password2) {
        $errors[] = "Passwords do not match.";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters.";
    } else {
        // check username uniqueness
        $chk = $mysqli->prepare("SELECT admin_id FROM admins WHERE username = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param('s', $username);
            $chk->execute();
            $r = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($r) $errors[] = "Username already taken.";
        } else {
            $errors[] = "DB error (check username).";
        }
    }

    if (empty($errors)) {
        $pw_hash = password_hash($password, PASSWORD_DEFAULT);
        $ins = $mysqli->prepare("INSERT INTO admins (username, password_hash, name, role) VALUES (?, ?, ?, ?)");
        if ($ins) {
            $ins->bind_param('ssss', $username, $pw_hash, $name, $role);
            if ($ins->execute()) {
                $success = "Admin account created successfully.";
            } else {
                $errors[] = "DB insert failed: " . $ins->error;
            }
            $ins->close();
        } else {
            $errors[] = "DB prepare failed: " . $mysqli->error;
        }
    }
}

// show simple UI
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container my-4">
  <h2>Admin Registration</h2>

  <?php if (!$canRegister): ?>
    <div class="alert alert-danger">Registration not allowed. Only a SuperAdmin may create admin accounts.</div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <form method="post" class="row g-3" <?= $canRegister ? '' : 'style="display:none;"' ?>>
    <div class="col-md-6">
      <label class="form-label">Username</label>
      <input name="username" class="form-control" required>
    </div>
    <div class="col-md-6">
      <label class="form-label">Full Name</label>
      <input name="name" class="form-control">
    </div>
    <div class="col-md-4">
      <label class="form-label">Password</label>
      <input name="password" type="password" class="form-control" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">Confirm Password</label>
      <input name="password2" type="password" class="form-control" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">Role</label>
      <select name="role" class="form-control">
        <option value="admin">Admin</option>
        <option value="superadmin">SuperAdmin</option>
      </select>
    </div>

    <div class="col-12">
      <button class="btn btn-primary" type="submit">Create Admin</button>
      <a class="btn btn-secondary" href="/FLIGHT_FRONTEND/index.php">Back</a>
    </div>
  </form>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
