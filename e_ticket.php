<?php
// /FLIGHT_FRONTEND/e_ticket.php
// Always-fresh e-ticket that forces live flight override (date/time/PNR/price/gate/terminal/belt)

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
safe_start_session();

if (empty($_SESSION['passport_no'])) {
    header('Location: /FLIGHT_FRONTEND/auth/login.php');
    exit;
}

// prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/includes/header.php';

$ticket_no  = isset($_GET['ticket_no'])  ? trim($_GET['ticket_no'])  : null;
$booking_id = isset($_GET['booking_id']) ? trim($_GET['booking_id']) : null;
$passport   = $_SESSION['passport_no'] ?? '';

$data = null;
$source_table = null;

/* ---------- helpers ---------- */
function col_exists($mysqli, $table, $col) {
    $t = $mysqli->real_escape_string($table);
    $c = $mysqli->real_escape_string($col);
    $res = $mysqli->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return ($res && $res->num_rows > 0);
}
function detect_flights_table($mysqli) {
    if ($mysqli->query("SHOW TABLES LIKE 'flights'")->num_rows > 0) return 'flights';
    if ($mysqli->query("SHOW TABLES LIKE 'flight'")->num_rows > 0) return 'flight';
    return 'flight';
}
function pick_aliased_col($mysqli, $table, $candidates, $alias, $fallback = "NULL") {
    foreach ($candidates as $c) {
        if (col_exists($mysqli, $table, $c)) {
            $col = $mysqli->real_escape_string($c);
            return "f.`{$col}` AS {$alias}";
        }
    }
    return "{$fallback} AS {$alias}";
}
function hsafe($v, $fallback = '—') { return htmlspecialchars($v ?? $fallback, ENT_QUOTES); }
function fmt_full($raw) { if (!$raw) return 'Unknown'; $ts = strtotime($raw); return $ts ? date('d M Y, h:i A', $ts) : htmlspecialchars($raw); }
function fmt_time($raw) { if (!$raw) return '—'; $ts = strtotime($raw); return $ts ? date('h:i A', $ts) : htmlspecialchars($raw); }
function fmt_date($raw) { if (!$raw) return '—'; $ts = strtotime($raw); return $ts ? date('d M Y', $ts) : htmlspecialchars($raw); }

/* ---------- detect flight table ---------- */
$flight_table = detect_flights_table($mysqli);

/* fragments used everywhere */
$pnr_cols   = ['flight_code','flight_no','flight_number','code','flightid','flight_id'];
$price_cols = ['base_price','price','fare','amount','f_price'];

/* time fragments */
$d_time_frag = col_exists($mysqli, $flight_table, 'd_time') ? "f.d_time AS d_time"
             : (col_exists($mysqli, $flight_table, 'departure_time') ? "f.departure_time AS d_time" : "NULL AS d_time");
$a_time_frag = col_exists($mysqli, $flight_table, 'a_time') ? "f.a_time AS a_time"
             : (col_exists($mysqli, $flight_table, 'arrival_time') ? "f.arrival_time AS a_time" : "NULL AS a_time");

/* pnr / price fragments */
$pnr_frag   = pick_aliased_col($mysqli, $flight_table, $pnr_cols, 'pnr_code', "NULL");
$price_frag = pick_aliased_col($mysqli, $flight_table, $price_cols, 'price', "0");

/* source / destination fragments (aliased pair) */
$use_src_id = col_exists($mysqli, $flight_table, 'source_id');
$use_dst_id = col_exists($mysqli, $flight_table, 'destination_id');

$src_join = '';
$dst_join = '';
$src_select = "NULL AS src_code, NULL AS src_name";
$dst_select = "NULL AS dst_code, NULL AS dst_name";

if ($use_src_id) {
    $src_join   = " LEFT JOIN airport src ON f.source_id = src.airport_id ";
    $src_select = "src.airport_code AS src_code, src.airport_name AS src_name";
} elseif (col_exists($mysqli, $flight_table, 'source')) {
    $src_select = "NULL AS src_code, f.source AS src_name";
}

