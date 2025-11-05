<?php
// admin_dashboard.php (updated with Reschedule modal for admin)
require_once __DIR__ . '/includes/auth_check.php'; // blocks non-admins
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function detect_table($mysqli, array $names, $fallback) {
    foreach ($names as $n) {
        $r = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($n) . "'");
        if ($r && $r->num_rows > 0) return $n;
    }
    return $fallback;
}
function safe($v) { return htmlspecialchars($v ?? '—', ENT_QUOTES); }

$flash = null;
if (!empty($_SESSION['admin_flash'])) { $flash = $_SESSION['admin_flash']; unset($_SESSION['admin_flash']); }

$flight_table = detect_table($mysqli, ['flight','flights'], 'flight');
$tables = [
    'flights' => $flight_table,
    'bookings' => detect_table($mysqli, ['bookings'], 'bookings'),
    'ticket' => detect_table($mysqli, ['ticket'], 'ticket'),
    'passenger' => detect_table($mysqli, ['passenger'], 'passenger'),
    'loyalty' => detect_table($mysqli, ['loyalty','loyalty_points','frequent_fly','ff_members'], 'loyalty')
];

$counts = [];
foreach ($tables as $k => $tbl) {
    $counts[$k] = 0;
    $res = $mysqli->query("SELECT COUNT(*) AS c FROM `{$tbl}`");
    if ($res) { $r = $res->fetch_assoc(); $counts[$k] = intval($r['c'] ?? 0); $res->free(); }
}

