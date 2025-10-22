<?php
// edit_profile.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/header.php';

// ensure session started
if (session_status() === PHP_SESSION_NONE) session_start();

// require login
if (empty($_SESSION['passport_no'])) {
    header('Location: /FLIGHT_FRONTEND/auth/login.php?return=' . urlencode('/FLIGHT_FRONTEND/auth/edit_profile.php'));
    exit;
}

$passport_no = $_SESSION['passport_no'];

// simple helper for safe output
function safe_val($v, $fallback = '') {
    return htmlspecialchars($v ?? $fallback, ENT_QUOTES);
}

// handle POST (update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $age   = trim($_POST['age'] ?? '');

    $errors = [];

    // phone: allow digits, spaces, +, -, parentheses; strip spaces for storage
    $phone_sanitized = preg_replace('/[^\d\+\-\(\) ]+/', '', $phone);
    // optional: require at least 7 digits
    $digits_only = preg_replace('/\D+/', '', $phone_sanitized);
    if ($phone !== '' && strlen($digits_only) < 7) {
        $errors[] = "Phone number looks too short.";
    }

    // age: optional, but if provided must be integer 0-120
    if ($age !== '') {
        if (!ctype_digit($age)) {
            $errors[] = "Age must be a valid whole number.";
        } else {
            $age_i = intval($age);
            if ($age_i < 0 || $age_i > 120) $errors[] = "Age must be between 0 and 120.";
        }
    } else {
        $age_i = null;
    }

    if (empty($errors)) {
        // Update passenger row (only phone & age). Use prepared statement.
        $sql = "UPDATE passenger SET phone = ?, age = ? WHERE passport_no = ?";
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            // bind age as NULL if empty
            if ($age_i === null) {
                // bind null for age
                $stmt->bind_param('sis', $phone_sanitized, $age_i, $passport_no);
                // but PHP mysqli won't accept null direct — use workaround below
            }
            // We'll handle bind robustly:
            if ($age_i === null) {
                // use explicit NULL query when age is null
                $sql2 = "UPDATE passenger SET phone = ?, age = NULL WHERE passport_no = ?";
                $stmt2 = $mysqli->prepare($sql2);
                if ($stmt2) {
                    $stmt2->bind_param('ss', $phone_sanitized, $passport_no);
                    $ok = $stmt2->execute();
                    $stmt2->close();
                } else {
                    $ok = false;
                }
            } else {
                $stmt->bind_param('sis', $phone_sanitized, $age_i, $passport_no);
                $ok = $stmt->execute();
                $stmt->close();
            }

            if ($ok) {
                $_SESSION['flash_message'] = "Profile updated successfully.";
                // also update session phone so header shows updated phone
                $_SESSION['phone'] = $phone_sanitized;
                header('Location: /FLIGHT_FRONTEND/auth/profile.php');
                exit;
            } else {
                $_SESSION['flash_error'] = "Failed to update profile (DB error).";
            }
        } else {
            $_SESSION['flash_error'] = "Failed to prepare DB update.";
        }
    } else {
        // show validation errors
        $_SESSION['flash_error'] = implode(' ', $errors);
    }

    // redirect back so flash shows (PRG pattern)
    header('Location: /FLIGHT_FRONTEND/auth/edit_profile.php');
    exit;
}

// GET — fetch current values to prefill form
$stmt = $mysqli->prepare("SELECT name, email, phone, age FROM passenger WHERE passport_no = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('s', $passport_no);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    $user = ['name' => '', 'email' => '', 'phone' => '', 'age' => ''];
    $_SESSION['flash_error'] = "Failed to load profile.";
}
?>

<div class="container mt-5">
  <div class="card shadow p-4 col-md-8 mx-auto">
    <h4 class="mb-3">Edit Profile</h4>

    <?php if (!empty($_SESSION['flash_message'])): ?>
      <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <div class="mb-3">
        <label class="form-label">Name</label>
        <input class="form-control" type="text" name="name_display" value="<?= safe_val($user['name']); ?>" disabled>
      </div>

      <div class="mb-3">
        <label class="form-label">Email</label>
        <input class="form-control" type="email" value="<?= safe_val($user['email']); ?>" disabled>
      </div>

      <div class="mb-3">
        <label class="form-label">Phone (optional)</label>
        <input class="form-control" type="text" name="phone" value="<?= safe_val($user['phone']); ?>" placeholder="+91 98765 43210">
        <div class="form-text">You can include +, -, spaces, parentheses. Minimum 7 digits.</div>
      </div>

      <div class="mb-3">
        <label class="form-label">Age (optional)</label>
        <input class="form-control" type="number" name="age" min="0" max="120" value="<?= safe_val($user['age']); ?>" placeholder="e.g. 29">
      </div>

      <div class="d-flex gap-2">
        <a class="btn btn-secondary" href="/FLIGHT_FRONTEND/auth/profile.php">Cancel</a>
        <button class="btn btn-primary" type="submit">Save changes</button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
