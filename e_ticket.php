<?php
// /FLIGHT_FRONTEND/e_ticket.php (minimal fixes: detect flights table, no-cache, booking-by-id source marker)
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
safe_start_session();

if (!isset($_SESSION['passport_no'])) {
    header('Location: /FLIGHT_FRONTEND/auth/login.php');
    exit;
}

require_once __DIR__ . '/includes/header.php';

// prevent caching (helps avoid stale e-ticket showing)
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// accept ticket_no OR booking_id (booking_id takes precedence)
$ticket_no  = isset($_GET['ticket_no'])  ? trim($_GET['ticket_no'])  : null;
$booking_id = isset($_GET['booking_id']) ? trim($_GET['booking_id']) : null;
$passport   = $_SESSION['passport_no'] ?? '—';

$data = null;
$source_table = null; // will be set to indicate which lookup succeeded

// ---------- helper: detect whether a column exists in a table ----------
function col_exists($mysqli, $table, $col) {
    $t = $mysqli->real_escape_string($table);
    $c = $mysqli->real_escape_string($col);
    $res = $mysqli->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return ($res && $res->num_rows > 0);
}

// ---------- detect whether flights table is named `flights` or `flight` ----------
function detect_flights_table($mysqli) {
    $res = $mysqli->query("SHOW TABLES LIKE 'flights'");
    if ($res && $res->num_rows > 0) return 'flights';
    $res2 = $mysqli->query("SHOW TABLES LIKE 'flight'");
    if ($res2 && $res2->num_rows > 0) return 'flight';
    // fallback to 'flights' (most of your other pages used 'flights')
    return 'flights';
}
$flight_table = detect_flights_table($mysqli);

// ---------- time columns ----------
$use_d_time = col_exists($mysqli, $flight_table, 'd_time');
$use_a_time = col_exists($mysqli, $flight_table, 'a_time');
$use_departure_time = col_exists($mysqli, $flight_table, 'departure_time');
$use_arrival_time = col_exists($mysqli, $flight_table, 'arrival_time');

$d_time_select = $use_d_time ? "f.d_time AS d_time" : ($use_departure_time ? "f.departure_time AS d_time" : "NULL AS d_time");
$a_time_select = $use_a_time ? "f.a_time AS a_time" : ($use_arrival_time ? "f.arrival_time AS a_time" : "NULL AS a_time");

// ---------- source/destination ----------
$source_select = col_exists($mysqli, $flight_table, 'source') ? "f.source" : "NULL AS source";
$destination_select = col_exists($mysqli, $flight_table, 'destination') ? "f.destination" : "NULL AS destination";

// ---------- PNR / flight code detection ----------
$pnr_col_candidates = ['flight_code','flight_no','flight_number','code','flightid','flight_id'];
$pnr_select = "NULL AS pnr_code";
foreach ($pnr_col_candidates as $c) {
    if (col_exists($mysqli, $flight_table, $c)) {
        $col = $mysqli->real_escape_string($c);
        $pnr_select = "f.`{$col}` AS pnr_code";
        break;
    }
}

// ---------- PRICE detection ----------
$price_col_candidates = ['base_price','price','f_price','fare','amount'];
$price_select = "0 AS price"; // safe fallback
foreach ($price_col_candidates as $c) {
    if (col_exists($mysqli, $flight_table, $c)) {
        $col = $mysqli->real_escape_string($c);
        $price_select = "COALESCE(f.`{$col}`, 0) AS price";
        break;
    }
}

// ---------- seat/class presence detection (safe checks) ----------
$seat_exists_in_bookings = col_exists($mysqli, 'bookings', 'seat_no');
$seat_exists_in_ticket   = col_exists($mysqli, 'ticket', 'seat_no');

// -------------------------------------------------------------------------------

// 1) booking_id lookup (booking_id takes precedence) — this must work for "from my bookings"
if ($booking_id !== null && $booking_id !== '') {
    $sql = "
      SELECT b.booking_id AS ticket_no, b.booking_date, b.status,
             f.flight_id, {$pnr_select}, {$source_select} AS source, {$destination_select} AS destination,
             {$d_time_select}, {$a_time_select},
             {$price_select},
             b.seat_no AS seat_no, b.class AS class
      FROM bookings b
      JOIN {$flight_table} f ON b.flight_id = f.flight_id
      WHERE b.booking_id = ? AND b.passport_no = ?
      LIMIT 1
    ";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        if (ctype_digit((string)$booking_id)) {
            $bi = intval($booking_id);
            $stmt->bind_param('is', $bi, $passport);
        } else {
            $stmt->bind_param('ss', $booking_id, $passport);
        }
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        if ($data) {
            $source_table = 'booking_by_id';
        } else {
            error_log("e_ticket: booking lookup returned no rows for booking_id={$booking_id} passport={$passport}");
        }
        $stmt->close();
    } else {
        error_log("e_ticket: booking-by-id prepare failed: " . $mysqli->error);
    }
}