if ($use_dst_id) {
    $dst_join   = " LEFT JOIN airport dst ON f.destination_id = dst.airport_id ";
    $dst_select = "dst.airport_code AS dst_code, dst.airport_name AS dst_name";
} elseif (col_exists($mysqli, $flight_table, 'destination')) {
    $dst_select = "NULL AS dst_code, f.destination AS dst_name";
}

/* airline fragments */
$airline_join   = col_exists($mysqli, $flight_table, 'airline_id') ? " LEFT JOIN airline al ON f.airline_id = al.airline_id " : "";
$airline_select = col_exists($mysqli, $flight_table, 'airline_id') ? "al.airline_name AS airline_name" : "NULL AS airline_name";

/* ---------- flight_ground_info: join most recent row ---------- */
$ground_select_fragment = "NULL AS gate, NULL AS terminal, NULL AS baggage_belt";
$ground_join_fragment   = "";
if ($mysqli->query("SHOW TABLES LIKE 'flight_ground_info'")->num_rows > 0) {
    $gcols = [];
    $gres = $mysqli->query("SHOW COLUMNS FROM flight_ground_info");
    if ($gres) while ($c = $gres->fetch_assoc()) $gcols[] = $c['Field'];

    $col_gate     = in_array('gate', $gcols) ? 'gate' : (in_array('g_gate', $gcols) ? 'g_gate' : null);
    $col_terminal = in_array('terminal', $gcols) ? 'terminal' : (in_array('term', $gcols) ? 'term' : null);
    $col_belt     = in_array('baggage_belt', $gcols) ? 'baggage_belt' : (in_array('belt', $gcols) ? 'belt' : null);

    $join_key = in_array('flight_id', $gcols) ? 'flight_id' : (in_array('flight', $gcols) ? 'flight' : null);

    if ($join_key && ($col_gate || $col_terminal || $col_belt)) {
        $has_updated_at = in_array('updated_at', $gcols);

        if ($has_updated_at) {
            $ground_join_fragment = "
              LEFT JOIN flight_ground_info g ON f.flight_id = g.flight_id
              AND g.updated_at = (
                SELECT MAX(g2.updated_at) FROM flight_ground_info g2 WHERE g2.flight_id = f.flight_id
              )
            ";
        } else {
            $ground_join_fragment = "
              LEFT JOIN flight_ground_info g ON f.flight_id = g.flight_id
              AND g.info_id = (
                SELECT MAX(g2.info_id) FROM flight_ground_info g2 WHERE g2.flight_id = f.flight_id
              )
            ";
        }

        $parts = [];
        $parts[] = $col_gate     ? "g.`{$col_gate}` AS gate"           : "NULL AS gate";
        $parts[] = $col_terminal ? "g.`{$col_terminal}` AS terminal"   : "NULL AS terminal";
        $parts[] = $col_belt     ? "g.`{$col_belt}` AS baggage_belt"   : "NULL AS baggage_belt";
        $ground_select_fragment = implode(', ', $parts);
    }
}

/* ---------- 1) Try booking lookup by booking_id ---------- */
if ($booking_id !== null && $booking_id !== '') {
    $sql = "
      SELECT b.booking_id AS ticket_no, b.booking_date, b.status,
             f.flight_id, {$pnr_frag}, {$src_select}, {$dst_select},
             {$d_time_frag}, {$a_time_frag}, {$price_frag},
             b.seat_no AS seat_no, b.class AS class, b.passport_no AS booked_passport,
             {$airline_select},
             {$ground_select_fragment}
      FROM bookings b
      JOIN {$flight_table} f ON b.flight_id = f.flight_id
      {$src_join} {$dst_join} {$airline_join}
      {$ground_join_fragment}
      WHERE b.booking_id = ? AND b.passport_no = ?
      LIMIT 1
    ";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        if (ctype_digit((string)$booking_id)) {
            $bi = (int)$booking_id;
            $stmt->bind_param('is', $bi, $passport);
        } else {
            $stmt->bind_param('ss', $booking_id, $passport);
        }
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        if ($data) $source_table = 'booking_by_id';
        $stmt->close();
    }
}

