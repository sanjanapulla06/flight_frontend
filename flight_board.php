<?php
// flight_board.php - Live Flight Board with friendly airport labels (dynamic, defensive, latest ground-info)
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
safe_start_session();
require_once __DIR__ . '/includes/header.php';

/** Helpers **/
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

/* ---------- detect tables/columns ---------- */
$flight_table = detect_flight_table($mysqli);

/* common flight id column */
$col_fid = col_exists($mysqli, $flight_table, 'flight_id') ? 'flight_id' :
          (col_exists($mysqli, $flight_table, 'id') ? 'id' : 'flight_id');

/* possible flight code column */
$col_code = col_exists($mysqli, $flight_table, 'flight_code') ? 'flight_code' :
            (col_exists($mysqli, $flight_table, 'flight_no') ? 'flight_no' :
            (col_exists($mysqli, $flight_table, 'flight_number') ? 'flight_number' : $col_fid));

/* source/destination detection */
$col_src = col_exists($mysqli, $flight_table, 'source_id') ? 'source_id' :
           (col_exists($mysqli, $flight_table, 'src') ? 'src' :
           (col_exists($mysqli, $flight_table, 'source') ? 'source' : null));

$col_dst = col_exists($mysqli, $flight_table, 'destination_id') ? 'destination_id' :
           (col_exists($mysqli, $flight_table, 'dst') ? 'dst' :
           (col_exists($mysqli, $flight_table, 'destination') ? 'destination' : null));

/* times & price */
$col_depart = col_exists($mysqli, $flight_table, 'departure_time') ? 'departure_time' :
             (col_exists($mysqli, $flight_table, 'd_time') ? 'd_time' : null);

$col_arrive = col_exists($mysqli, $flight_table, 'arrival_time') ? 'arrival_time' :
             (col_exists($mysqli, $flight_table, 'a_time') ? 'a_time' : null);

$col_price = col_exists($mysqli, $flight_table, 'price') ? 'price' :
           (col_exists($mysqli, $flight_table, 'base_price') ? 'base_price' : null);

/* airline */
$has_airline = col_exists($mysqli, $flight_table, 'airline_id');

/* status */
$flight_status_col = col_exists($mysqli, $flight_table, 'status') ? 'status' :
                     (col_exists($mysqli, $flight_table, 'flight_status') ? 'flight_status' : null);

/* ---------- build select fragments ---------- */
$sel_parts = [];
$sel_parts[] = "f.`" . $mysqli->real_escape_string($col_fid) . "` AS flight_id";
$sel_parts[] = "f.`" . $mysqli->real_escape_string($col_code) . "` AS flight_code";

/* source */
$join_src = '';
$join_city_src = '';
if ($col_src === 'source_id') {
    $sel_parts[] = "src.airport_code AS src_code";
    $sel_parts[] = "src.airport_name AS src_airport_name";
    $sel_parts[] = "src.city_id AS src_city_id";
    $join_src = " LEFT JOIN airport src ON f.source_id = src.airport_id ";
    if ($mysqli->query("SHOW TABLES LIKE 'city'")->num_rows > 0) {
        $sel_parts[] = "sc.city_name AS src_city_name";
        $join_city_src = " LEFT JOIN city sc ON src.city_id = sc.city_id ";
    } else {
        $sel_parts[] = "NULL AS src_city_name";
    }
} elseif ($col_src !== null) {
    $sel_parts[] = "f.`" . $mysqli->real_escape_string($col_src) . "` AS src_code";
    $sel_parts[] = "NULL AS src_airport_name";
    $sel_parts[] = "NULL AS src_city_name";
} else {
    $sel_parts[] = "NULL AS src_code";
    $sel_parts[] = "NULL AS src_airport_name";
    $sel_parts[] = "NULL AS src_city_name";
}

