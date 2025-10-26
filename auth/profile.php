<?php
// /FLIGHT_FRONTEND/auth/profile.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
safe_start_session();

// require login BEFORE printing header
if (empty($_SESSION['passport_no'])) {
    header('Location: /FLIGHT_FRONTEND/auth/login.php');
    exit();
}

$passport_no = $_SESSION['passport_no'];

// small helper for safe output
function safe_val($v, $fallback = '—') {
    return htmlspecialchars($v ?? $fallback, ENT_QUOTES);
}

// helper: column exists (uses current database)
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

/* ----------------------------
   Loyalty points logic
   - If a loyalty table exists we read stored points/tier.
   - Else compute points from ticket or bookings/flight prices.
   - Convert total_spend -> points using a rule: 1 point per 100 currency units (changeable).
   - Compute tier by thresholds.
   ---------------------------- */

function detect_loyalty_table($mysqli) {
    $candidates = ['loyalty', 'loyalty_points', 'frequent_flyer', 'ff_members'];
    foreach ($candidates as $t) {
        $r = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($t) . "'");
        if ($r && $r->num_rows > 0) return $t;
    }
    return null;
}

function fetch_loyalty_from_table($mysqli, $table, $passport) {
    // be defensive about column names
    $cols = [];
    $r = $mysqli->query("SHOW COLUMNS FROM `{$table}`");
    if ($r) while ($c = $r->fetch_assoc()) $cols[] = $c['Field'];

    $col_pass = in_array('passport_no', $cols) ? 'passport_no' : (in_array('member_id', $cols) ? 'member_id' : null);
    $col_points = in_array('points', $cols) ? 'points' : (in_array('balance', $cols) ? 'balance' : null);
    $col_tier = in_array('tier', $cols) ? 'tier' : (in_array('status', $cols) ? 'status' : null);
    $col_updated = in_array('updated_at', $cols) ? 'updated_at' : null;

    if (!$col_pass || !$col_points) return null;

    $sql = "SELECT `" . $mysqli->real_escape_string($col_points) . "` AS points"
         . ($col_tier ? ", `" . $mysqli->real_escape_string($col_tier) . "` AS tier" : "")
         . ($col_updated ? ", `" . $mysqli->real_escape_string($col_updated) . "` AS updated_at" : "")
         . " FROM `{$table}` WHERE `" . $mysqli->real_escape_string($col_pass) . "` = ? LIMIT 1";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('s', $passport);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    return $row;
}

function compute_points_from_spend($mysqli, $passport) {
    // 1) Try ticket table (preferred) -> sum ticket.price
    $total = 0.0;
    $stmt = $mysqli->prepare("SELECT COALESCE(SUM(price),0) AS s FROM ticket WHERE passport_no = ?");
    if ($stmt) {
        $stmt->bind_param('s', $passport);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $total = floatval($r['s'] ?? 0);
    }

    // 2) Fallback: bookings -> join flight price
    if ($total <= 0) {
        // detect flight table name for join
        $flight_table = $mysqli->query("SHOW TABLES LIKE 'flight'")->num_rows > 0 ? 'flight' : (
                        $mysqli->query("SHOW TABLES LIKE 'flights'")->num_rows > 0 ? 'flights' : 'flight');
        // Determine a plausible price column
        $price_col = 'price';
        $cols = [];
        $cres = $mysqli->query("SHOW COLUMNS FROM `{$flight_table}`");
        if ($cres) while ($c = $cres->fetch_assoc()) $cols[] = $c['Field'];
        if (!in_array('price', $cols)) {
            if (in_array('base_price', $cols)) $price_col = 'base_price';
            elseif (in_array('fare', $cols)) $price_col = 'fare';
        }

        $sql = "SELECT COALESCE(SUM(COALESCE(f.`{$price_col}`,0)),0) AS s
                FROM bookings b
                LEFT JOIN `{$flight_table}` f ON b.flight_id = f.flight_id
                WHERE b.passport_no = ?";
        $stmt2 = $mysqli->prepare($sql);
        if ($stmt2) {
            $stmt2->bind_param('s', $passport);
            $stmt2->execute();
            $rr = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();
            $total = floatval($rr['s'] ?? 0);
        }
    }

    // convert spend -> points. RULE: 1 point per 100 currency units (adjust as needed)
    $points = floor($total / 100.0);
    return ['points' => intval($points), 'spend' => $total];
}

