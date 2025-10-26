<?php
// /FLIGHT_FRONTEND/e_ticket.php (with Gate / Terminal / Baggage Belt - latest row chosen)
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
safe_start_session();

if (empty($_SESSION['passport_no'])) {
    header('Location: /FLIGHT_FRONTEND/auth/login.php');
    exit;
}

require_once __DIR__ . '/includes/header.php';

// prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

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

/* ---------- detect flight table ---------- */
$flight_table = detect_flights_table($mysqli);

/* fragments used everywhere */
$pnr_cols = ['flight_code','flight_no','flight_number','code','flightid','flight_id'];
$price_cols = ['base_price','price','fare','amount','f_price'];

/* time fragments */
$d_time_frag = col_exists($mysqli, $flight_table, 'd_time') ? "f.d_time AS d_time"
             : (col_exists($mysqli, $flight_table, 'departure_time') ? "f.departure_time AS d_time" : "NULL AS d_time");
$a_time_frag = col_exists($mysqli, $flight_table, 'a_time') ? "f.a_time AS a_time"
             : (col_exists($mysqli, $flight_table, 'arrival_time') ? "f.arrival_time AS a_time" : "NULL AS a_time");

/* pnr / price fragments */
$pnr_frag = pick_aliased_col($mysqli, $flight_table, $pnr_cols, 'pnr_code', "NULL");
$price_frag = pick_aliased_col($mysqli, $flight_table, $price_cols, 'price', "0");

/* source / destination fragments (aliased pair) */
$use_src_id = col_exists($mysqli, $flight_table, 'source_id');
$use_dst_id = col_exists($mysqli, $flight_table, 'destination_id');

$src_join = '';
$dst_join = '';
$src_select = "NULL AS src_code, NULL AS src_name";
$dst_select = "NULL AS dst_code, NULL AS dst_name";

if ($use_src_id) {
    $src_join = " LEFT JOIN airport src ON f.source_id = src.airport_id ";
    $src_select = "src.airport_code AS src_code, src.airport_name AS src_name";
} elseif (col_exists($mysqli, $flight_table, 'source')) {
    $src_select = "NULL AS src_code, f.source AS src_name";
}

if ($use_dst_id) {
    $dst_join = " LEFT JOIN airport dst ON f.destination_id = dst.airport_id ";
    $dst_select = "dst.airport_code AS dst_code, dst.airport_name AS dst_name";
} elseif (col_exists($mysqli, $flight_table, 'destination')) {
    $dst_select = "NULL AS dst_code, f.destination AS dst_name";
}

/* airline fragments */
$airline_join = col_exists($mysqli, $flight_table, 'airline_id') ? " LEFT JOIN airline al ON f.airline_id = al.airline_id " : "";
$airline_select = col_exists($mysqli, $flight_table, 'airline_id') ? "al.airline_name AS airline_name" : "NULL AS airline_name";

