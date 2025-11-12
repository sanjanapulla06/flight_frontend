<?php
// admin_dashboard.php
require_once __DIR__ . '/includes/auth_check.php'; // blocks non-admins
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Flash message
$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

// Helpers
function detect_table($mysqli, array $names, $fallback) {
    foreach ($names as $n) {
        $r = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($n) . "'");
        if ($r && $r->num_rows > 0) return $n;
    }
    return $fallback;
}

function safe($v) { return htmlspecialchars($v ?? '—', ENT_QUOTES); }

// Handle ground-info update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_ground') {
    $flight_id = trim($_POST['flight_id'] ?? '');
    $terminal  = trim($_POST['terminal'] ?? '');
    $gate      = trim($_POST['gate'] ?? '');
    $belt      = trim($_POST['baggage_belt'] ?? '');

    if (!$flight_id) {
        $_SESSION['admin_flash'] = ['type'=>'danger','msg'=>'Flight ID required.'];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $flight_table = detect_table($mysqli, ['flight','flights'], 'flight');

    $stmt = $mysqli->prepare("SELECT 1 FROM {$flight_table} WHERE flight_id=? LIMIT 1");
    $stmt->bind_param('s', $flight_id);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row();
    $stmt->close();

    if (!$exists) {
        $_SESSION['admin_flash'] = ['type'=>'danger','msg'=>"Flight not found: {$flight_id}"];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Ensure flight_ground_info table exists
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS flight_ground_info (
            info_id INT AUTO_INCREMENT PRIMARY KEY,
            flight_id VARCHAR(64) NOT NULL UNIQUE,
            terminal VARCHAR(32),
            gate VARCHAR(32),
            baggage_belt VARCHAR(32),
            checkin_counter VARCHAR(64),
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX(flight_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $upd = $mysqli->prepare("UPDATE flight_ground_info SET terminal=?, gate=?, baggage_belt=?, updated_at=NOW() WHERE flight_id=? LIMIT 1");
    $upd->bind_param('ssss', $terminal, $gate, $belt, $flight_id);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();

    if ($affected === 0) {
        $ins = $mysqli->prepare("INSERT INTO flight_ground_info (flight_id, terminal, gate, baggage_belt, updated_at) VALUES (?, ?, ?, ?, NOW())");
        $ins->bind_param('ssss', $flight_id, $terminal, $gate, $belt);
        $ins->execute();
        $ins->close();
    }

    $_SESSION['admin_flash'] = ['type'=>'success','msg'=>"Ground info updated for flight {$flight_id}."];
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Gather stats
$flight_table = detect_table($mysqli, ['flight','flights'], 'flight');
$tables = [
    'flights' => $flight_table,
    'bookings' => detect_table($mysqli, ['bookings'], 'bookings'),
    'passenger' => detect_table($mysqli, ['passenger'], 'passenger'),
    'loyalty' => detect_table($mysqli, ['loyalty','loyalty_points','frequent_flyer','ff_members'], null)
];

$counts = [];
foreach ($tables as $k => $tbl) {
    $counts[$k] = 0;
    if (!$tbl) continue;
    $res = $mysqli->query("SELECT COUNT(*) AS c FROM {$tbl}");
    if ($res) $counts[$k] = intval($res->fetch_assoc()['c'] ?? 0);
}

// Recent bookings
$recentBookings = [];
$res = $mysqli->query("
    SELECT b.booking_id, b.passport_no, b.seat_no, b.class, b.booking_date,
           COALESCE(f.flight_id,b.flight_id) AS flight_id,
           COALESCE(src.airport_code,'') AS src, COALESCE(dst.airport_code,'') AS dst
    FROM {$tables['bookings']} b
    LEFT JOIN {$flight_table} f ON f.flight_id = b.flight_id
    LEFT JOIN airport src ON f.source_id=src.airport_id
    LEFT JOIN airport dst ON f.destination_id=dst.airport_id
    ORDER BY b.booking_date DESC
    LIMIT 8
");
if ($res) while ($r = $res->fetch_assoc()) $recentBookings[] = $r;

// Recent flights
$recentFlights = [];
$res = $mysqli->query("
    SELECT f.flight_id, COALESCE(al.airline_name,'') AS airline,
           COALESCE(src.airport_code,'') AS src, COALESCE(dst.airport_code,'') AS dst,
           COALESCE(f.departure_time,f.d_time) AS departure_time
    FROM {$flight_table} f
    LEFT JOIN airport src ON f.source_id=src.airport_id
    LEFT JOIN airport dst ON f.destination_id=dst.airport_id
    LEFT JOIN airline al ON f.airline_id=al.airline_id
    ORDER BY COALESCE(f.departure_time,f.d_time,f.flight_id) ASC
    LIMIT 12
");
if ($res) while ($r = $res->fetch_assoc()) $recentFlights[] = $r;

$ground_map = [];
if ($recentFlights) {
    $flight_ids = array_map(fn($f) => "'" . $mysqli->real_escape_string($f['flight_id']) . "'", $recentFlights);
    $id_list = implode(',', $flight_ids);
    $gres = $mysqli->query("SELECT flight_id, terminal, gate, baggage_belt FROM flight_ground_info WHERE flight_id IN ($id_list)");
    if ($gres) while ($g = $gres->fetch_assoc()) $ground_map[$g['flight_id']] = $g;
}


require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h2>Admin Dashboard</h2>
      <div class="small text-muted">Welcome back, <?= safe($_SESSION['admin_name'] ?? $_SESSION['admin_username']) ?> — role: <?= safe($_SESSION['admin_role']) ?></div>
    </div>
    <div class="btn-group">
      <a href="/FLIGHT_FRONTEND/" class="btn btn-light">Site home</a>
      <a href="/FLIGHT_FRONTEND/admin/admin_register.php" class="btn btn-outline-primary">Create Admin</a>
      <a href="/FLIGHT_FRONTEND/admin/admin_logout.php" class="btn btn-danger">Logout</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?? 'info' ?>"><?= safe($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Flights</div><div class="h4 mb-0"><?= number_format($counts['flights']) ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Bookings</div><div class="h4 mb-0"><?= number_format($counts['bookings']) ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Passengers</div><div class="h4 mb-0"><?= number_format($counts['passenger']) ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Loyalty members</div><div class="h4 mb-0"><?= number_format($counts['loyalty']) ?></div></div></div>
  </div>

  <!-- Employee Management Dropdown -->
  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="dropdown">
        <button class="btn btn-primary w-100 dropdown-toggle" type="button" id="employeeDropdown" data-bs-toggle="dropdown" aria-expanded="false">Employee</button>
        <ul class="dropdown-menu w-100" aria-labelledby="employeeDropdown">
          <li><a class="dropdown-item" href="/FLIGHT_FRONTEND/admin/add_employee.php">➕ Add Employee</a></li>
          <li><a class="dropdown-item" href="/FLIGHT_FRONTEND/admin/manage_employee.php">🗂 Manage Employees</a></li>
          <li><a class="dropdown-item" href="/FLIGHT_FRONTEND/admin/view_employee.php">👤 View Employee</a></li>
        </ul>
      </div>
    </div>
  </div>

  <!-- Recent Bookings & Flights -->
  <div class="row mb-4">
    <div class="col-md-6">
      <div class="card p-3">
        <h5>Recent Bookings</h5>
        <?php if ($recentBookings): ?>
          <div class="list-group">
            <?php foreach ($recentBookings as $b): ?>
              <div class="list-group-item d-flex justify-content-between align-items-start">
                <div>
                  <div><strong><?= safe($b['booking_id']) ?></strong> — <?= safe($b['flight_id']) ?></div>
                  <div class="small text-muted"><?= safe($b['src']) ?> → <?= safe($b['dst']) ?> • <?= safe($b['seat_no']) ?> • <?= safe($b['class']) ?></div>
                </div>
                <div class="text-end">
                  <div class="small text-muted"><?= safe($b['booking_date']) ?></div>
                  <a class="btn btn-sm btn-outline-primary mt-1" href="/FLIGHT_FRONTEND/e_ticket.php?booking_id=<?= urlencode($b['booking_id']) ?>" target="_blank">E-ticket</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="text-muted">No recent bookings found.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card p-3">
        <h5>Recent Flights & Ground Info</h5>
        <?php if ($recentFlights): ?>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>Flight</th><th>Route</th><th>Departure</th><th>Gate</th><th>Terminal</th><th>Belt</th></tr></thead>
              <tbody>
                <?php foreach ($recentFlights as $f): $g = $ground_map[$f['flight_id']] ?? null; ?>
                  <tr>
                    <td><strong><?= safe($f['flight_id']) ?></strong><br><small class="text-muted"><?= safe($f['airline']) ?></small></td>
                    <td><?= safe($f['src']) ?> → <?= safe($f['dst']) ?></td>
                    <td class="small text-muted"><?= safe($f['departure_time']) ?></td>
                    <td><?= safe($g['gate'] ?? '—') ?></td>
                    <td><?= safe($g['terminal'] ?? '—') ?></td>
                    <td><?= safe($g['baggage_belt'] ?? '—') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-muted">No flights found.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Quick Ground Info Update -->
  <div class="card p-3 mb-4">
    <h5>Quick: Update Ground Info (Gate / Terminal / Belt)</h5>
    <form method="post" class="row g-2">
      <input type="hidden" name="action" value="update_ground">
      <div class="col-md-3"><label class="form-label small">Flight ID</label><input name="flight_id" class="form-control" placeholder="SQ1234" required></div>
      <div class="col-md-2"><label class="form-label small">Terminal</label><input name="terminal" class="form-control"></div>
      <div class="col-md-2"><label class="form-label small">Gate</label><input name="gate" class="form-control"></div>
      <div class="col-md-2"><label class="form-label small">Baggage Belt</label><input name="baggage_belt" class="form-control"></div>
      <div class="col-md-3 d-flex align-items-end">
        <button class="btn btn-primary me-2" type="submit">Update Ground Info</button>
        <a class="btn btn-outline-secondary" href="/FLIGHT_FRONTEND/admin/flight_board.php">Open Flight Board</a>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>