function compute_tier_from_points($points) {
    // simple thresholds; customize as needed
    if ($points >= 5000) return 'Platinum';
    if ($points >= 2000) return 'Gold';
    if ($points >= 750)  return 'Silver';
    return 'Bronze';
}

/* ---------- Determine loyalty data ---------- */
$loyalty_table = detect_loyalty_table($mysqli);
$loyalty = [
    'points' => 0,
    'tier'   => 'Bronze',
    'source' => 'computed', // or 'table'
    'spend'  => 0.0,
    'updated_at' => null
];

if ($loyalty_table) {
    $lr = fetch_loyalty_from_table($mysqli, $loyalty_table, $passport_no);
    if ($lr) {
        $loyalty['points'] = isset($lr['points']) ? (int)$lr['points'] : 0;
        $loyalty['tier']   = isset($lr['tier']) && $lr['tier'] !== '' ? $lr['tier'] : compute_tier_from_points($loyalty['points']);
        $loyalty['source'] = 'table';
        $loyalty['updated_at'] = $lr['updated_at'] ?? null;
    } else {
        // no row for user in table; compute from spend as fallback
        $c = compute_points_from_spend($mysqli, $passport_no);
        $loyalty['points'] = $c['points'];
        $loyalty['spend'] = $c['spend'];
        $loyalty['tier'] = compute_tier_from_points($c['points']);
        $loyalty['source'] = 'computed';
    }
} else {
    // no loyalty table: compute points from spend
    $c = compute_points_from_spend($mysqli, $passport_no);
    $loyalty['points'] = $c['points'];
    $loyalty['spend'] = $c['spend'];
    $loyalty['tier'] = compute_tier_from_points($c['points']);
    $loyalty['source'] = 'computed';
}

/* ---------- rest of your profile handlers (DELETE / UPDATE) ---------- */
/* ---------- DELETE ACCOUNT handler ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_account') {
    // server-side safety: passport matches session
    $del_passport = $passport_no;

    // verify tables exist
    $has_ticket_table = $mysqli->query("SHOW TABLES LIKE 'ticket'")->num_rows > 0;
    $has_bookings_table = $mysqli->query("SHOW TABLES LIKE 'bookings'")->num_rows > 0;
    $has_passenger_table = $mysqli->query("SHOW TABLES LIKE 'passenger'")->num_rows > 0;

    $mysqli->begin_transaction();
    try {
        if ($has_bookings_table) {
            $stmt = $mysqli->prepare("DELETE FROM bookings WHERE passport_no = ?");
            if ($stmt) { $stmt->bind_param('s', $del_passport); $stmt->execute(); $stmt->close(); }
        }

        if ($has_ticket_table) {
            $stmt = $mysqli->prepare("DELETE FROM ticket WHERE passport_no = ?");
            if ($stmt) { $stmt->bind_param('s', $del_passport); $stmt->execute(); $stmt->close(); }
        }

        if ($has_passenger_table) {
            $stmt = $mysqli->prepare("DELETE FROM passenger WHERE passport_no = ? LIMIT 1");
            if (!$stmt) throw new Exception("Failed to prepare passenger delete: " . $mysqli->error);
            $stmt->bind_param('s', $del_passport);
            if (!$stmt->execute()) throw new Exception("Failed to delete passenger row: " . $stmt->error);
            $stmt->close();
        } else {
            throw new Exception("Passenger table not found.");
        }

        $mysqli->commit();

        // destroy session and redirect to homepage
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();

        header('Location: /FLIGHT_FRONTEND/index.php');
        exit();
    } catch (Exception $e) {
        $mysqli->rollback();
        safe_start_session(); // ensure we can set flash
        $_SESSION['flash_error'] = "Failed to delete account: " . htmlspecialchars($e->getMessage());
        // continue to render the page so user sees the flash error after header include
    }
}
/* ---------- end delete handler ---------- */