/* ---------- 2) Ticket lookup by ticket_no, preferring bookings data ---------- */
if (!$data && $ticket_no) {
    $sql = "
      SELECT
        t.ticket_no,
        COALESCE(b.booking_id, t.booking_id) AS booking_id,
        COALESCE(b.booking_date, t.booking_date) AS booking_date,
        COALESCE(b.status, t.status) AS status,
        COALESCE(b.seat_no, t.seat_no) AS seat_no,
        COALESCE(b.class, t.class) AS class,
        COALESCE(b.flight_id, t.flight_id) AS flight_id,
        {$pnr_frag},
        {$src_select}, {$dst_select},
        {$d_time_frag}, {$a_time_frag}, {$price_frag},
        {$airline_select},
        {$ground_select_fragment}
      FROM ticket t
      LEFT JOIN bookings b ON t.booking_id = b.booking_id
      LEFT JOIN {$flight_table} f ON COALESCE(b.flight_id, t.flight_id) = f.flight_id
      {$src_join} {$dst_join} {$airline_join}
      {$ground_join_fragment}
      WHERE t.ticket_no = ? AND t.passport_no = ?
      LIMIT 1
    ";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $ticket_no, $passport);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        if ($data) $source_table = 'ticket_with_booking_prefer_booking';
        $stmt->close();
    }
}

/* ---------- 3) Latest ticket for passport ---------- */
if (!$data) {
    $sql = "
      SELECT t.ticket_no, t.booking_date, t.passport_no AS booked_passport, t.seat_no, t.class,
             f.flight_id, {$pnr_frag}, {$src_select}, {$dst_select},
             {$d_time_frag}, {$a_time_frag}, {$price_frag},
             {$airline_select},
             {$ground_select_fragment}
      FROM ticket t
      LEFT JOIN {$flight_table} f ON t.flight_id = f.flight_id
      {$src_join} {$dst_join} {$airline_join}
      {$ground_join_fragment}
      WHERE t.passport_no = ?
      ORDER BY t.booking_date DESC LIMIT 1
    ";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $passport);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        if ($data) $source_table = 'ticket_latest';
        $stmt->close();
    }
}

/* ---------- 4) Fallback: latest booking for passport ---------- */
if (!$data) {
    $sql = "
      SELECT b.booking_id AS ticket_no, b.booking_date, b.status,
             f.flight_id, {$pnr_frag}, {$src_select}, {$dst_select},
             {$d_time_frag}, {$a_time_frag}, {$price_frag},
             b.seat_no AS seat_no, b.class AS class, b.passport_no AS booked_passport,
             {$airline_select},
             {$ground_select_fragment}
      FROM bookings b
      JOIN {$flight_table} f ON b.flight_id = f.flight_id
      {$src_join} {$dst_join} {$airline_join}
      {$ground_join_fragment}
      WHERE b.passport_no = ?
      ORDER BY b.booking_date DESC LIMIT 1
    ";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $passport);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        if ($data) $source_table = 'booking_latest';
        $stmt->close();
    }
}

