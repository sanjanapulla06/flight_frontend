<?php
// admin/admin_view_ticket.php
// Admin-only viewer — schema-defensive version
require_once __DIR__ . '/includes/auth_check.php'; // ensures admin
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$booking_id = trim((string)($_GET['booking_id'] ?? ''));
if ($booking_id === '') {
    $_SESSION['admin_flash'] = ['type'=>'danger','msg'=>'No booking_id provided'];
    header('Location: /FLIGHT_FRONTEND/admin/admin_dashboard.php');
    exit;
}

/* helpers */
function detect_table($mysqli, array $names, $fallback) {
    foreach ($names as $n) {
        $r = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($n) . "'");
        if ($r && $r->num_rows > 0) return $n;
    }
    return $fallback;
}
function get_columns($mysqli, $table) {
    $cols = [];
    $r = $mysqli->query("SHOW COLUMNS FROM `" . $mysqli->real_escape_string($table) . "`");
    if ($r) while ($c = $r->fetch_assoc()) $cols[] = $c['Field'];
    return $cols;
}

/* detect tables */
$booking_table = detect_table($mysqli, ['bookings'], 'bookings');
$ticket_table  = detect_table($mysqli, ['ticket','tickets'], 'ticket');
$flight_table  = detect_table($mysqli, ['flight','flights'], 'flight');
$passenger_tbl = detect_table($mysqli, ['passenger','passengers'], 'passenger');

/* detect ticket columns and decide JOIN strategy */
$ticket_cols = [];
if ($mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($ticket_table) . "'")->num_rows > 0) {
    $ticket_cols = get_columns($mysqli, $ticket_table);
}

$ticket_has_booking_id = in_array('booking_id', $ticket_cols);
$ticket_has_flight_id  = in_array('flight_id', $ticket_cols) || in_array('flight', $ticket_cols);
$ticket_has_ticket_no  = in_array('ticket_no', $ticket_cols) || in_array('pnr', $ticket_cols);
$ticket_has_price      = in_array('price', $ticket_cols) || in_array('fare', $ticket_cols) || in_array('amount', $ticket_cols);

/* Build SQL fragments defensively */
$ticket_join = '';
$ticket_select = 'NULL AS ticket_no, NULL AS ticket_price';
if ($ticket_has_booking_id) {
    $ticket_join = " LEFT JOIN `{$ticket_table}` t ON t.booking_id = b.booking_id ";
    $tn_col = $ticket_has_ticket_no ? (in_array('ticket_no', $ticket_cols) ? 'ticket_no' : (in_array('pnr', $ticket_cols) ? 'pnr' : null)) : null;
    $price_col = $ticket_has_price ? (in_array('price',$ticket_cols)?'price':(in_array('fare',$ticket_cols)?'fare':'amount')) : null;
    $parts = [];
    $parts[] = $tn_col ? "t.`{$tn_col}` AS ticket_no" : "NULL AS ticket_no";
    $parts[] = $price_col ? "t.`{$price_col}` AS ticket_price" : "NULL AS ticket_price";
    $ticket_select = implode(', ', $parts);
} elseif ($ticket_has_flight_id) {
    // join ticket by flight_id if booking_id missing
    // use flight_id column name from ticket if different
    $ticket_fcol = in_array('flight_id', $ticket_cols) ? 'flight_id' : 'flight';
    $ticket_join = " LEFT JOIN `{$ticket_table}` t ON t.`{$ticket_fcol}` = b.flight_id ";
    $tn_col = $ticket_has_ticket_no ? (in_array('ticket_no', $ticket_cols) ? 'ticket_no' : (in_array('pnr', $ticket_cols) ? 'pnr' : null)) : null;
    $price_col = $ticket_has_price ? (in_array('price',$ticket_cols)?'price':(in_array('fare',$ticket_cols)?'fare':'amount')) : null;
    $parts = [];
    $parts[] = $tn_col ? "t.`{$tn_col}` AS ticket_no" : "NULL AS ticket_no";
    $parts[] = $price_col ? "t.`{$price_col}` AS ticket_price" : "NULL AS ticket_price";
    $ticket_select = implode(', ', $parts);
} else {
    // no ticket join possible; keep ticket_select as nulls
    $ticket_join = '';
    $ticket_select = "NULL AS ticket_no, NULL AS ticket_price";
}

