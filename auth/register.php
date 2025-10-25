]<?php
// /FLIGHT_FRONTEND/auth/register.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
safe_start_session();

$err = '';
$success = '';

/**
 * Check if a column exists in the current database/table
 * (we re-declare here to be safe if file is included standalone)
 */
function col_exists($mysqli, $table, $col) {
    $sql = "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = (bool)$res->fetch_row();
    $stmt->close();
    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $age_raw = trim($_POST['age'] ?? '');
    $dob_raw = trim($_POST['dob'] ?? '');

    // basic validations
    if ($name === '' || $email === '' || $password === '') {
        $err = 'Please fill all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strpos($email, '@') === false) {
        $err = 'Please enter a valid email address containing @.';
    } else {
        // phone cleaning
        $digits_only = preg_replace('/\D+/', '', $phone);
        if ($phone !== '') {
            if (strlen($digits_only) !== 10) {
                $err = 'Phone number must contain exactly 10 digits (Indian format).';
            } elseif (!preg_match('/^[6-9]/', $digits_only)) {
                $err = 'Phone number must start with 6, 7, 8, or 9 (Indian mobile).';
            }
        }

        // age validation
        if ($err === '') {
            $age = null;
            if ($age_raw !== '') {
                if (!ctype_digit($age_raw)) {
                    $err = 'Age must be a whole number.';
                } else {
                    $age = (int)$age_raw;
                    if ($age < 18) {
                        $err = 'You must be 18 or older to register.';
                    } elseif ($age > 120) {
                        $err = 'Please enter a realistic age (under 120).';
                    }
                }
            }

            // optional DOB check
            $dob = null;
            if ($dob_raw !== '') {
                $t = date_parse($dob_raw);
                if ($t['error_count'] === 0 && checkdate((int)$t['month'], (int)$t['day'], (int)$t['year'])) {
                    $dob = date('Y-m-d', strtotime($dob_raw));
                    $calc_age = (int)date_diff(date_create($dob), date_create('today'))->y;
                    if ($calc_age < 18) $err = 'You must be 18 or older to register.';
                } else {
                    $err = 'Invalid date of birth format.';
                }
            }
        }
    }

    if ($err === '') {
        // ensure unique passport number generation (attempts loop)
        $passport = null;
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $candidate = 'P' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            $chk = $mysqli->prepare("SELECT 1 FROM passenger WHERE passport_no = ? LIMIT 1");
            if ($chk) {
                $chk->bind_param('s', $candidate);
                $chk->execute();
                $exists = (bool)$chk->get_result()->fetch_row();
                $chk->close();
                if (!$exists) { $passport = $candidate; break; }
            } else {
                // if check can't be prepared, still use generated candidate (rare)
                $passport = $candidate; break;
            }
        }
        if ($passport === null) {
            $err = "Failed to generate unique passport number. Try again.";
        }
    }

    if ($err === '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // detect columns
        $has_age_col = col_exists($mysqli, 'passenger', 'age');
        $has_dob_col = col_exists($mysqli, 'passenger', 'dob');
        $has_phone_col = col_exists($mysqli, 'passenger', 'phone');
        $has_role_col = col_exists($mysqli, 'passenger', 'role');
        $has_pw_hash_col = col_exists($mysqli, 'passenger', 'password_hash');
        $has_pw_plain_col = col_exists($mysqli, 'passenger', 'password');

        // If the passenger table lacks any password column, try to add password_hash (best effort)
        if (!$has_pw_hash_col && !$has_pw_plain_col) {
            $alter_sql = "ALTER TABLE passenger ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL";
            if ($mysqli->query($alter_sql) === TRUE) {
                $has_pw_hash_col = true;
            } else {
                // if we can't alter, set error and ask admin to add password_hash
                $err = "Database does not have a password column and automatic migration failed. Please add a `password_hash` VARCHAR(255) column to `passenger` table.";
            }
        }

        // proceed if still no error
        if ($err === '') {
            // build query dynamically (include role only if column exists)
            $cols = ['passport_no', 'name', 'email'];
            $placeholders = ['?', '?', '?'];
            $params = [$passport, $name, $email];
            $types = 'sss';

            if ($has_role_col) {
                $cols[] = 'role';
                $placeholders[] = '?';
                $params[] = 'passenger';
                $types .= 's';
            }

            if ($has_phone_col) {
                $cols[] = 'phone';
                $placeholders[] = '?';
                $params[] = $digits_only ?: null;
                $types .= 's';
            }

            if ($has_dob_col) {
                $cols[] = 'dob';
                $placeholders[] = '?';
                $params[] = $dob;
                $types .= 's';
            }

            if ($has_age_col) {
                $cols[] = 'age';
                $placeholders[] = '?';
                $params[] = $age;
                $types .= 'i';
            }

            // choose password column (prefer password_hash)
            $pw_col = $has_pw_hash_col ? 'password_hash' : 'password';
            $cols[] = $pw_col;
            $placeholders[] = '?';
            $params[] = $hash;
            $types .= 's';

            $sql = 'INSERT INTO passenger (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
            $stmt = $mysqli->prepare($sql);

            if (!$stmt) {
                $err = 'Database prepare error: ' . htmlspecialchars($mysqli->error);
            } else {
                // dynamic bind
                $bind_names = [];
                $bind_names[] = $types;
                for ($i = 0; $i < count($params); $i++) {
                    $var = 'p' . $i;
                    $$var = $params[$i];
                    $bind_names[] = &$$var;
                }
                call_user_func_array([$stmt, 'bind_param'], $bind_names);

                if ($stmt->execute()) {
                    // set session and redirect
                    session_regenerate_id(true);
                    $_SESSION['passport_no'] = $passport;
                    $_SESSION['name'] = $name;
                    $_SESSION['email'] = $email;
                    $_SESSION['role'] = $has_role_col ? ($params[array_search('role', $cols)] ?? 'passenger') : 'passenger';

                    header('Location: /FLIGHT_FRONTEND/search.php');
                    exit;
                } else {
                    // unique email constraint check fallback
                    if ($mysqli->errno === 1062) {
                        $err = 'Email already registered. Try logging in.';
                    } else {
                        $err = 'Database error: ' . htmlspecialchars($stmt->error);
                    }
                }
                $stmt->close();
            }
        }
    }
}

