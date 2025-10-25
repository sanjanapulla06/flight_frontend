<?php
// tools/seed_all_pairs.php  (FIXED: flight_id length safe <= 20 chars)
require_once __DIR__ . '/../includes/db.php';
ini_set('display_errors',1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) session_start();

// ---- CONFIG ----
$start_date = new DateTime('2025-10-25');   // inclusive
$end_date   = new DateTime('2025-11-20');   // inclusive
$flights_per_route_per_day = 3;            // adjust if needed
$random_suffix = true;                     // tiny random suffix to reduce collisions
$max_runtime_seconds = 1800;               // seconds, bump if needed
set_time_limit($max_runtime_seconds);
// -----------------

echo "<h2>All-pairs seeder — starting</h2>";
echo "<p>Range: {$start_date->format('Y-m-d')} → {$end_date->format('Y-m-d')}, flights/day per route per airline: {$flights_per_route_per_day}</p>";

// choose flights table name (prefer 'flights' then 'flight')
$tbl = null;
if ($mysqli->query("SHOW TABLES LIKE 'flights'")->num_rows > 0) $tbl = 'flights';
elseif ($mysqli->query("SHOW TABLES LIKE 'flight'")->num_rows > 0) $tbl = 'flight';
else {
    die("<div style='color:red'>No flights table found. Expected table named 'flights' or 'flight'.</div>");
}
echo "<p>Using flights table: <strong>{$tbl}</strong></p>";

// fetch airports
$res = $mysqli->query("SELECT airport_id, airport_name, airport_code, city_id FROM airport ORDER BY airport_id ASC");
$airports = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
if (count($airports) < 2) {
    die("<div style='color:red'>Need at least 2 airports in `airport` table. Found: " . count($airports) . "</div>");
}
$airport_count = count($airports);
echo "<p>Airports found: {$airport_count}</p>";

// fetch airlines
$res2 = $mysqli->query("SELECT airline_id, iata_code, airline_name FROM airline ORDER BY airline_id ASC");
$airlines = $res2 ? $res2->fetch_all(MYSQLI_ASSOC) : [];
if (empty($airlines)) {
    die("<div style='color:red'>No airlines found in `airline` table.</div>");
}
echo "<p>Airlines found: " . count($airlines) . "</p>";

// prepare insert SQL (use columns typical in your schema)
$sql = "INSERT INTO {$tbl}
        (flight_id, source_id, destination_id, airline_id, flight_date, status, d_time, a_time, departure_time, arrival_time, tot_seat, base_price, price, flight_type, no_of_stops)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE price = VALUES(price), departure_time = VALUES(departure_time), arrival_time = VALUES(arrival_time)";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die("<div style='color:red'>Prepare failed: " . htmlspecialchars($mysqli->error) . "</div>");
}

$inserted = 0;
$skipped = 0;
$errors = 0;
$logSample = [];
$max_logs = 200;

// build pairs
$ids = array_column($airports, 'airport_id');
$pairs = [];
foreach ($ids as $src) {
    foreach ($ids as $dst) {
        if ($src == $dst) continue;
        $pairs[] = [$src, $dst];
    }
}
$total_pairs = count($pairs);

// estimates & human warning
$days = $start_date->diff($end_date)->days + 1;
$estimate = $total_pairs * count($airlines) * $days * $flights_per_route_per_day;
echo "<p>Total ordered pairs: {$total_pairs}</p>";
echo "<p>Days: {$days} — Estimated rows to insert: <strong>{$estimate}</strong> (approx)</p>";
echo "<div style='padding:8px;background:#fff7cc;border-radius:6px'>If this number is large, reduce <code>\$flights_per_route_per_day</code> or limit airports.</div>";

// main loop
$start_ts = time();
$progress_count = 0;