/* Build main SQL dynamically */
$sql = "
  SELECT b.booking_id, b.flight_id, b.passport_no, b.seat_no, b.class, b.booking_date, b.status AS booking_status,
         {$ticket_select},
         p.name AS passenger_name, p.email AS passenger_email, p.phone AS passenger_phone,
         f.flight_id AS flight_code,
         COALESCE(f.departure_time, f.d_time, '') AS departure_time,
         COALESCE(f.arrival_time, f.a_time, '') AS arrival_time,
         COALESCE(src.airport_code,'') AS src_code, COALESCE(dst.airport_code,'') AS dst_code,
         COALESCE(al.airline_name,'') AS airline_name
  FROM `{$booking_table}` b
  {$ticket_join}
  LEFT JOIN `{$passenger_tbl}` p ON p.passport_no = b.passport_no
  LEFT JOIN `{$flight_table}` f ON f.flight_id = b.flight_id
  LEFT JOIN airport src ON f.source_id = src.airport_id
  LEFT JOIN airport dst ON f.destination_id = dst.airport_id
  LEFT JOIN airline al ON f.airline_id = al.airline_id
  WHERE b.booking_id = ?
  LIMIT 1
";

/* Prepare & execute safely */
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    $_SESSION['admin_flash'] = ['type'=>'danger','msg'=>'DB prepare failed: '.$mysqli->error];
    header('Location: /FLIGHT_FRONTEND/admin/admin_dashboard.php');
    exit;
}
$stmt->bind_param('s', $booking_id);
if (!$stmt->execute()) {
    $_SESSION['admin_flash'] = ['type'=>'danger','msg'=>'DB execute failed: '.$stmt->error];
    $stmt->close();
    header('Location: /FLIGHT_FRONTEND/admin/admin_dashboard.php');
    exit;
}
$ticket = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ticket) {
    $_SESSION['admin_flash'] = ['type'=>'danger','msg'=>"Booking not found: {$booking_id}"];
    header('Location: /FLIGHT_FRONTEND/admin/admin_dashboard.php');
    exit;
}

/* render */
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container my-4">
  <h3>Admin Ticket Viewer — <?= htmlspecialchars($ticket['booking_id']) ?></h3>

  <div class="card p-3 mb-3">
    <div class="row">
      <div class="col-md-6">
        <h5><?= htmlspecialchars($ticket['airline_name'] ?: '—') ?> <small class="text-muted"><?= htmlspecialchars($ticket['flight_code']) ?></small></h5>
        <p class="mb-1"><strong>Route:</strong> <?= htmlspecialchars($ticket['src_code']) ?> → <?= htmlspecialchars($ticket['dst_code']) ?></p>
        <p class="mb-1"><strong>Departure:</strong> <?= htmlspecialchars($ticket['departure_time']) ?></p>
        <p class="mb-1"><strong>Arrival:</strong> <?= htmlspecialchars($ticket['arrival_time']) ?></p>
      </div>
      <div class="col-md-6">
        <p class="mb-1"><strong>Passenger:</strong> <?= htmlspecialchars($ticket['passenger_name'] ?? $ticket['passport_no']) ?></p>
        <p class="mb-1"><strong>Passport:</strong> <?= htmlspecialchars($ticket['passport_no']) ?></p>
        <p class="mb-1"><strong>Phone / Email:</strong> <?= htmlspecialchars($ticket['passenger_phone'] ?? '—') ?> / <?= htmlspecialchars($ticket['passenger_email'] ?? '—') ?></p>
        <p class="mb-1"><strong>Seat:</strong> <?= htmlspecialchars($ticket['seat_no'] ?? '—') ?> <strong>Class:</strong> <?= htmlspecialchars($ticket['class'] ?? '—') ?></p>
        <p class="mb-1"><strong>Price:</strong> <?= ($ticket['ticket_price'] !== null && $ticket['ticket_price'] !== '') ? '₹' . number_format((float)$ticket['ticket_price'],2) : '—' ?></p>
        <p class="mb-1"><strong>Ticket No:</strong> <?= htmlspecialchars($ticket['ticket_no'] ?? '—') ?></p>
      </div>
    </div>
  </div>

  <div class="mb-3">
    <a class="btn btn-outline-secondary" href="/FLIGHT_FRONTEND/admin/admin_dashboard.php">Back to Admin</a>
    <a class="btn btn-primary" href="/FLIGHT_FRONTEND/e_ticket.php?booking_id=<?= urlencode($ticket['booking_id']) ?>" target="_blank">Open Passenger View</a>
  </div>

  <div class="card p-3">
    <h5>Debug: raw DB row</h5>
    <pre><?= htmlspecialchars(print_r($ticket, true)) ?></pre>
    <h6>Detected ticket table and columns</h6>
    <pre>
ticket_table: <?= htmlspecialchars($ticket_table) . "\n" ?>
ticket columns: <?= htmlspecialchars(implode(', ', $ticket_cols) ?: '(none)') . "\n" ?>
join used: <?= htmlspecialchars($ticket_join ?: '(no ticket join)') . "\n" ?>
    </pre>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