// 2) ticket lookup by ticket_no (original behavior)
if (!$data && $ticket_no) {
    $sql = "
        SELECT t.*, {$d_time_select}, {$a_time_select}, {$source_select} AS source, {$destination_select} AS destination, al.airline_name, {$pnr_select}, {$price_select}
        FROM ticket t
        LEFT JOIN {$flight_table} f ON t.flight_id = f.flight_id
        LEFT JOIN airline al ON f.airline_id = al.airline_id
        WHERE t.ticket_no = ? AND t.passport_no = ? LIMIT 1
    ";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $ticket_no, $passport);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        if ($data) $source_table = 'ticket';
        $stmt->close();
    } else {
        error_log("e_ticket: ticket prepare failed: " . $mysqli->error);
    }
}

// 3) latest ticket for passport (original fallback)
if (!$data) {
    $sql = "
        SELECT t.*, {$d_time_select}, {$a_time_select}, {$source_select} AS source, {$destination_select} AS destination, al.airline_name, {$pnr_select}, {$price_select}
        FROM ticket t
        LEFT JOIN {$flight_table} f ON t.flight_id = f.flight_id
        LEFT JOIN airline al ON f.airline_id = al.airline_id
        WHERE t.passport_no = ? ORDER BY t.booking_date DESC LIMIT 1
    ";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $passport);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        if ($data) $source_table = 'ticket_latest';
        $stmt->close();
    } else {
        error_log("e_ticket: ticket-latest prepare failed: " . $mysqli->error);
    }
}

// 4) final fallback: latest booking for passport
if (!$data) {
    $sql = "
      SELECT b.booking_id AS ticket_no, b.booking_date, b.status,
             f.flight_id, {$pnr_select}, {$source_select} AS source, {$destination_select} AS destination,
             {$d_time_select}, {$a_time_select},
             {$price_select},
             b.seat_no AS seat_no, b.class AS class
      FROM bookings b
      JOIN {$flight_table} f ON b.flight_id = f.flight_id
      WHERE b.passport_no = ?
      ORDER BY b.booking_date DESC
      LIMIT 1
    ";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $passport);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        if ($data) $source_table = 'booking_latest';
        $stmt->close();
    } else {
        error_log("e_ticket: booking-latest prepare failed: " . $mysqli->error);
    }
}

