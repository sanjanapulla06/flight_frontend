<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
safe_start_session();

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $err = 'Please enter email and password';
    } else {
        // 🔹 include phone and email in the fetch
        $stmt = $mysqli->prepare("
            SELECT passport_no, name, email, phone, password, role 
            FROM passenger 
            WHERE email = ? 
            LIMIT 1
        ");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        // 🔹 verify password & set all session values
        if ($row && password_verify($password, $row['password'])) {
            $_SESSION['passport_no'] = $row['passport_no'];
            $_SESSION['name'] = $row['name'];
            $_SESSION['email'] = $row['email'];
            $_SESSION['phone'] = $row['phone'];
            $_SESSION['role'] = $row['role'] ?? 'passenger';

            // redirect to where user came from or search page
            $return = $_GET['return'] ?? '/FLIGHT_FRONTEND/search.php';
            header('Location: ' . urldecode($return));
            exit;
        } else {
            $err = 'Invalid credentials';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center my-4">
  <div class="col-md-5">
    <h3 class="mb-3 text-primary fw-bold">Login</h3>

    <?php if ($err): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <div class="mb-2">
        <input name="email" id="email" type="email" class="form-control" placeholder="Email" required>
      </div>
      <div class="mb-2">
        <input name="password" id="password" type="password" class="form-control" placeholder="Password" required>
      </div>
      <div>
        <button class="btn btn-primary w-100">Login</button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