/* ---------- PROFILE UPDATE handler ---------- */
$has_age_col = col_exists($mysqli, 'passenger', 'age');
$has_dob_col  = col_exists($mysqli, 'passenger', 'dob');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $phone = trim($_POST['phone'] ?? '');
    $age   = trim($_POST['age'] ?? '');

    $errors = [];

    // sanitize phone & digits
    $phone_sanitized = preg_replace('/[^\d\+\-\(\) ]+/', '', $phone);
    $digits_only = preg_replace('/\D+/', '', $phone_sanitized);

    if ($phone !== '') {
        if (strlen($digits_only) < 7) {
            $errors[] = "Phone number must contain at least 7 digits.";
        } elseif (strlen($digits_only) > 15) {
            $errors[] = "Phone number seems too long.";
        }
    }

    $age_i = null;
    if ($age !== '') {
        if (!ctype_digit($age)) {
            $errors[] = "Age must be a whole number.";
        } else {
            $age_i = intval($age);
            if ($age_i < 18) $errors[] = "You must be 18 or older to have your own account.";
            if ($age_i > 120) $errors[] = "Age must be 120 or less.";
        }
    }

    if (empty($errors)) {
        $ok = false;
        if ($has_age_col) {
            if ($age_i === null) {
                $sql = "UPDATE passenger SET phone = ?, age = NULL WHERE passport_no = ?";
                $stmt = $mysqli->prepare($sql);
                if ($stmt) { $stmt->bind_param('ss', $phone_sanitized, $passport_no); $ok = $stmt->execute(); $stmt->close(); }
            } else {
                $sql = "UPDATE passenger SET phone = ?, age = ? WHERE passport_no = ?";
                $stmt = $mysqli->prepare($sql);
                if ($stmt) { $stmt->bind_param('sis', $phone_sanitized, $age_i, $passport_no); $ok = $stmt->execute(); $stmt->close(); }
            }
        } elseif ($has_dob_col) {
            if ($age_i === null) {
                $sql = "UPDATE passenger SET phone = ? WHERE passport_no = ?";
                $stmt = $mysqli->prepare($sql);
                if ($stmt) { $stmt->bind_param('ss', $phone_sanitized, $passport_no); $ok = $stmt->execute(); $stmt->close(); }
            } else {
                // approximate dob = today - age years
                $dob_dt = new DateTimeImmutable('today');
                $dob_dt = $dob_dt->sub(new DateInterval('P' . $age_i . 'Y'));
                $dob_str = $dob_dt->format('Y-m-d');

                $sql = "UPDATE passenger SET phone = ?, dob = ? WHERE passport_no = ?";
                $stmt = $mysqli->prepare($sql);
                if ($stmt) { $stmt->bind_param('sss', $phone_sanitized, $dob_str, $passport_no); $ok = $stmt->execute(); $stmt->close(); }
            }
        } else {
            $sql = "UPDATE passenger SET phone = ? WHERE passport_no = ?";
            $stmt = $mysqli->prepare($sql);
            if ($stmt) { $stmt->bind_param('ss', $phone_sanitized, $passport_no); $ok = $stmt->execute(); $stmt->close(); }
        }

        if ($ok) {
            $_SESSION['flash_message'] = "Profile updated successfully.";
            $_SESSION['phone'] = $phone_sanitized;
            header('Location: /FLIGHT_FRONTEND/auth/profile.php#profile');
            exit;
        } else {
            $_SESSION['flash_error'] = "Failed to update profile (DB error).";
            header('Location: /FLIGHT_FRONTEND/auth/profile.php#profile');
            exit;
        }
    } else {
        $_SESSION['flash_error'] = implode(' ', $errors);
        header('Location: /FLIGHT_FRONTEND/auth/profile.php#profile');
        exit;
    }
}
/* ---------- end update handler ---------- */

/* now include header and render page (GET) */
require_once __DIR__ . '/../includes/header.php';

/* GET: fetch user record */
$select_cols = "name, email, passport_no, phone";
if ($has_age_col) $select_cols .= ", age";
if ($has_dob_col)  $select_cols .= ", dob";

$stmt = $mysqli->prepare("SELECT {$select_cols} FROM passenger WHERE passport_no = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('s', $passport_no);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    $user = ['name' => '', 'email' => '', 'passport_no' => $passport_no, 'phone' => '', 'age' => null, 'dob' => null];
    $_SESSION['flash_error'] = "Failed to load profile.";
}