/* ---------- flight_ground_info: join most recent row ---------- */
$ground_select_fragment = "NULL AS gate, NULL AS terminal, NULL AS baggage_belt";
$ground_join_fragment = "";
if ($mysqli->query("SHOW TABLES LIKE 'flight_ground_info'")->num_rows > 0) {
    // check columns in ground table
    $gcols = [];
    $gres = $mysqli->query("SHOW COLUMNS FROM flight_ground_info");
    if ($gres) while ($c = $gres->fetch_assoc()) $gcols[] = $c['Field'];

    $col_gate = in_array('gate', $gcols) ? 'gate' : (in_array('g_gate', $gcols) ? 'g_gate' : null);
    $col_terminal = in_array('terminal', $gcols) ? 'terminal' : (in_array('term', $gcols) ? 'term' : null);
    $col_belt = in_array('baggage_belt', $gcols) ? 'baggage_belt' : (in_array('belt', $gcols) ? 'belt' : null);

    // guard: we expect flight_ground_info to have flight_id or flight column to join on
    $join_key = in_array('flight_id', $gcols) ? 'flight_id' : (in_array('flight', $gcols) ? 'flight' : null);

    if ($join_key && ($col_gate || $col_terminal || $col_belt)) {
        // Choose latest row per flight:
        // If updated_at exists, join by the row having MAX(updated_at); otherwise fallback to MAX(info_id).
        $has_updated_at = in_array('updated_at', $gcols);

        if ($has_updated_at) {
            // use subquery to pick latest updated_at per flight
            $ground_join_fragment = "
              LEFT JOIN flight_ground_info g ON f.flight_id = g.flight_id
              AND g.updated_at = (
                SELECT MAX(g2.updated_at) FROM flight_ground_info g2 WHERE g2.flight_id = f.flight_id
              )
            ";
        } else {
            // fallback: pick row with highest info_id per flight
            $ground_join_fragment = "
              LEFT JOIN flight_ground_info g ON f.flight_id = g.flight_id
              AND g.info_id = (
                SELECT MAX(g2.info_id) FROM flight_ground_info g2 WHERE g2.flight_id = f.flight_id
              )
            ";
        }

        $parts = [];
        $parts[] = $col_gate ? "g.`{$col_gate}` AS gate" : "NULL AS gate";
        $parts[] = $col_terminal ? "g.`{$col_terminal}` AS terminal" : "NULL AS terminal";
        $parts[] = $col_belt ? "g.`{$col_belt}` AS baggage_belt" : "NULL AS baggage_belt";
        $ground_select_fragment = implode(', ', $parts);
    }
}

/* ---------- Lookups (using dynamic fragments) ---------- */

// 1) booking lookup by booking_id
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
    } else {
        error_log("e_ticket: booking-by-id prepare failed: " . $mysqli->error);
    }
}

// 2) ticket lookup by ticket_no
if (!$data && $ticket_no) {
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

// 3) latest ticket for passport
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
    } else {
        error_log("e_ticket: ticket-latest prepare failed: " . $mysqli->error);
    }
}

// 4) fallback: latest booking for passport
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
    } else {
        error_log("e_ticket: booking-latest prepare failed: " . $mysqli->error);
    }
}