foreach ($pairs as $pair) {
    if (time() - $start_ts > $max_runtime_seconds - 5) {
        echo "<div style='color:orange'>Stopping early due to runtime limit.</div>";
        break;
    }

    list($src_id, $dst_id) = $pair;

    $cur_day = clone $start_date;
    while ($cur_day <= $end_date) {
        $date = $cur_day->format('Y-m-d');
        $short_date = $cur_day->format('ymd'); // yymmdd used in flight_id

        foreach ($airlines as $aiIndex => $ai) {
            // sanitize iata (max 3 chars)
            $iata_raw = strtoupper(preg_replace('/[^A-Z0-9]/', '', ($ai['iata_code'] ?? substr($ai['airline_name'],0,3))));
            $iata = substr($iata_raw, 0, 3);
            for ($fnum = 1; $fnum <= $flights_per_route_per_day; $fnum++) {
                // generate departure time
                $seed_hour = 6 + (($aiIndex * 3 + $fnum * 5 + ($src_id + $dst_id) ) % 16);
                $seed_min  = ( ($aiIndex * 13 + $fnum * 7 + ($src_id + $dst_id)) % 60 );
                $d_time = sprintf("%02d:%02d:00", $seed_hour, $seed_min);
                $dep_dt = $date . ' ' . $d_time;

                // duration and arrival
                $duration = 60 + (($aiIndex * 13 + $fnum * 11 + ($src_id + $dst_id)) % 241); // 60..300
                $arrival_dt = date('Y-m-d H:i:s', strtotime($dep_dt . " + {$duration} minutes"));
                $a_time = date('H:i:s', strtotime($arrival_dt));

                // compact flight_id generation (<=20 chars)
                // Format: IAT + yymmdd + src%100 (2) + dst%100 (2) + fnum(1) + rnd2 (2)  => <= 3+6+2+2+1+2 = 16
                $src_mod = str_pad((string)($src_id % 100), 2, '0', STR_PAD_LEFT);
                $dst_mod = str_pad((string)($dst_id % 100), 2, '0', STR_PAD_LEFT);
                $fnum_char = (string)$fnum;
                $rnd = $random_suffix ? substr(bin2hex(random_bytes(1)), 0, 2) : '';
                $flight_id = "{$iata}{$short_date}{$src_mod}{$dst_mod}{$fnum_char}{$rnd}";

                // ensure it's <= 20 (defensive)
                if (strlen($flight_id) > 20) {
                    $flight_id = substr($flight_id, 0, 20);
                }

                // prepare bind variables (all simple vars)
                $flight_id_var = $flight_id;
                $src_var = (int)$src_id;
                $dst_var = (int)$dst_id;
                $airline_var = (int)$ai['airline_id'];
                $date_var = $date;
                $status_bind = 'On Time';
                $d_time_var = $d_time;
                $a_time_var = $a_time;
                $dep_dt_var = $dep_dt;
                $arrival_dt_var = $arrival_dt;
                $tot_seat_bind = (int)(150 + (($aiIndex * 7 + $fnum * 3) % 150));
                $base_price_bind = (float)(2000 + (($src_id + $dst_id + $aiIndex) % 4000));
                $price_bind = (float)($base_price_bind + (($fnum * 150) % 1000));
                $flight_type_bind = 'Mixed';
                $no_of_stops_bind = 0;

                $ok = $stmt->bind_param(
                    'siiissssssiddsi',
                    $flight_id_var,
                    $src_var,
                    $dst_var,
                    $airline_var,
                    $date_var,
                    $status_bind,
                    $d_time_var,
                    $a_time_var,
                    $dep_dt_var,
                    $arrival_dt_var,
                    $tot_seat_bind,
                    $base_price_bind,
                    $price_bind,
                    $flight_type_bind,
                    $no_of_stops_bind
                );

                if (!$ok) {
                    $errors++;
                    if (count($logSample) < $max_logs) $logSample[] = "BIND FAIL {$flight_id}: " . $stmt->error;
                    continue;
                }

                if (!$stmt->execute()) {
                    $errors++;
                    if (count($logSample) < $max_logs) $logSample[] = "EXEC FAIL {$flight_id}: " . $stmt->error;
                    continue;
                }

                if ($stmt->affected_rows > 0) $inserted++;
                else $skipped++;

                $progress_count++;
                if ($progress_count % 500 == 0) {
                    echo "<div>Progress: {$progress_count} rows processed — inserted: {$inserted}, skipped: {$skipped}</div>";
                    flush();
                }
            } // flights per day
        } // airlines

        $cur_day->modify('+1 day');
    } // dates
} // pairs

$stmt->close();

$elapsed = time() - $start_ts;
echo "<h3>Seeder finished</h3>";
echo "<p>Elapsed: {$elapsed}s</p>";
echo "<p>Inserted: <strong>{$inserted}</strong></p>";
echo "<p>Skipped (no-op): <strong>{$skipped}</strong></p>";
echo "<p>Errors: <strong>{$errors}</strong></p>";
if (!empty($logSample)) {
    echo "<h4>Sample errors/logs</h4><pre style='max-height:300px;overflow:auto'>".htmlspecialchars(implode("\n", $logSample))."</pre>";
}

echo "<p><a href='/FLIGHT_FRONTEND/tools/check_flights.php'>Run check_flights</a> — then search in UI (use yyyy-mm-dd dates)</p>";
echo "<p><strong>Reminder:</strong> this created many rows. If you want to revert some seeded rows, run a careful DELETE (example):</p>";
echo "<pre>DELETE FROM {$tbl} WHERE flight_date BETWEEN '2025-10-25' AND '2025-11-20' AND flight_id LIKE '___%';</pre>";
?>