if (!$data) {
    echo "<div class='alert alert-warning'>Ticket not found.</div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

/* ---------- If rescheduled, prefer the latest new_flight_id from reschedule_tx ---------- */
$flight_id = $data['flight_id'] ?? null;
$reschedule_note = null;
$booking_updated = false;

if ($booking_id && $mysqli->query("SHOW TABLES LIKE 'reschedule_tx'")->num_rows > 0) {
    $rs = $mysqli->prepare("
        SELECT new_flight_id, requested_date, status, COALESCE(processed_at, created_at) AS tx_time
        FROM reschedule_tx
        WHERE booking_id = ?
        ORDER BY COALESCE(processed_at, created_at) DESC
        LIMIT 1
    ");
    if ($rs) {
        $rs->bind_param('s', $booking_id);
        $rs->execute();
        $rsrow = $rs->get_result()->fetch_assoc();
        $rs->close();
        if ($rsrow) {
            // Always prefer new_flight_id if present (user explicitly chose a flight)
            if (!empty($rsrow['new_flight_id'])) {
                $flight_id = $rsrow['new_flight_id']; // override with the rescheduled flight id
                $booking_updated = true;
                if ($rsrow['status'] === 'completed' || $rsrow['status'] === 'processed') {
                    $reschedule_note = "Rescheduled on " . date('d M Y', strtotime($rsrow['tx_time']));
                } else {
                    $reschedule_note = "Reschedule pending";
                }
            }
        }
    }
}

// If booking was rescheduled, refresh the booking row to get the latest flight_id from bookings table
if ($booking_updated && $booking_id) {
    $refresh_sql = "SELECT flight_id FROM bookings WHERE booking_id = ? LIMIT 1";
    $refresh_stmt = $mysqli->prepare($refresh_sql);
    if ($refresh_stmt) {
        if (ctype_digit((string)$booking_id)) {
            $bi = (int)$booking_id;
            $refresh_stmt->bind_param('i', $bi);
        } else {
            $refresh_stmt->bind_param('s', $booking_id);
        }
        $refresh_stmt->execute();
        $refresh_row = $refresh_stmt->get_result()->fetch_assoc();
        $refresh_stmt->close();
        if ($refresh_row && !empty($refresh_row['flight_id'])) {
            // Use the booking table's flight_id if it's been updated (takes precedence)
            $flight_id = $refresh_row['flight_id'];
        }
    }
}

/* ============================================================================
   🔥 FORCED LIVE FLIGHT OVERRIDE (SOURCE OF TRUTH)
   Always use the latest flight row for times/PNR/price/ground info.
   ============================================================================ */
if (!empty($flight_id)) {
    // Build selects that ensure we fetch canonical columns again
    $dep_select = col_exists($mysqli, $flight_table, 'departure_time') ? "f.departure_time AS departure_time"
                 : (col_exists($mysqli, $flight_table, 'd_time') ? "f.d_time AS departure_time" : "NULL AS departure_time");
    $arr_select = col_exists($mysqli, $flight_table, 'arrival_time') ? "f.arrival_time AS arrival_time"
                 : (col_exists($mysqli, $flight_table, 'a_time') ? "f.a_time AS arrival_time" : "NULL AS arrival_time");

    $sql = "
      SELECT f.flight_id,
             {$pnr_frag},
             {$dep_select},
             {$arr_select},
             {$price_frag},
             {$airline_select},
             {$src_select},
             {$dst_select},
             {$ground_select_fragment}
      FROM {$flight_table} f
      {$src_join} {$dst_join} {$airline_join}
      {$ground_join_fragment}
      WHERE f.flight_id = ?
      LIMIT 1
    ";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $flight_id);
        $stmt->execute();
        $fresh = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($fresh) {
            // Hard override: if the fresh value is non-null/non-empty, copy it in.
            foreach ($fresh as $k => $v) {
                if ($v !== null && $v !== '') {
                    $data[$k] = $v;
                }
            }
        }
    }
}

/* ---------- Passenger name ---------- */
$passenger_name = $_SESSION['name'] ?? null;
if (empty($passenger_name)) {
    $p_pass = $data['booked_passport'] ?? $passport;
    if ($p_pass) {
        $stmt = $mysqli->prepare("SELECT name FROM passenger WHERE passport_no = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $p_pass);
            $stmt->execute();
            $pr = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($pr && !empty($pr['name'])) $passenger_name = $pr['name'];
        }
    }
}
if (empty($passenger_name)) $passenger_name = 'Passenger';

/* ---------- Resolve fields (after override) ---------- */
$ticket_id = $data['ticket_no'] ?? $data['booking_id'] ?? ($data['pnr_code'] ?? null) ?? '—';
$pnr       = $data['pnr_code'] ?? $data['pnr'] ?? $ticket_id;
$flight_id = $data['flight_id'] ?? ($data['flight_code'] ?? null);
$airline   = $data['airline_name'] ?? ($data['airline'] ?? 'Unknown Airline');

$src_code        = $data['src_code'] ?? null;
$dst_code        = $data['dst_code'] ?? null;
$source_name     = $data['src_name'] ?? $data['source'] ?? 'Unknown';
$destination_name= $data['dst_name'] ?? $data['destination'] ?? 'Unknown';

$d_raw = $data['d_time'] ?? $data['departure_time'] ?? null;
$a_raw = $data['a_time'] ?? $data['arrival_time'] ?? null;

$departure_full = fmt_full($d_raw);
$arrival_full   = fmt_full($a_raw);
$departure_time = fmt_time($d_raw);
$arrival_time   = fmt_time($a_raw);
$departure_date = fmt_date($d_raw);
$arrival_date   = fmt_date($a_raw);

$seat  = $data['seat_no'] ?? $data['seat'] ?? '—';
$class = $data['class'] ?? 'Economy';

$price_raw = $data['price'] ?? null;
$price     = ($price_raw !== null && $price_raw !== '') ? number_format((float)$price_raw, 2) : '0.00';

$booked_on_raw = $data['booking_date'] ?? null;
$booked_on     = $booked_on_raw ? fmt_full($booked_on_raw) : 'Unknown';

// Ground info
$gate     = $data['gate']        ?? ($data['g_gate'] ?? null);
$terminal = $data['terminal']    ?? ($data['term'] ?? null);
$belt     = $data['baggage_belt']?? ($data['belt'] ?? null);
if (empty($gate))     $gate = '—';
if (empty($terminal)) $terminal = '—';
if (empty($belt))     $belt = '—';

?>
<style>
.boarding-wrap{max-width:980px;margin:24px auto;padding:20px;font-family:Inter,system-ui,Arial}
.boarding{display:flex;gap:24px}
.bp-left{flex:1;border-radius:12px;padding:22px;background:#fff;box-shadow:0 6px 18px rgba(0,0,0,0.04)}
.bp-right{width:320px;border-radius:12px;padding:20px;background:#f6fbff}
.logo{width:72px;height:36px;background:#0b63c6;color:#fff;display:flex;align-items:center;justify-content:center;border-radius:6px;font-weight:700}
.code{font-size:1.8rem;font-weight:800;color:#0b3f8a}
.detail{background:#f1f8ff;border-radius:8px;padding:12px}
.small{font-size:.85rem;color:#374151}
.barcode{height:52px;background-image:repeating-linear-gradient(90deg,#111 0 2px,transparent 2px 6px);border-radius:4px;margin-top:8px}
.btn{padding:8px 12px;border-radius:8px;border:none;cursor:pointer}
.btn-primary{background:#0b63c6;color:#fff}
.btn-secondary{background:#eef6ff;color:#0b3f8a}
@media(max-width:900px){.boarding{flex-direction:column}.bp-right{width:100%}}
</style>

<div class="boarding-wrap printable">
  <div class="boarding">
    <div class="bp-left">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div class="logo">LA</div>
        <div style="font-weight:700;color:#0b63c6"><?= hsafe($airline) ?></div>
      </div>

      <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
        <div style="flex:1">
          <div class="code"><?= hsafe($src_code ?: $source_name) ?></div>
          <div class="small">From</div>
        </div>
        <div style="width:64px;text-align:center;color:#7c93c9;font-weight:700">→</div>
        <div style="flex:1;text-align:right">
          <div class="code"><?= hsafe($dst_code ?: $destination_name) ?></div>
          <div class="small">To</div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">
        <div class="detail"><div class="small">Passenger</div><div style="font-weight:700;margin-top:6px"><?= hsafe($passenger_name) ?></div></div>
        <div class="detail"><div class="small">Flight</div><div style="font-weight:700;margin-top:6px"><?= hsafe($flight_id) ?></div></div>
        <div class="detail"><div class="small">Seat</div><div style="font-weight:700;margin-top:6px"><?= hsafe($seat) ?></div></div>

        <div class="detail"><div class="small">Date</div><div style="font-weight:700;margin-top:6px"><?= hsafe($departure_date) ?></div></div>
        <div class="detail"><div class="small">Departure</div><div style="font-weight:700;margin-top:6px"><?= hsafe($departure_time) ?></div></div>
        <div class="detail"><div class="small">Arrival</div><div style="font-weight:700;margin-top:6px"><?= hsafe($arrival_time) ?></div></div>

        <div class="detail"><div class="small">Class</div><div style="font-weight:700;margin-top:6px"><?= hsafe($class) ?></div></div>
        <div class="detail"><div class="small">PNR</div><div style="font-weight:700;margin-top:6px"><?= hsafe($pnr) ?></div></div>
        <div class="detail"><div class="small">Price</div><div style="font-weight:700;margin-top:6px">₹<?= hsafe($price) ?></div></div>
      </div>

      <div style="margin-top:14px" class="small">
        <strong>Booked on:</strong> <?= hsafe($booked_on) ?> &nbsp; Passport: <?= hsafe($data['booked_passport'] ?? $passport) ?>
        <?php if ($reschedule_note): ?>
          <br><span style="color:#0b63c6;font-weight:600;">✓ <?= hsafe($reschedule_note) ?></span>
        <?php endif; ?>
      </div>

      <div style="margin-top:12px; display:flex; gap:10px;">
        <div style="flex:1" class="small">
          <strong>Gate</strong><br>
          <div style="font-weight:700; margin-top:4px;"><?= hsafe($gate) ?></div>
        </div>
        <div style="flex:1" class="small">
          <strong>Terminal</strong><br>
          <div style="font-weight:700; margin-top:4px;"><?= hsafe($terminal) ?></div>
        </div>
        <div style="flex:1" class="small">
          <strong>Belt</strong><br>
          <div style="font-weight:700; margin-top:4px;"><?= hsafe($belt) ?></div>
        </div>
      </div>

      <div class="barcode" aria-hidden="true"></div>
    </div>

    <div class="bp-right">
      <div>
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <div>
            <div style="font-size:.85rem;color:#6b7280">BOARDING PASS</div>
            <div style="font-weight:800;font-size:1.3rem;color:#0b3f8a"><?= hsafe($departure_time) ?></div>
            <div style="font-size:.75rem;color:#475569"><?= hsafe($departure_date) ?></div>
          </div>
          <div style="text-align:right">
            <div style="font-size:.85rem;color:#6b7280">Class</div>
            <div style="font-weight:700;font-size:1.05rem;color:#0b3f8a"><?= hsafe($class) ?></div>
          </div>
        </div>

        <hr style="margin:14px 0;border:none;border-top:1px dashed rgba(0,0,0,0.06)">

        <div style="font-size:.82rem;color:#6b7280">Passenger</div>
        <div style="font-weight:700;font-size:1.02rem;color:#111827"><?= hsafe($passenger_name) ?></div>

        <div style="margin-top:10px">
          <div style="font-size:.82rem;color:#6b7280">Route</div>
          <div style="font-weight:700;font-size:1.02rem;color:#111827"><?= hsafe($src_code ?: $source_name) ?> → <?= hsafe($dst_code ?: $destination_name) ?></div>
        </div>

        <div style="margin-top:10px; display:flex; gap:10px;">
          <div style="flex:1">
            <div style="font-size:.78rem;color:#6b7280">Gate</div>
            <div style="font-weight:700"><?= hsafe($gate) ?></div>
          </div>
          <div style="flex:1">
            <div style="font-size:.78rem;color:#6b7280">Terminal</div>
            <div style="font-weight:700"><?= hsafe($terminal) ?></div>
          </div>
        </div>

      </div>

      <div>
        <div class="barcode" style="height:64px"></div>
        <div style="margin-top:8px;display:flex;justify-content:space-between;align-items:center">
          <div style="font-size:.78rem;color:#6b7280">Ticket</div>
          <div style="font-weight:700"><?= hsafe($ticket_id) ?></div>
        </div>

        <div style="margin-top:8px;display:flex;justify-content:space-between;align-items:center">
          <div style="font-size:.78rem;color:#6b7280">PNR</div>
          <div style="font-weight:700"><?= hsafe($pnr) ?></div>
        </div>

        <div style="margin-top:12px;display:flex;gap:10px">
          <button class="btn btn-primary" onclick="window.print()">Print E-ticket</button>
          <a class="btn btn-secondary" href="/FLIGHT_FRONTEND/index.php">Back to Home</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
