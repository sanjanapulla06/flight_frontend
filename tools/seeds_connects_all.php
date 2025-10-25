<?php
// /FLIGHT_FRONTEND/tools/seeds_connect_all.php
// Creates flights between every airport -> every other airport for a date range.
// WARNING: This can create a LOT of rows. Adjust $days_to_generate, $flights_per_route_per_day.

require_once __DIR__ . '/../includes/db.php';
ini_set('display_errors',1); error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) session_start();

set_time_limit(0);

// CONFIG - tune these before running
$start_date = new DateTime('2025-10-25');
$end_date   = new DateTime('2025-11-20'); // inclusive
$days_to_generate = (int)$start_date->diff($end_date)->days + 1;
$flights_per_route_per_day = 3;   // e.g. 3 flights per route per day
$seat_min = 150; $seat_max = 300;

// Fetch airports (use airport_id, airport_name, airport_code, city_id)
$res = $mysqli->query("SELECT airport_id, airport_name, airport_code, city_id FROM airport ORDER BY airport_id ASC");
$airports = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
if (count($airports) < 2) die("Need at least 2 airports to seed flights.\n");

// Fetch airlines (airline_id). If none exist, create a few sample airlines.
$airRes = $mysqli->query("SELECT airline_id, iata_code FROM airline");
$airlines = $airRes ? $airRes->fetch_all(MYSQLI_ASSOC) : [];
if (empty($airlines)) {
    $defaults = [
        ['airline_name' => 'AirOne', 'iata_code' => 'AO'],
        ['airline_name' => 'SkyJet', 'iata_code' => 'SJ'],
        ['airline_name' => 'IndiSwift', 'iata_code' => 'IS'],
    ];
    $stmtA = $mysqli->prepare("INSERT INTO airline (airline_name, iata_code, icao_code) VALUES (?, ?, ?)");
    foreach ($defaults as $d) { $icode = ''; $stmtA->bind_param('sss', $d['airline_name'], $d['iata_code'], $icode); $stmtA->execute(); }
    $stmtA->close();
    $airRes = $mysqli->query("SELECT airline_id, iata_code FROM airline");
    $airlines = $airRes ? $airRes->fetch_all(MYSQLI_ASSOC) : [];
}
if (empty($airlines)) die("No airlines available.\n");

// Prepare INSERT: uses ON DUPLICATE KEY UPDATE so re-runs are safe
$sql = "INSERT INTO flight
        (flight_id, source_id, destination_id, airline_id, flight_date, status, d_time, a_time, departure_time, arrival_time, tot_seat, base_price, price, flight_type, no_of_stops)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE price = VALUES(price), departure_time = VALUES(departure_time), arrival_time = VALUES(arrival_time)";
$insertStmt = $mysqli->prepare($sql);
if (!$insertStmt) { die("Prepare failed: " . $mysqli->error); }

// helper to get airport code fallback
function get_code($airport) {
    $c = trim($airport['airport_code'] ?? '');
    if ($c !== '') return strtoupper(substr(preg_replace('/[^A-Z]/','', $c),0,3));
    // fallback from name
    $parts = preg_split('/\s+/', preg_replace('/[^A-Za-z ]/','', $airport['airport_name']));
    if (count($parts) >= 2) return strtoupper(substr($parts[0],0,1) . substr($parts[1],0,2));
    return strtoupper(substr($airport['airport_name'], 0, 3));
}

// date loop
$inserted = 0; $skipped = 0;
$cur = clone $start_date;
while ($cur <= $end_date) {
    $dateStr = $cur->format('Y-m-d');

    // iterate all unique ordered pairs (src != dst)
    foreach ($airports as $src) {
        foreach ($airports as $dst) {
            if ($src['airport_id'] == $dst['airport_id']) continue;

            // create multiple flights per day for each airline
            foreach ($airlines as $aIndex => $air) {
                for ($fnum = 1; $fnum <= $flights_per_route_per_day; $fnum++) {
                    // choose departure hour spacing, vary by airline+fnum
                    $dep_hour = (6 + (($aIndex * 3 + $fnum) % 14)); // between 6 and 19
                    $dep_min = [0,15,30,45][($aIndex + $fnum) % 4];
                    $duration_minutes = 60 + (($aIndex + $fnum) % 4) * 30; // 60,90,120,150

                    $d_time = sprintf("%02d:%02d:00", $dep_hour, $dep_min);
                    $dep_dt = DateTime::createFromFormat('Y-m-d H:i:s', $dateStr . ' ' . $d_time . ':00');
                    if (!$dep_dt) { continue; }
                    $arr_dt = (clone $dep_dt)->modify("+{$duration_minutes} minutes");
                    $a_time = $arr_dt->format('H:i:00');

                    // prices: base + small variance
                    $base = 3000 + intval(rand(0, 4000));
                    $price = $base + ($aIndex * 150) + ($fnum * 50) + rand(0,200);

                    // seats and stops
                    $tot_seat = rand($seat_min, $seat_max);
                    $no_of_stops = 0;
                    $flight_type = 'Mixed';

                    // create readable flight_id: IATA + SRC + DST + YYYYMMDD + seq
                    $iata = strtoupper(trim($air['iata_code'] ?? 'XX'));
                    $src_c = get_code($src);
                    $dst_c = get_code($dst);
                    $seq = str_pad($fnum, 2, '0', STR_PAD_LEFT);
                    $flight_id = "{$iata}-{$src_c}{$dst_c}-{$cur->format('Ymd')}-{$seq}";

                    // bind parameters by reference
                    $flight_date = $dateStr;
                    $status = 'On Time';
                    $departure_time = $dep_dt->format('Y-m-d H:i:s');
                    $arrival_time = $arr_dt->format('Y-m-d H:i:s');
                    $base_price = (float)$base;
                    $price_val = (float)$price;

                    $types = 'siiissssssiddsi'; // see code comments in chat analysis
                    $ok = $insertStmt->bind_param(
                        $types,
                        $flight_id,
                        $src['airport_id'],
                        $dst['airport_id'],
                        $air['airline_id'],
                        $flight_date,
                        $status,
                        $d_time,
                        $a_time,
                        $departure_time,
                        $arrival_time,
                        $tot_seat,
                        $base_price,
                        $price_val,
                        $flight_type,
                        $no_of_stops
                    );

                    if ($ok === false) { $skipped++; continue; }
                    $execOk = $insertStmt->execute();
                    if ($execOk) {
                        if ($insertStmt->affected_rows > 0) $inserted++;
                    } else {
                        $skipped++;
                        // log minimal error
                    }
                } // fnum
            } // airlines
        } // dst
    } // src

    $cur->modify('+1 day');
} // dates

$insertStmt->close();

echo "<h2>Seeder finished</h2>";
echo "<p>Inserted flights: <strong>{$inserted}</strong></p>";
echo "<p>Skipped (errors/duplicates): <strong>{$skipped}</strong></p>";
echo "<p>Generated flights across " . count($airports) . " airports for {$days_to_generate} days.</p>";
echo "<p><a href='/FLIGHT_FRONTEND/search.php'>Open Search Page</a></p>";