/* destination */
$join_dst = '';
$join_city_dst = '';
if ($col_dst === 'destination_id') {
    $sel_parts[] = "dst.airport_code AS dst_code";
    $sel_parts[] = "dst.airport_name AS dst_airport_name";
    $sel_parts[] = "dst.city_id AS dst_city_id";
    $join_dst = " LEFT JOIN airport dst ON f.destination_id = dst.airport_id ";
    if ($mysqli->query("SHOW TABLES LIKE 'city'")->num_rows > 0) {
        $sel_parts[] = "dc.city_name AS dst_city_name";
        $join_city_dst = " LEFT JOIN city dc ON dst.city_id = dc.city_id ";
    } else {
        $sel_parts[] = "NULL AS dst_city_name";
    }
} elseif ($col_dst !== null) {
    $sel_parts[] = "f.`" . $mysqli->real_escape_string($col_dst) . "` AS dst_code";
    $sel_parts[] = "NULL AS dst_airport_name";
    $sel_parts[] = "NULL AS dst_city_name";
} else {
    $sel_parts[] = "NULL AS dst_code";
    $sel_parts[] = "NULL AS dst_airport_name";
    $sel_parts[] = "NULL AS dst_city_name";
}

/* times and price */
$sel_parts[] = ($col_depart ? "f.`{$col_depart}` AS departure_time" : "NULL AS departure_time");
$sel_parts[] = ($col_arrive ? "f.`{$col_arrive}` AS arrival_time" : "NULL AS arrival_time");
$sel_parts[] = ($col_price ? "COALESCE(f.`{$col_price}`,0) AS price" : "0 AS price");

/* airline */
if ($has_airline) {
    $sel_parts[] = "al.airline_name AS airline_name";
    $join_airline = " LEFT JOIN airline al ON f.airline_id = al.airline_id ";
} else {
    $sel_parts[] = "NULL AS airline_name";
    $join_airline = "";
}

/* status */
if ($flight_status_col) {
    $sel_parts[] = "f.`" . $mysqli->real_escape_string($flight_status_col) . "` AS status";
} else {
    $sel_parts[] = "NULL AS status";
}

$select_sql = implode(",\n       ", $sel_parts);

/* ---------- ground-info: ensure latest row per flight ---------- */
/*
 Strategy:
  - If table flight_ground_info exists and has updated_at, pick row where updated_at = max(updated_at)
  - else pick row with max(info_id)
  - Use correlated subquery in JOIN condition to ensure one row per flight in final result
*/
$join_ground = "";
$ground_select = "NULL AS terminal, NULL AS gate, NULL AS belt";
$has_ground = ($mysqli->query("SHOW TABLES LIKE 'flight_ground_info'")->num_rows > 0);
if ($has_ground) {
    $gcols = [];
    $gres = $mysqli->query("SHOW COLUMNS FROM flight_ground_info");
    if ($gres) while ($c = $gres->fetch_assoc()) $gcols[] = $c['Field'];

    $g_has_updated_at = in_array('updated_at', $gcols);
    $g_col_infoid = in_array('info_id', $gcols) ? 'info_id' : null;
    $g_col_flightid = in_array('flight_id', $gcols) ? 'flight_id' : (in_array('flight', $gcols) ? 'flight' : null);
    $g_col_gate = in_array('gate', $gcols) ? 'gate' : (in_array('g_gate', $gcols) ? 'g_gate' : null);
    $g_col_term = in_array('terminal', $gcols) ? 'terminal' : (in_array('term', $gcols) ? 'term' : null);
    $g_col_belt = in_array('baggage_belt', $gcols) ? 'baggage_belt' : (in_array('belt', $gcols) ? 'belt' : null);

    if ($g_col_flightid && ($g_col_gate || $g_col_term || $g_col_belt)) {
        if ($g_has_updated_at) {
            // join on latest updated_at per flight
            $join_ground = "
              LEFT JOIN flight_ground_info g
                ON f.`{$col_fid}` = g.`{$g_col_flightid}`
               AND g.updated_at = (
                 SELECT MAX(g2.updated_at) FROM flight_ground_info g2 WHERE g2.`{$g_col_flightid}` = f.`{$col_fid}`
               )
            ";
        } elseif ($g_col_infoid) {
            // fallback: join on highest info_id per flight
            $join_ground = "
              LEFT JOIN flight_ground_info g
                ON f.`{$col_fid}` = g.`{$g_col_flightid}`
               AND g.`{$g_col_infoid}` = (
                 SELECT MAX(g2.`{$g_col_infoid}`) FROM flight_ground_info g2 WHERE g2.`{$g_col_flightid}` = f.`{$col_fid}`
               )
            ";
        } else {
            // last resort: simple LEFT JOIN (may return duplicates if multiple rows)
            $join_ground = " LEFT JOIN flight_ground_info g ON f.`{$col_fid}` = g.`{$g_col_flightid}` ";
        }

        $parts = [];
        $parts[] = $g_col_term ? "g.`{$g_col_term}` AS terminal" : "NULL AS terminal";
        $parts[] = $g_col_gate ? "g.`{$g_col_gate}` AS gate" : "NULL AS gate";
        $parts[] = $g_col_belt ? "g.`{$g_col_belt}` AS belt" : "NULL AS belt";
        $ground_select = implode(', ', $parts);
    }
}