/* compute display age */
$display_age = null;
if ($has_age_col && isset($user['age']) && $user['age'] !== null && $user['age'] !== '') {
    $display_age = (int)$user['age'];
} elseif ($has_dob_col && !empty($user['dob'])) {
    $dob_ts = strtotime($user['dob']);
    if ($dob_ts !== false) {
        $dob_dt = new DateTimeImmutable('@' . $dob_ts);
        $now = new DateTimeImmutable('now');
        $display_age = (int)$now->diff($dob_dt)->y;
    } else {
        $display_age = null;
    }
} else {
    $display_age = null;
}

/* fetch bookings with airport join for friendly route */
$bookings_query = "
  SELECT b.booking_id,
         f.flight_id,
         a_src.airport_code AS src_code,
         a_dst.airport_code AS dst_code,
         f.flight_date,
         b.booking_date,
         b.status
  FROM bookings b
  JOIN flight f ON b.flight_id = f.flight_id
  LEFT JOIN airport a_src ON f.source_id = a_src.airport_id
  LEFT JOIN airport a_dst ON f.destination_id = a_dst.airport_id
  WHERE b.passport_no = ?
  ORDER BY b.booking_date DESC
";
$stmt2 = $mysqli->prepare($bookings_query);
if ($stmt2) {
    $stmt2->bind_param('s', $passport_no);
    $stmt2->execute();
    $bookings_result = $stmt2->get_result();
    $stmt2->close();
} else {
    $bookings_result = null;
}
?>

