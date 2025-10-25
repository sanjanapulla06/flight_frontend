<?php
// /FLIGHT_FRONTEND/auth/login.php
// DEV: show errors while debugging — remove in production
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
safe_start_session();

$err = '';
// If user already logged in, send them away
if (!empty($_SESSION['passport_no'])) {
    header('Location: /FLIGHT_FRONTEND/search.php');
    exit;
}

// Helper: check column presence
function column_exists($mysqli, $table, $col) {
    $res = $mysqli->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    return ($res && $res->num_rows > 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $err = 'Please enter email and password';
    } else {
        // detect which password column to use
        $pw_col = null;
        if (column_exists($mysqli, 'passenger', 'password_hash')) {
            $pw_col = 'password_hash';
        } elseif (column_exists($mysqli, 'passenger', 'password')) {
            $pw_col = 'password';
        }

        if ($pw_col === null) {
            // Friendly actionable error instead of blank page
            $err = "Server configuration issue: no password column found on `passenger` table. Please add a `password_hash` VARCHAR(255) column (recommended).";
            // also log backtrace for admin
            error_log('Login failed: passenger table missing password/password_hash column.');
        } else {
            // build query using the detected column name safely (it's validated above)
            $sql = "SELECT passport_no, name, email, phone, {$pw_col} AS pw, role FROM passenger WHERE email = ? LIMIT 1";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                $err = 'Database error: ' . htmlspecialchars($mysqli->error);
            } else {
                $stmt->bind_param('s', $email);
                if (!$stmt->execute()) {
                    $err = 'Database execution error: ' . htmlspecialchars($stmt->error);
                } else {
                    $row = $stmt->get_result()->fetch_assoc();
                    if ($row) {
                        $stored = $row['pw'];
                        $auth_ok = false;

                        // If using password_hash column, expect a normal password_hash() value
                        if ($pw_col === 'password_hash') {
                            if (is_string($stored) && password_verify($password, $stored)) {
                                $auth_ok = true;
                                // rehash if needed
                                if (password_needs_rehash($stored, PASSWORD_DEFAULT)) {
                                    $new_hash = password_hash($password, PASSWORD_DEFAULT);
                                    $upd = $mysqli->prepare("UPDATE passenger SET password_hash = ? WHERE passport_no = ? LIMIT 1");
                                    if ($upd) { $upd->bind_param('ss', $new_hash, $row['passport_no']); $upd->execute(); $upd->close(); }
                                }
                            }
                        } else {
                            // pw_col === 'password' -> legacy column (maybe plaintext or old hashed)
                            // try hash verify first, then fallback to direct compare
                            $looks_like_hash = is_string($stored) && (
                                strpos($stored, '$2y$') === 0 ||
                                strpos($stored, '$2a$') === 0 ||
                                strpos($stored, '$argon2') === 0 ||
                                strpos($stored, '$argon2id') === 0
                            );
                            if ($looks_like_hash && password_verify($password, $stored)) {
                                $auth_ok = true;
                                // migrate to password_hash column if exists; create if necessary
                                if (!column_exists($mysqli, 'passenger', 'password_hash')) {
                                    // best-effort: add column (requires privileges)
                                    @$mysqli->query("ALTER TABLE passenger ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL");
                                }
                                if (column_exists($mysqli, 'passenger', 'password_hash')) {
                                    $new_hash = password_hash($password, PASSWORD_DEFAULT);
                                    $upd = $mysqli->prepare("UPDATE passenger SET password_hash = ? WHERE passport_no = ? LIMIT 1");
                                    if ($upd) { $upd->bind_param('ss', $new_hash, $row['passport_no']); $upd->execute(); $upd->close(); }
                                }
                            } elseif (hash_equals((string)$stored, (string)$password)) {
                                // direct compare (legacy plaintext) - migrate immediately
                                $auth_ok = true;
                                if (!column_exists($mysqli, 'passenger', 'password_hash')) {
                                    @$mysqli->query("ALTER TABLE passenger ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL");
                                }
                                if (column_exists($mysqli, 'passenger', 'password_hash')) {
                                    $new_hash = password_hash($password, PASSWORD_DEFAULT);
                                    $upd = $mysqli->prepare("UPDATE passenger SET password_hash = ? WHERE passport_no = ? LIMIT 1");
                                    if ($upd) { $upd->bind_param('ss', $new_hash, $row['passport_no']); $upd->execute(); $upd->close(); }
                                }
                            }
                        }

                        if ($auth_ok) {
                            session_regenerate_id(true);
                            $_SESSION['passport_no'] = $row['passport_no'];
                            $_SESSION['name'] = $row['name'];
                            $_SESSION['email'] = $row['email'];
                            $_SESSION['phone'] = $row['phone'];
                            $_SESSION['role'] = $row['role'] ?? 'passenger';

                            // safe redirect
                            $return = $_GET['return'] ?? '/FLIGHT_FRONTEND/search.php';
                            if (!is_string($return) || strpos($return, '/') !== 0) $return = '/FLIGHT_FRONTEND/search.php';
                            header('Location: ' . $return);
                            exit;
                        } else {
                            $err = 'Invalid credentials';
                        }
                    } else {
                        $err = 'Invalid credentials';
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// include header and render form — always show something so page isn't blank
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
        <input name="email" id="email" type="email" class="form-control" placeholder="Email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="mb-2">
        <input name="password" id="password" type="password" class="form-control" placeholder="Password" required>
      </div>
      <div>
        <button class="btn btn-primary w-100">Login</button>
      </div>
    </form>

    <div class="mt-3 text-center">
      <a href="/FLIGHT_FRONTEND/auth/register.php">Create account</a> ·
      <a href="/FLIGHT_FRONTEND/auth/forgot_password.php">Forgot password?</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