/* ---------- Compose SQL ---------- */
$sql = "
SELECT
       {$select_sql},
       {$ground_select}
FROM `{$flight_table}` f
{$join_src}
{$join_city_src}
{$join_dst}
{$join_city_dst}
{$join_airline}
{$join_ground}
ORDER BY f." . ($col_depart ?? $col_fid) . " ASC
LIMIT 500
";

/* Execute */
$res = $mysqli->query($sql);
if (!$res) {
    echo '<div class="container my-4"><div class="alert alert-danger">Failed to load flights: ' . htmlspecialchars($mysqli->error) . '</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;

/** Render helper to create friendly airport label **/
function airport_label($code, $airport_name, $city_name, $raw) {
    $code = trim((string)$code);
    $airport_name = trim((string)$airport_name);
    $city_name = trim((string)$city_name);
    if ($code !== '' && $airport_name !== '') {
        return strtoupper($code) . " (" . $airport_name . ")";
    }
    if ($airport_name !== '' && $city_name !== '') {
        return $city_name . " — " . $airport_name;
    }
    if ($airport_name !== '') return $airport_name;
    if ($code !== '') return strtoupper($code);
    return $raw !== null ? (string)$raw : '—';
}
?>

<div class="container my-4">
  <h2 class="mb-3">✈️ Live Flight Board</h2>

  <div class="table-responsive">
    <table class="table align-middle">
      <thead class="table-primary">
        <tr>
          <th>Flight</th>
          <th>Airline</th>
          <th>From</th>
          <th>To</th>
          <th>Departure</th>
          <th>Arrival</th>
          <th>Gate</th>
          <th>Terminal</th>
          <th>Belt</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
           $flight_code = $r['flight_code'] ?? $r['flight_id'] ?? '—';

           $src_code = $r['src_code'] ?? null;
           $src_airport_name = $r['src_airport_name'] ?? null;
           $src_city_name = $r['src_city_name'] ?? null;
           $src_raw = $r['src_code'] ?? null;
           $from = airport_label($src_code, $src_airport_name, $src_city_name, $src_raw);

           $dst_code = $r['dst_code'] ?? null;
           $dst_airport_name = $r['dst_airport_name'] ?? null;
           $dst_city_name = $r['dst_city_name'] ?? null;
           $dst_raw = $r['dst_code'] ?? null;
           $to = airport_label($dst_code, $dst_airport_name, $dst_city_name, $dst_raw);

           $dep  = !empty($r['departure_time']) ? date('M d, H:i', strtotime($r['departure_time'])) : '—';
           $arr  = !empty($r['arrival_time'])   ? date('M d, H:i', strtotime($r['arrival_time'])) : '—';
           $gate = $r['gate'] ?? '—';
           $term = $r['terminal'] ?? '—';
           $belt = $r['belt'] ?? ($r['baggage_belt'] ?? '—');
           $airline = $r['airline_name'] ?? ($r['operator'] ?? '—');
           $status = !empty($r['status']) ? ucfirst($r['status']) : 'Scheduled';
        ?>
          <tr>
            <td><strong><?= htmlspecialchars($flight_code) ?></strong></td>
            <td><?= htmlspecialchars($airline) ?></td>
            <td style="min-width:220px"><?= htmlspecialchars($from) ?></td>
            <td style="min-width:220px"><?= htmlspecialchars($to) ?></td>
            <td><?= htmlspecialchars($dep) ?></td>
            <td><?= htmlspecialchars($arr) ?></td>
            <td><?= htmlspecialchars($gate) ?></td>
            <td><?= htmlspecialchars($term) ?></td>
            <td><?= htmlspecialchars($belt) ?></td>
            <td><strong><?= htmlspecialchars($status) ?></strong></td>
          </tr>
        <?php endforeach; ?>

        <?php if (empty($rows)): ?>
          <tr><td colspan="10" class="text-center">No flights found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