if (!$data) {
    echo "<div class='alert alert-warning'>Ticket not found.</div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

/* ---------- Enrich flight-level info if missing (also get ground info) ---------- */
$flight_id = $data['flight_id'] ?? null;
if ($flight_id) {
    $dep_select = col_exists($mysqli, $flight_table, 'departure_time') ? "f.departure_time AS departure_time"
                 : (col_exists($mysqli, $flight_table, 'd_time') ? "f.d_time AS departure_time" : "NULL AS departure_time");
    $arr_select = col_exists($mysqli, $flight_table, 'arrival_time') ? "f.arrival_time AS arrival_time"
                 : (col_exists($mysqli, $flight_table, 'a_time') ? "f.a_time AS arrival_time" : "NULL AS arrival_time");

    $sql = "
      SELECT f.flight_id, {$pnr_frag}, {$dep_select}, {$arr_select}, {$price_frag}, {$airline_select},
             {$src_select}, {$dst_select},
             {$ground_select_fragment}
      FROM {$flight_table} f
      {$src_join} {$dst_join} {$airline_join}
      {$ground_join_fragment}
      WHERE f.flight_id = ? LIMIT 1
    ";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $flight_id);
        $stmt->execute();
        $frow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($frow) {
            if (empty($data['src_name']) && !empty($frow['src_name'])) $data['src_name'] = $frow['src_name'];
            if (empty($data['dst_name']) && !empty($frow['dst_name'])) $data['dst_name'] = $frow['dst_name'];
            if (empty($data['src_code']) && !empty($frow['src_code'])) $data['src_code'] = $frow['src_code'];
            if (empty($data['dst_code']) && !empty($frow['dst_code'])) $data['dst_code'] = $frow['dst_code'];
            if (empty($data['departure_time']) && !empty($frow['departure_time'])) $data['departure_time'] = $frow['departure_time'];
            if (empty($data['arrival_time']) && !empty($frow['arrival_time'])) $data['arrival_time'] = $frow['arrival_time'];
            if ((empty($data['price']) || $data['price'] === '0') && isset($frow['price'])) $data['price'] = $frow['price'];
            if (empty($data['airline_name']) && !empty($frow['airline_name'])) $data['airline_name'] = $frow['airline_name'];
            if (empty($data['pnr_code']) && !empty($frow['pnr_code'])) $data['pnr_code'] = $frow['pnr_code'];
            // ground info (if present)
            if (empty($data['gate']) && isset($frow['gate'])) $data['gate'] = $frow['gate'];
            if (empty($data['terminal']) && isset($frow['terminal'])) $data['terminal'] = $frow['terminal'];
            if (empty($data['baggage_belt']) && isset($frow['baggage_belt'])) $data['baggage_belt'] = $frow['baggage_belt'];
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

/* ---------- formatting helpers ---------- */
function safe($v, $fallback = '—') { return htmlspecialchars($v ?? $fallback); }
function fmt_full($raw) { if (!$raw) return 'Unknown'; $ts = strtotime($raw); return $ts ? date('d M Y, h:i A', $ts) : htmlspecialchars($raw); }
function fmt_time($raw) { if (!$raw) return '—'; $ts = strtotime($raw); return $ts ? date('h:i A', $ts) : htmlspecialchars($raw); }
function fmt_date($raw) { if (!$raw) return '—'; $ts = strtotime($raw); return $ts ? date('d M Y', $ts) : htmlspecialchars($raw); }

/* ---------- Resolve fields ---------- */
$ticket_id = $data['ticket_no'] ?? $data['booking_id'] ?? ($data['pnr_code'] ?? null) ?? '—';
$pnr = $data['pnr_code'] ?? $data['pnr'] ?? $ticket_id;
$flight_id = $data['flight_id'] ?? ($data['flight_code'] ?? null);
$airline = $data['airline_name'] ?? ($data['airline'] ?? 'Unknown Airline');
$src_code = $data['src_code'] ?? null;
$dst_code = $data['dst_code'] ?? null;
$source_name = $data['src_name'] ?? $data['source'] ?? 'Unknown';
$destination_name = $data['dst_name'] ?? $data['destination'] ?? 'Unknown';

$d_raw = $data['d_time'] ?? $data['departure_time'] ?? null;
$a_raw = $data['a_time'] ?? $data['arrival_time'] ?? null;
$departure_full = fmt_full($d_raw);
$arrival_full = fmt_full($a_raw);
$departure_time = fmt_time($d_raw);
$arrival_time = fmt_time($a_raw);
$departure_date = fmt_date($d_raw);
$arrival_date = fmt_date($a_raw);

$seat = $data['seat_no'] ?? $data['seat'] ?? '—';
$class = $data['class'] ?? 'Economy';

$price_raw = $data['price'] ?? null;
$price = ($price_raw !== null && $price_raw !== '') ? number_format(floatval($price_raw), 2) : '0.00';

$booked_on_raw = $data['booking_date'] ?? null;
$booked_on = $booked_on_raw ? fmt_full($booked_on_raw) : 'Unknown';

$passport_display = $data['booked_passport'] ?? $passport;

/* ---------- Ground info (final fallbacks) ---------- */
$gate = $data['gate'] ?? ($data['g_gate'] ?? null);
$terminal = $data['terminal'] ?? ($data['term'] ?? null);
$belt = $data['baggage_belt'] ?? ($data['belt'] ?? null);

// final very-small fallback: if still empty, show dash
if (empty($gate)) $gate = '—';
if (empty($terminal)) $terminal = '—';
if (empty($belt)) $belt = '—';

/* ---------- Output boarding-pass UI ---------- */
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
        <div style="font-weight:700;color:#0b63c6"><?= safe($airline) ?></div>
      </div>

      <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
        <div style="flex:1">
          <div class="code"><?= safe($src_code ?: $source_name) ?></div>
          <div class="small">From</div>
        </div>
        <div style="width:64px;text-align:center;color:#7c93c9;font-weight:700">→</div>
        <div style="flex:1;text-align:right">
          <div class="code"><?= safe($dst_code ?: $destination_name) ?></div>
          <div class="small">To</div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">
        <div class="detail"><div class="small">Passenger</div><div style="font-weight:700;margin-top:6px"><?= safe($passenger_name) ?></div></div>
        <div class="detail"><div class="small">Flight</div><div style="font-weight:700;margin-top:6px"><?= safe($flight_id) ?></div></div>
        <div class="detail"><div class="small">Seat</div><div style="font-weight:700;margin-top:6px"><?= safe($seat) ?></div></div>

        <div class="detail"><div class="small">Date</div><div style="font-weight:700;margin-top:6px"><?= safe($departure_date) ?></div></div>
        <div class="detail"><div class="small">Departure</div><div style="font-weight:700;margin-top:6px"><?= safe($departure_time) ?></div></div>
        <div class="detail"><div class="small">Arrival</div><div style="font-weight:700;margin-top:6px"><?= safe($arrival_time) ?></div></div>

        <div class="detail"><div class="small">Class</div><div style="font-weight:700;margin-top:6px"><?= safe($class) ?></div></div>
        <div class="detail"><div class="small">PNR</div><div style="font-weight:700;margin-top:6px"><?= safe($pnr) ?></div></div>
        <div class="detail"><div class="small">Price</div><div style="font-weight:700;margin-top:6px">₹<?= safe($price) ?></div></div>
      </div>

      <div style="margin-top:14px" class="small">
        <strong>Booked on:</strong> <?= safe($booked_on) ?> &nbsp; Passport: <?= safe($passport_display) ?>
      </div>

      <div style="margin-top:12px; display:flex; gap:10px;">
        <div style="flex:1" class="small">
          <strong>Gate</strong><br>
          <div style="font-weight:700; margin-top:4px;"><?= safe($gate) ?></div>
        </div>
        <div style="flex:1" class="small">
          <strong>Terminal</strong><br>
          <div style="font-weight:700; margin-top:4px;"><?= safe($terminal) ?></div>
        </div>
        <div style="flex:1" class="small">
          <strong>Belt</strong><br>
          <div style="font-weight:700; margin-top:4px;"><?= safe($belt) ?></div>
        </div>
      </div>

      <div class="barcode" aria-hidden="true"></div>
    </div>

    <div class="bp-right">
      <div>
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <div>
            <div style="font-size:.85rem;color:#6b7280">BOARDING PASS</div>
            <div style="font-weight:800;font-size:1.3rem;color:#0b3f8a"><?= safe($departure_time) ?></div>
            <div style="font-size:.75rem;color:#475569"><?= safe($departure_date) ?></div>
          </div>
          <div style="text-align:right">
            <div style="font-size:.85rem;color:#6b7280">Class</div>
            <div style="font-weight:700;font-size:1.05rem;color:#0b3f8a"><?= safe($class) ?></div>
          </div>
        </div>

        <hr style="margin:14px 0;border:none;border-top:1px dashed rgba(0,0,0,0.06)">

        <div style="font-size:.82rem;color:#6b7280">Passenger</div>
        <div style="font-weight:700;font-size:1.02rem;color:#111827"><?= safe($passenger_name) ?></div>

        <div style="margin-top:10px">
          <div style="font-size:.82rem;color:#6b7280">Route</div>
          <div style="font-weight:700;font-size:1.02rem;color:#111827"><?= safe($src_code ?: $source_name) ?> → <?= safe($dst_code ?: $destination_name) ?></div>
        </div>

        <div style="margin-top:10px; display:flex; gap:10px;">
          <div style="flex:1">
            <div style="font-size:.78rem;color:#6b7280">Gate</div>
            <div style="font-weight:700"><?= safe($gate) ?></div>
          </div>
          <div style="flex:1">
            <div style="font-size:.78rem;color:#6b7280">Terminal</div>
            <div style="font-weight:700"><?= safe($terminal) ?></div>
          </div>
        </div>

      </div>

      <div>
        <div class="barcode" style="height:64px"></div>
        <div style="margin-top:8px;display:flex;justify-content:space-between;align-items:center">
          <div style="font-size:.78rem;color:#6b7280">Ticket</div>
          <div style="font-weight:700"><?= safe($ticket_id) ?></div>
        </div>

        <div style="margin-top:8px;display:flex;justify-content:space-between;align-items:center">
          <div style="font-size:.78rem;color:#6b7280">PNR</div>
          <div style="font-weight:700"><?= safe($pnr) ?></div>
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