<div class="container mt-5" id="profile">
  <div class="card shadow p-4">
    <div class="d-flex justify-content-between align-items-start mb-2">
      <h3 class="text-primary mb-0">👋 Welcome, <?php echo safe_val($user['name'], 'Passenger'); ?>!</h3>
      <div>
        <button id="editToggleBtn" class="btn btn-sm btn-outline-primary me-2">Edit</button>

        <!-- Delete account button -->
        <form id="deleteAccountForm" method="post" style="display:inline;">
          <input type="hidden" name="action" value="delete_account">
          <button id="deleteAccountBtn" type="submit" class="btn btn-sm btn-danger"
            onclick="return confirm('Are you sure you want to permanently delete your account and all related data? This action cannot be undone.');">
            Delete Account
          </button>
        </form>
      </div>
    </div>

    <hr>

    <?php if (!empty($_SESSION['flash_message'])): ?>
      <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
    <?php endif; ?>

    <div class="row mb-3">
      <div class="col-md-7">
        <p><strong>📧 Email:</strong> <?php echo safe_val($user['email']); ?></p>
        <p><strong>🪪 Passport No:</strong> <?php echo safe_val($user['passport_no']); ?></p>
        <p><strong>📞 Phone:</strong> <?php echo safe_val($user['phone']); ?></p>
        <p><strong>🎂 Age:</strong> <?php echo $display_age !== null ? safe_val($display_age) : '—'; ?>
          <?php if ($display_age === null && $has_dob_col && empty($user['dob'])): ?>
            <br><small class="text-muted">No DOB/age on file</small>
          <?php elseif ($has_dob_col && !$has_age_col): ?>
            <br><small class="text-muted">Age computed from DOB</small>
          <?php endif; ?>
        </p>
      </div>

      <!-- Loyalty card -->
      <div class="col-md-5">
        <div class="border rounded-3 p-3" style="background:#f8fbff">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <div>
              <div style="font-size:0.85rem;color:#6b7280">Loyalty</div>
              <div style="font-weight:800;font-size:1.35rem;color:#0b63c6"><?php echo safe_val($loyalty['tier']); ?></div>
            </div>
            <div style="text-align:right">
              <div style="font-size:0.85rem;color:#6b7280">Points</div>
              <div style="font-weight:800;font-size:1.35rem;color:#0b3f8a"><?php echo number_format($loyalty['points']); ?></div>
            </div>
          </div>

          <div style="margin:10px 0">
            <?php
              // progress to next tier (simple thresholds)
              $pts = (int)$loyalty['points'];
              $nextThreshold = 750;
              if ($pts >= 5000) $nextThreshold = null; // top
              elseif ($pts >= 2000) $nextThreshold = 5000;
              elseif ($pts >= 750) $nextThreshold = 2000;
              else $nextThreshold = 750;

              if ($nextThreshold) {
                $pct = min(100, round(($pts / $nextThreshold) * 100, 1));
                if ($pct < 0) $pct = 0;
              } else {
                $pct = 100;
              }
            ?>
            <div style="font-size:.85rem;color:#475569;margin-bottom:6px">
              <?php if ($nextThreshold): ?>
                <?php echo safe_val($pts) ?> / <?php echo safe_val($nextThreshold) ?> pts to <?php echo compute_tier_from_points($nextThreshold) ?? 'next tier'; ?>
              <?php else: ?>
                Top tier achieved
              <?php endif; ?>
            </div>
            <div style="background:#e6f2ff;border-radius:6px;height:10px;overflow:hidden">
              <div style="width:<?php echo $pct ?>%;height:100%;background:#0b63c6"></div>
            </div>
          </div>

          <div style="margin-top:8px;font-size:.85rem;color:#475569">
            <strong>Source:</strong> <?php echo safe_val($loyalty['source'] === 'table' ? 'Stored' : 'Computed'); ?>
            <?php if (!empty($loyalty['updated_at'])): ?>
              <br><small class="text-muted">Updated: <?php echo htmlspecialchars(date('M d, Y', strtotime($loyalty['updated_at']))); ?></small>
            <?php endif; ?>
          </div>

          <?php if ($loyalty['source'] === 'computed'): ?>
            <div class="mt-3">
              <small class="text-muted">Points computed from historical spend (1pt per ₹100). To persist points create a <code>loyalty</code> table.</small>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Edit form (hidden initially) -->
    <div id="editProfile" style="display:none;">
      <form id="profileForm" method="post" action="/FLIGHT_FRONTEND/auth/profile.php#profile" class="row g-3">
        <input type="hidden" name="action" value="update_profile">
        <div class="col-md-6">
          <label class="form-label">Name</label>
          <input class="form-control" type="text" name="name_display" value="<?php echo safe_val($user['name']); ?>" disabled>
        </div>

        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input class="form-control" type="email" value="<?php echo safe_val($user['email']); ?>" disabled>
        </div>

        <div class="col-md-6">
          <label class="form-label">Phone (optional)</label>
          <input id="phoneInput" class="form-control" type="text" name="phone" value="<?php echo safe_val($user['phone'], ''); ?>" placeholder="+91 98765 43210">
          <div class="form-text">Digits only: 7–15 digits. You may include +, -, spaces, parentheses.</div>
          <div id="phoneError" class="text-danger small mt-1" style="display:none;"></div>
        </div>

        <div class="col-md-3">
          <label class="form-label">Age (optional)</label>
          <input id="ageInput" class="form-control" type="number" name="age" min="0" max="120" value="<?php echo $display_age !== null ? safe_val($display_age) : ''; ?>" placeholder="e.g. 29">
          <div id="ageError" class="text-danger small mt-1" style="display:none;"></div>
          <?php if (!$has_age_col && $has_dob_col): ?>
            <div class="form-text">We’ll update your DOB when you change age (approximate).</div>
          <?php endif; ?>
        </div>

        <div class="col-12 mt-3">
          <button id="saveBtn" type="submit" class="btn btn-primary">Save changes</button>
          <button type="button" id="cancelEditBtn" class="btn btn-secondary ms-2">Cancel</button>
        </div>
      </form>
    </div>

    <hr class="my-4">

    <h5 class="text-secondary mb-3">✈️ Your Flight Bookings</h5>
    <?php if ($bookings_result && $bookings_result->num_rows > 0): ?>
      <div class="table-responsive">
      <table class="table table-striped table-bordered">
        <thead class="table-primary">
          <tr>
            <th>Booking ID</th>
            <th>Flight</th>
            <th>Route</th>
            <th>Flight Date</th>
            <th>Booked On</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php while($row = $bookings_result->fetch_assoc()): ?>
          <tr>
            <td><?php echo safe_val($row['booking_id']); ?></td>
            <td><?php echo safe_val($row['flight_id']); ?></td>
            <td><?php echo safe_val($row['src_code']) . ' → ' . safe_val($row['dst_code']); ?></td>
            <td><?php echo safe_val($row['flight_date'] ? date('M d, Y', strtotime($row['flight_date'])) : '—'); ?></td>
            <td><?php echo safe_val($row['booking_date'] ? date('M d, Y H:i', strtotime($row['booking_date'])) : '—'); ?></td>
            <td><?php echo safe_val(ucfirst($row['status'])); ?></td>
            <td>
              <a class="btn btn-sm btn-primary" href="/FLIGHT_FRONTEND/e_ticket.php?booking_id=<?php echo urlencode($row['booking_id']); ?>" target="_blank" rel="noopener noreferrer">View E-ticket</a>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
      </div>
    <?php else: ?>
      <p class="text-muted">You haven’t booked any flights yet.</p>
    <?php endif; ?>

  </div>
