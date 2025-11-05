<?php
// todays_flights.php — Show only today's flights, based on flight_board.php logic ✈️

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
safe_start_session();
require_once __DIR__ . '/../includes/header.php';

/* Helper: detect table + columns dynamically */
function detect_flight_table($mysqli) {
    if ($mysqli->query("SHOW TABLES LIKE 'flight'")->num_rows > 0) return 'flight';
    if ($mysqli->query("SHOW TABLES LIKE 'flights'")->num_rows > 0) return 'flights';
    return 'flight';
}
function col_exists($mysqli, $table, $col) {
    if (! $mysqli) return false;
    $t = $mysqli->real_escape_string($table);
    $c = $mysqli->real_escape_string($col);
    $res = $mysqli->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return ($res && $res->num_rows > 0);
}

/* Detect flight table and columns */
$flight_table = detect_flight_table($mysqli);

$col_fid = col_exists($mysqli, $flight_table, 'flight_id') ? 'flight_id' :
          (col_exists($mysqli, $flight_table, 'id') ? 'id' : 'flight_id');
$col_code = col_exists($mysqli, $flight_table, 'flight_code') ? 'flight_code' :
          (col_exists($mysqli, $flight_table, 'flight_no') ? 'flight_no' :
          (col_exists($mysqli, $flight_table, 'flight_number') ? 'flight_number' : $col_fid));

$col_src = col_exists($mysqli, $flight_table, 'source_id') ? 'source_id' :
          (col_exists($mysqli, $flight_table, 'src') ? 'src' :
          (col_exists($mysqli, $flight_table, 'source') ? 'source' : null));
$col_dst = col_exists($mysqli, $flight_table, 'destination_id') ? 'destination_id' :
          (col_exists($mysqli, $flight_table, 'dst') ? 'dst' :
          (col_exists($mysqli, $flight_table, 'destination') ? 'destination' : null));

$col_depart = col_exists($mysqli, $flight_table, 'departure_time') ? 'departure_time' :
             (col_exists($mysqli, $flight_table, 'd_time') ? 'd_time' : null);
$col_arrive = col_exists($mysqli, $flight_table, 'arrival_time') ? 'arrival_time' :
             (col_exists($mysqli, $flight_table, 'a_time') ? 'a_time' : null);
$col_status = col_exists($mysqli, $flight_table, 'status') ? 'status' :
             (col_exists($mysqli, $flight_table, 'flight_status') ? 'flight_status' : null);

$has_airline = col_exists($mysqli, $flight_table, 'airline_id');

/* Build SELECT query (filter by today's date) */
$sel = [];
$sel[] = "f.`$col_fid` AS flight_id";
$sel[] = "f.`$col_code` AS flight_code";
$sel[] = $col_depart ? "f.`$col_depart` AS departure_time" : "NULL AS departure_time";
$sel[] = $col_arrive ? "f.`$col_arrive` AS arrival_time" : "NULL AS arrival_time";
$sel[] = $col_status ? "f.`$col_status` AS status" : "NULL AS status";

if ($col_src === 'source_id') {
    $sel[] = "src.airport_code AS src_code";
    $sel[] = "src.airport_name AS src_name";
    $join_src = "LEFT JOIN airport src ON f.source_id = src.airport_id";
} else {
    $sel[] = "f.`$col_src` AS src_code";
    $sel[] = "NULL AS src_name";
    $join_src = "";
}

if ($col_dst === 'destination_id') {
    $sel[] = "dst.airport_code AS dst_code";
    $sel[] = "dst.airport_name AS dst_name";
    $join_dst = "LEFT JOIN airport dst ON f.destination_id = dst.airport_id";
} else {
    $sel[] = "f.`$col_dst` AS dst_code";
    $sel[] = "NULL AS dst_name";
    $join_dst = "";
}

if ($has_airline) {
    $sel[] = "al.airline_name AS airline_name";
    $join_airline = "LEFT JOIN airline al ON f.airline_id = al.airline_id";
} else {
    $sel[] = "NULL AS airline_name";
    $join_airline = "";
}

/* Only today's flights */
$where_clause = "";
if ($col_depart) {
    $where_clause = "WHERE DATE(f.`$col_depart`) = CURDATE()";
}

/* Final query */
$sql = "
SELECT " . implode(", ", $sel) . "
FROM `$flight_table` f
$join_src
$join_dst
$join_airline
$where_clause
ORDER BY f.`$col_depart` ASC
LIMIT 200
";

$res = $mysqli->query($sql);
if (!$res) {
    echo '<div class="container my-4"><div class="alert alert-danger">DB Error: ' . htmlspecialchars($mysqli->error) . '</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* Display */
?>
<div class="container my-4">
  <h2 class="mb-3">✈️ Flights Departing Today (<?= date('l, F jS Y') ?>)</h2>
  <div class="table-responsive">
    <table class="table align-middle table-bordered table-hover">
      <thead class="table-primary">
        <tr>
          <th>Flight</th>
          <th>Airline</th>
          <th>From</th>
          <th>To</th>
          <th>Departure</th>
          <th>Arrival</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($res->num_rows > 0): ?>
          <?php while ($r = $res->fetch_assoc()): ?>
            <tr>
              <td><strong><?= htmlspecialchars($r['flight_code'] ?? $r['flight_id']) ?></strong></td>
              <td><?= htmlspecialchars($r['airline_name'] ?? '—') ?></td>
              <td><?= htmlspecialchars($r['src_code'] ?? '—') ?></td>
              <td><?= htmlspecialchars($r['dst_code'] ?? '—') ?></td>
              <td><?= htmlspecialchars(!empty($r['departure_time']) ? date('H:i', strtotime($r['departure_time'])) : '—') ?></td>
              <td><?= htmlspecialchars(!empty($r['arrival_time']) ? date('H:i', strtotime($r['arrival_time'])) : '—') ?></td>
              <td><?= htmlspecialchars($r['status'] ?? 'Scheduled') ?></td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr><td colspan="7" class="text-center text-muted">No flights scheduled for today 💤</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