if (!$data) {
    echo "<div class='alert alert-warning'>Ticket not found.</div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// small debug notice so you can see which lookup was used (remove in production)
if (!empty($source_table)) {
    echo '<div class="container"><div class="alert alert-info small">Data source used: ' . htmlspecialchars($source_table) . '</div></div>';
}

/* -------------------- rest of your original file unchanged -------------------- */

// SAFE helpers & fallbacks
function safe($v, $fallback = '—') {
    return htmlspecialchars($v ?? $fallback);
}
function fmt_full($raw) {
    if (!$raw) return 'Unknown';
    $ts = strtotime($raw);
    if ($ts === false) return htmlspecialchars($raw);
    return date('d M Y, h:i A', $ts);
}
function fmt_time($raw) {
    if (!$raw) return '—';
    $ts = strtotime($raw);
    if ($ts === false) return htmlspecialchars($raw);
    return date('h:i A', $ts);
}
function fmt_date($raw) {
    if (!$raw) return '—';
    $ts = strtotime($raw);
    if ($ts === false) return htmlspecialchars($raw);
    return date('d M Y', $ts);
}

// Resolve fields (names may vary)
$ticket_id = $data['ticket_no'] ?? $data['booking_id'] ?? '—';
$pnr = $data['pnr_code'] ?? $data['pnr'] ?? ($data['pnr_code'] ?? '');
$passenger_name = $_SESSION['name'] ?? ($data['passenger_name'] ?? 'Passenger');
$flight_id = $data['flight_id'] ?? ($data['flight_no'] ?? 'F1');
$airline = $data['airline_name'] ?? ($data['airline'] ?? 'Lorem Airlines');
$source = $data['source'] ?? $data['f_source'] ?? 'Unknown';
$destination = $data['destination'] ?? $data['f_destination'] ?? 'Unknown';

// NOTE: SELECT guaranteed d_time/a_time aliases (or NULL)
$d_raw = $data['d_time'] ?? $data['departure_time'] ?? null;
$a_raw = $data['a_time'] ?? $data['arrival_time'] ?? null;
$departure_full = fmt_full($d_raw);
$arrival_full = fmt_full($a_raw);
$departure_time = fmt_time($d_raw);
$arrival_time = fmt_time($a_raw);
$departure_date = fmt_date($d_raw);
$arrival_date = fmt_date($a_raw);

$seat = $data['seat_no'] ?? $data['seat'] ?? '—';
$class = $data['class'] ?? $data['class_name'] ?? ($data['seat_class'] ?? 'Economy');

$price_raw = $data['price'] ?? $data['amount'] ?? $data['fare'] ?? $data['f_base_price'] ?? null;
// Note: price was aliased to `price` in SELECT when possible
$price = ($price_raw !== null && $price_raw !== '') ? number_format(floatval($price_raw), 2) : '0.00';

$booked_on_raw = $data['booking_date'] ?? $data['booked_on'] ?? $data['created_at'] ?? null;
$booked_on = $booked_on_raw ? fmt_full($booked_on_raw) : 'Unknown';

// Simple barcode generator using CSS repeating gradients (visual only, not scanner-grade)
$barcode_text = $pnr ?: $ticket_id;

// ---------- HTML + inline CSS for boarding-pass style (kept same as your file) ----------
?>
<!-- (UI markup & CSS unchanged - paste your existing markup here) -->
<style>
/* Boarding pass card */
.boarding-wrap {
  max-width: 980px;
  margin: 24px auto;
  font-family: "Poppins", "Segoe UI", system-ui, -apple-system, "Helvetica Neue", Arial;
  color: #1f2937;
  padding: 20px;
}
.boarding { display: flex; gap: 24px; align-items: stretch; background: transparent; }
.bp-left { flex: 1; border-radius: 12px; padding: 22px; background: linear-gradient(180deg,#ffffff,#f7fbff); box-shadow: 0 6px 18px rgba(30,41,59,0.06); border: 1px solid rgba(0,0,0,0.04); position: relative; }
.bp-right { width: 320px; border-radius: 12px; padding: 20px; background: linear-gradient(180deg,#eef6ff,#ffffff); box-shadow: 0 6px 18px rgba(30,41,59,0.04); border: 1px solid rgba(0,0,0,0.04); position: relative; display: flex; flex-direction: column; justify-content: space-between; }
.airline { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px; }
.airline .name { font-weight:700; color:#0b63c6; font-size:1.1rem; }
.airline .logo { width:72px; height:36px; background:#0b63c6; color:white; display:flex; align-items:center; justify-content:center; border-radius:6px; font-weight:700; }
.route { display:flex; align-items:center; gap:12px; margin:10px 0 16px; }
.airport { flex:1; }
.airport .code { font-size:1.8rem; font-weight:800; color:#0b3f8a; }
.airport .city { font-size:0.9rem; color:#374151; }
.arrow { width:64px; text-align:center; color:#7c93c9; font-weight:700; }
.details { display:grid; grid-template-columns: repeat(3, 1fr); gap:12px; margin-top:8px; }
.detail { background:rgba(11,99,198,0.04); border-radius:8px; padding:10px; text-align:left; }
.detail .label { font-size:0.75rem; color:#475569; }
.detail .val { font-weight:700; font-size:1rem; margin-top:6px; color:#0b3f8a; }
.footer-row { display:flex; gap:12px; align-items:center; margin-top:16px; flex-wrap:wrap; }
.small { font-size:0.85rem; color:#374151; }
.barcode { height:52px; background-image: repeating-linear-gradient(90deg, #111 0 2px, transparent 2px 6px); margin-top:8px; border-radius:4px; }
.actions { display:flex; gap:10px; margin-top:14px; }
.btn { padding:8px 12px; border-radius:8px; display:inline-block; text-decoration:none; font-weight:600; cursor:pointer; border: none; }
.btn-primary { background:#0b63c6; color:white; }
.btn-secondary { background:#f1f5f9; color:#0b3f8a; border:1px solid rgba(11,63,138,0.06); }
@media (max-width: 820px) { .boarding { flex-direction:column; } .bp-right { width:100%; } }
@media print { body * { visibility: hidden; } .printable, .printable * { visibility: visible; } .printable { position: absolute; left:0; top:0; width:100%; } }
</style>

<div class="boarding-wrap printable">
  <div class="boarding">

    <!-- LEFT: big boarding card -->
    <div class="bp-left">
      <div class="airline">
        <div><div class="logo">LA</div></div>
        <div class="name"><?= safe($airline) ?></div>
      </div>

      <div class="route">
        <div class="airport">
          <div class="code"><?= safe($source) ?></div>
          <div class="city">From</div>
        </div>

        <div class="arrow">→</div>

        <div class="airport" style="text-align:right">
          <div class="code"><?= safe($destination) ?></div>
          <div class="city">To</div>
        </div>
      </div>

      <div class="details">
        <div class="detail"><div class="label">Passenger</div><div class="val"><?= safe($passenger_name) ?></div></div>
        <div class="detail"><div class="label">Flight</div><div class="val"><?= safe($flight_id) ?></div></div>
        <div class="detail"><div class="label">Seat</div><div class="val"><?= safe($seat) ?></div></div>

        <div class="detail"><div class="label">Date</div><div class="val"><?= safe($departure_date) ?></div></div>
        <div class="detail"><div class="label">Departure</div><div class="val"><?= safe($departure_time) ?></div></div>
        <div class="detail"><div class="label">Arrival</div><div class="val"><?= safe($arrival_time) ?></div></div>

        <div class="detail"><div class="label">Class</div><div class="val"><?= safe($class) ?></div></div>
        <div class="detail"><div class="label">PNR</div><div class="val"><?= safe($pnr ?: $ticket_id) ?></div></div>
        <div class="detail"><div class="label">Price</div><div class="val">₹<?= safe($price) ?></div></div>
      </div>

      <div class="footer-row">
        <div class="small"><strong>Booked on:</strong> <?= safe($booked_on) ?></div>
        <div class="small">Passport: <?= safe($passport) ?></div>
      </div>

      <div class="barcode" aria-hidden="true"></div>
    </div>

    <!-- RIGHT: stub -->
    <div class="bp-right">
      <div>
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <div>
            <div style="font-size:0.85rem; color:#6b7280;">BOARDING PASS</div>
            <div style="font-weight:800; font-size:1.3rem; color:#0b3f8a;"><?= safe($departure_time) ?></div>
            <div style="font-size:0.75rem; color:#475569;"><?= safe($departure_date) ?></div>
          </div>
          <div style="text-align:right">
            <div style="font-size:0.85rem; color:#6b7280;">Class</div>
            <div style="font-weight:700; font-size:1.05rem; color:#0b3f8a;"><?= safe($class) ?></div>
          </div>
        </div>

        <hr style="margin:14px 0; border:none; border-top:1px dashed rgba(0,0,0,0.06)">

        <div class="stub-mid">
          <div style="font-size:0.82rem; color:#6b7280;">Passenger</div>
          <div style="font-weight:700; font-size:1.02rem; color:#111827;"><?= safe($passenger_name) ?></div>

          <div style="margin-top:10px;">
            <div style="font-size:0.82rem; color:#6b7280;">Route</div>
            <div style="font-weight:700; font-size:1.02rem; color:#111827;"><?= safe($source) ?> → <?= safe($destination) ?></div>
          </div>
        </div>
      </div>

      <div>
        <div class="barcode" style="height:64px;"></div>
        <div style="margin-top:8px; display:flex; justify-content:space-between; align-items:center;">
          <div style="font-size:0.78rem; color:#6b7280;">Ticket</div>
          <div style="font-weight:700;"><?= safe($ticket_id) ?></div>
        </div>

        <div style="margin-top:8px; display:flex; justify-content:space-between; align-items:center;">
          <div style="font-size:0.78rem; color:#6b7280;">PNR</div>
          <div style="font-weight:700;"><?= safe($pnr ?: $ticket_id) ?></div>
        </div>

        <div class="actions">
          <button class="btn btn-primary" onclick="window.print()">Print E-ticket</button>
          <a class="btn btn-secondary" href="/FLIGHT_FRONTEND/index.php">Back to Home</a>
        </div>
      </div>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