// recent bookings: include booking_id + flight_id + seat/class
$recentBookings = [];
$booking_table = $tables['bookings'] ?? 'bookings';
$res = $mysqli->query("
   SELECT b.booking_id, b.passport_no, b.seat_no, b.class, b.booking_date,
          COALESCE(f.flight_id, b.flight_id) AS flight_id,
          COALESCE(src.airport_code, '') AS src, COALESCE(dst.airport_code, '') AS dst
   FROM `{$booking_table}` b
   LEFT JOIN `{$flight_table}` f ON f.flight_id = b.flight_id
   LEFT JOIN airport src ON f.source_id = src.airport_id
   LEFT JOIN airport dst ON f.destination_id = dst.airport_id
   ORDER BY b.booking_date DESC
   LIMIT 12
");
if ($res) while ($r = $res->fetch_assoc()) $recentBookings[] = $r;

// recent flights
$recentFlights = [];
$res2 = $mysqli->query("
   SELECT f.flight_id, COALESCE(al.airline_name,'') AS airline,
          COALESCE(src.airport_code,'') AS src, COALESCE(dst.airport_code,'') AS dst,
          COALESCE(f.departure_time, f.d_time) AS departure_time
   FROM `{$flight_table}` f
   LEFT JOIN airport src ON f.source_id = src.airport_id
   LEFT JOIN airport dst ON f.destination_id = dst.airport_id
   LEFT JOIN airline al ON f.airline_id = al.airline_id
   ORDER BY COALESCE(f.departure_time,f.d_time,f.flight_id) ASC
   LIMIT 12
");
$recentFlightIds = [];
if ($res2) {
    while ($r = $res2->fetch_assoc()) {
        $recentFlights[] = $r;
        if (!empty($r['flight_id'])) $recentFlightIds[] = $r['flight_id'];
    }
    $res2->free();
}

// fetch ground info only for recent flights (safe)
$ground_map = [];
if (!empty($recentFlightIds)) {
    $escaped = array_map(function($id) use ($mysqli) { return "'" . $mysqli->real_escape_string($id) . "'"; }, $recentFlightIds);
    $in = implode(',', $escaped);
    $gres = $mysqli->query("SELECT flight_id, terminal, gate, baggage_belt, updated_at FROM flight_ground_info WHERE flight_id IN ({$in})");
    if ($gres) { while ($g = $gres->fetch_assoc()) $ground_map[$g['flight_id']] = $g; $gres->free(); }
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

  <div class="row mb-4">
    <div class="col-md-6">
      <div class="card p-3">
        <h5>Recent Bookings</h5>
        <?php if (!empty($recentBookings)): ?>
          <div class="list-group">
            <?php foreach ($recentBookings as $b): ?>
              <div class="list-group-item d-flex justify-content-between align-items-start">
                <div>
                  <div><strong><?= safe($b['booking_id']) ?></strong> — <?= safe($b['flight_id']) ?></div>
                  <div class="small text-muted"><?= safe($b['src']) ?> → <?= safe($b['dst']) ?> • <?= safe($b['seat_no']) ?> • <?= safe($b['class']) ?></div>
                </div>
                <div class="text-end">
                  <div class="small text-muted"><?= safe($b['booking_date']) ?></div>

                  <div class="mt-1 d-flex gap-1 justify-content-end">
                    <a class="btn btn-sm btn-outline-primary" href="/FLIGHT_FRONTEND/e_ticket.php?booking_id=<?= urlencode($b['booking_id']) ?>" target="_blank">E-ticket</a>
                    <!-- Admin reschedule -> opens same modal -->
                    <button class="btn btn-sm btn-warning" onclick="openRescheduleModal('<?= htmlspecialchars($b['booking_id'], ENT_QUOTES) ?>')">Reschedule</button>
                    <!-- Admin cancel (optional) -->
                    <form action="/FLIGHT_FRONTEND/cancel_booking.php" method="POST" style="display:inline;">
                      <input type="hidden" name="booking_id" value="<?= htmlspecialchars($b['booking_id']) ?>">
                      <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Cancel this booking (admin)?')">Cancel</button>
                    </form>
                  </div>

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
        <?php if (!empty($recentFlights)): ?>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead>
                <tr><th>Flight</th><th>Route</th><th>Departure</th><th>Gate</th><th>Terminal</th><th>Belt</th></tr>
              </thead>
              <tbody>
                <?php foreach ($recentFlights as $f):
                    $gid = $f['flight_id']; $g = $ground_map[$gid] ?? null;
                ?>
                  <tr>
                    <td><strong><?= safe($gid) ?></strong><br><small class="text-muted"><?= safe($f['airline']) ?></small></td>
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

  <div class="card p-3 mb-4">
    <h5>Quick: Update Ground Info (Gate / Terminal / Belt)</h5>
    <form method="post" class="row g-2" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
      <input type="hidden" name="action" value="update_ground">
      <div class="col-md-3"><label class="form-label small">Flight ID</label><input name="flight_id" class="form-control" placeholder="e.g. SQ2510250220100" required></div>
      <div class="col-md-2"><label class="form-label small">Terminal</label><input name="terminal" class="form-control" placeholder="T1 / T2"></div>
      <div class="col-md-2"><label class="form-label small">Gate</label><input name="gate" class="form-control" placeholder="G12"></div>
      <div class="col-md-2"><label class="form-label small">Baggage Belt</label><input name="baggage_belt" class="form-control" placeholder="B1 / B9"></div>
      <div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary me-2" type="submit">Update Ground Info</button><a class="btn btn-outline-secondary" href="/FLIGHT_FRONTEND/admin/flight_board.php">Open Flight Board</a></div>
    </form>
    <div class="small text-muted mt-2">Tip: This updates the <code>flight_ground_info</code> table used by the Live Flight Board.</div>
  </div>

  <div class="d-flex gap-2">
    <a href="/FLIGHT_FRONTEND/admin/admin_register.php" class="btn btn-outline-primary">Create Admin</a>
    <a href="/FLIGHT_FRONTEND/admin/admin_login.php" class="btn btn-outline-secondary">Admin Login Page</a>
    <a href="/FLIGHT_FRONTEND/admin/admin_logout.php" class="btn btn-danger">Logout</a>
  </div>
</div>

<!-- Reschedule Modal (same as user) -->
<div class="modal fade" id="rescheduleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form id="rescheduleForm" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Reschedule Booking</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="booking_id" id="rs_booking_id" />
        <div class="mb-2"><label class="form-label">New Flight ID *</label><input name="new_flight_id" id="rs_new_flight_id" required class="form-control" placeholder="e.g. SQ251104031916f" /></div>
        <div class="mb-2"><label class="form-label">New Seat (optional)</label><input name="new_seat" id="rs_new_seat" class="form-control" placeholder="e.g. 12A" /></div>
        <div class="mb-2"><label class="form-label">Reason (optional)</label><input name="reason" id="rs_reason" class="form-control" placeholder="e.g. Operational change" /></div>
        <div id="rs_alert" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" id="rs_submit" class="btn btn-primary">Reschedule</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  window.openRescheduleModal = function(bookingId) {
    document.getElementById('rs_booking_id').value = bookingId || '';
    document.getElementById('rs_new_flight_id').value = '';
    document.getElementById('rs_new_seat').value = '';
    document.getElementById('rs_reason').value = '';
    const alertEl = document.getElementById('rs_alert'); alertEl.style.display = 'none'; alertEl.className = ''; alertEl.innerHTML = '';
    var modal = new bootstrap.Modal(document.getElementById('rescheduleModal')); modal.show();
  };

  document.getElementById('rescheduleForm').addEventListener('submit', async function(e){
    e.preventDefault();
    const submitBtn = document.getElementById('rs_submit'); submitBtn.disabled = true; submitBtn.textContent = 'Rescheduling...';
    const fd = new FormData(e.target);
    try {
      const resp = await fetch('/FLIGHT_FRONTEND/api/reschedule_booking.php', {
        method: 'POST', credentials: 'include', body: fd
      });
      const raw = await resp.text();
      let data;
      try { data = JSON.parse(raw); } catch (err) { data = { ok:false, error: raw }; }
      const alertEl = document.getElementById('rs_alert'); alertEl.style.display = 'block';
      if (resp.ok && data.ok) {
        alertEl.className = 'alert alert-success';
        alertEl.innerHTML = 'Booking rescheduled successfully. Booking ID: <strong>' + (data.booking_id||'') + '</strong>';
        setTimeout(() => location.reload(), 900);
      } else {
        alertEl.className = 'alert alert-danger';
        const message = data.error || data.detail || raw;
        alertEl.innerHTML = '<pre style="white-space:pre-wrap;margin:0;">' + message + '</pre>';
        submitBtn.disabled = false; submitBtn.textContent = 'Reschedule';
      }
    } catch (err) {
      const alertEl = document.getElementById('rs_alert'); alertEl.style.display = 'block'; alertEl.className = 'alert alert-danger'; alertEl.innerText = 'Request failed: ' + err;
      submitBtn.disabled = false; submitBtn.textContent = 'Reschedule';
    }
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