</div>

<script>
  (function(){
    const editBtn = document.getElementById('editToggleBtn');
    const viewEls = document.querySelectorAll('#viewProfile, #viewProfile'); // remains for backward compatibility
    const editEl = document.getElementById('editProfile');
    const cancelBtn = document.getElementById('cancelEditBtn');
    const form = document.getElementById('profileForm');
    const phoneInput = document.getElementById('phoneInput');
    const ageInput = document.getElementById('ageInput');
    const phoneError = document.getElementById('phoneError');
    const ageError = document.getElementById('ageError');
    const saveBtn = document.getElementById('saveBtn');
    const deleteForm = document.getElementById('deleteAccountForm');

    function showEdit() {
      // hide 'view' fields by toggling CSS. For simplicity we just show the edit block we added
      editEl.style.display = 'block';
      editBtn.textContent = 'Close';
      editBtn.classList.remove('btn-outline-primary');
      editBtn.classList.add('btn-outline-secondary');
    }
    function showView() {
      editEl.style.display = 'none';
      editBtn.textContent = 'Edit';
      editBtn.classList.remove('btn-outline-secondary');
      editBtn.classList.add('btn-outline-primary');
      phoneError.style.display = 'none';
      ageError.style.display = 'none';
    }

    editBtn.addEventListener('click', function(){
      if (!editEl) return;
      if (editEl.style.display === 'none' || editEl.style.display === '') showEdit(); else showView();
    });
    if (cancelBtn) cancelBtn.addEventListener('click', showView);

    function validatePhone() {
      if (!phoneInput) return true;
      phoneError.style.display = 'none';
      const v = phoneInput.value.trim();
      if (!v) return true; // optional
      const digits = v.replace(/\D/g, '');
      if (digits.length < 7) {
        phoneError.textContent = "Phone must contain at least 7 digits.";
        phoneError.style.display = 'block';
        return false;
      }
      if (digits.length > 15) {
        phoneError.textContent = "Phone seems too long.";
        phoneError.style.display = 'block';
        return false;
      }
      return true;
    }
    function validateAge() {
      if (!ageInput) return true;
      ageError.style.display = 'none';
      const v = ageInput.value.trim();
      if (!v) return true; // optional
      if (!/^\d+$/.test(v)) {
        ageError.textContent = "Age must be a whole number.";
        ageError.style.display = 'block';
        return false;
      }
      const n = parseInt(v, 10);
      if (n < 18) {
        ageError.textContent = "You must be 18 or older to have your own account.";
        ageError.style.display = 'block';
        return false;
      }
      if (n > 120) {
        ageError.textContent = "Age must be between 18 and 120.";
        ageError.style.display = 'block';
        return false;
      }
      return true;
    }

    if (phoneInput) phoneInput.addEventListener('input', validatePhone);
    if (ageInput) ageInput.addEventListener('input', validateAge);

    if (form) {
      form.addEventListener('submit', function(e){
        const okPhone = validatePhone();
        const okAge = validateAge();
        if (!okPhone || !okAge) {
          e.preventDefault();
          return false;
        }
        if (saveBtn) {
          saveBtn.disabled = true;
          saveBtn.textContent = 'Saving...';
        }
        return true;
      });
    }

    if (deleteForm) {
      deleteForm.addEventListener('submit', function(e){
        const ok = confirm('Are you absolutely sure? This will permanently delete your account and all related bookings/tickets.');
        if (!ok) e.preventDefault();
      });
    }

    <?php if (!empty($_SESSION['flash_error'])): ?>
      // show edit if there was a server error during update
      showEdit();
    <?php endif; ?>
  })();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