// show form (header included below)
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center my-4">
  <div class="col-md-6">
    <h3>Create Account</h3>
    <?php if($err): ?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif; ?>
    <form method="post" novalidate>
      <div class="mb-2">
        <label class="form-label">Full Name</label>
        <input name="name" class="form-control" required value="<?=htmlspecialchars($_POST['name'] ?? '')?>">
      </div>

      <div class="mb-2">
        <label class="form-label">Email</label>
        <input name="email" type="email" class="form-control" required value="<?=htmlspecialchars($_POST['email'] ?? '')?>">
      </div>

      <div class="mb-2">
        <label class="form-label">Phone (optional)</label>
        <input name="phone" class="form-control" placeholder="9876543210" value="<?=htmlspecialchars($_POST['phone'] ?? '')?>">
        <div class="form-text">Enter exactly 10 digits (Indian mobile starting with 6–9).</div>
      </div>

      <div class="mb-2">
        <label class="form-label">Password</label>
        <input name="password" type="password" class="form-control" required>
      </div>

      <div class="mb-2">
        <label class="form-label">Age</label>
        <input name="age" type="number" min="18" max="120" class="form-control" value="<?=htmlspecialchars($_POST['age'] ?? '')?>">
      </div>

      <div class="mb-2">
        <label class="form-label">Date of Birth (optional)</label>
        <input name="dob" type="date" class="form-control" value="<?=htmlspecialchars($_POST['dob'] ?? '')?>">
      </div>

      <div class="mt-3">
        <button class="btn btn-primary">Register</button>
        <a href="/FLIGHT_FRONTEND/auth/login.php" class="btn btn-link">Already have an account? Login</a>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelector('form').addEventListener('submit', function(e) {
  const email = document.querySelector('[name="email"]').value.trim();
  const phone = document.querySelector('[name="phone"]').value.trim();
  const age = parseInt(document.querySelector('[name="age"]').value.trim() || 0, 10);

  if (!email.includes('@')) {
    alert('Please enter a valid email containing @.');
    e.preventDefault(); return false;
  }
  if (phone) {
    const digits = phone.replace(/\D/g, '');
    if (digits.length !== 10) {
      alert('Phone must contain exactly 10 digits.');
      e.preventDefault(); return false;
    }
    if (!/^[6-9]/.test(digits)) {
      alert('Phone number must start with 6–9.');
      e.preventDefault(); return false;
    }
  }
  if (age && age < 18) {
    alert('You must be 18 or older to register.');
    e.preventDefault(); return false;
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
