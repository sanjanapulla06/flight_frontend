<?php
// tools/seeds_connect_debug.php
require_once __DIR__ . '/../includes/db.php';
ini_set('display_errors',1); error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) session_start();

echo "<h2>Debug Seeder (small safe run)</h2>";

// Config: tiny run for debugging
$start = new DateTime('2025-10-25');
$end   = new DateTime('2025-10-27'); // 3 days only
$flights_per_route_per_day = 1; // tiny: 1 flight per route per day
$airports = $mysqli->query("SELECT airport_id, airport_name, airport_code FROM airport LIMIT 10")->fetch_all(MYSQLI_ASSOC);
$airlines = $mysqli->query("SELECT airline_id, iata_code FROM airline LIMIT 5")->fetch_all(MYSQLI_ASSOC);

if (count($airports) < 2 || count($airlines) < 1) {
    die("<div style='color:red'>Need at least 2 airports and 1 airline. Found airports: " . count($airports) . ", airlines: " . count($airlines) . "</div>");
}

$sql = "INSERT INTO flight
        (flight_id, source_id, destination_id, airline_id, flight_date, status, d_time, a_time, departure_time, arrival_time, tot_seat, base_price, price, flight_type, no_of_stops)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE price = VALUES(price), departure_time = VALUES(departure_time), arrival_time = VALUES(arrival_time)";
$stmt = $mysqli->prepare($sql);
if (!$stmt) die("<div style='color:red'>Prepare failed: " . htmlspecialchars($mysqli->error) . "</div>");

$inserted = 0; $updated = 0; $errors = [];

$cur = clone $start;
while ($cur <= $end) {
    $dateStr = $cur->format('Y-m-d');
    foreach ($airports as $src) {
        foreach ($airports as $dst) {
            if ($src['airport_id'] == $dst['airport_id']) continue;
            foreach ($airlines as $aiIndex => $ai) {
                for ($fnum = 1; $fnum <= $flights_per_route_per_day; $fnum++) {
                    $dep_hour = 8 + ($aiIndex % 8);
                    $dep_min = 0;
                    $d_time = sprintf("%02d:%02d:00", $dep_hour, $dep_min);
                    $dep_dt = $dateStr . ' ' . $d_time;
                    $arr_dt = date('Y-m-d H:i:s', strtotime($dep_dt . ' + 90 minutes'));
                    $a_time = date('H:i:s', strtotime($arr_dt));
                    $flight_id = strtoupper($ai['iata_code'] . '-' . $src['airport_id'] . $dst['airport_id'] . '-' . $cur->format('Ymd') . '-' . $fnum);
                    $status = 'On Time';
                    $tot_seat = 180;
                    $base_price = 3000 + ($aiIndex * 100);
                    $price = $base_price + ($fnum * 100);
                    $flight_type = 'Mixed';
                    $no_of_stops = 0;

                    // bind and execute
                    $ok = $stmt->bind_param(
                        'siiissssssiddsi',
                        $flight_id,
                        $src['airport_id'],
                        $dst['airport_id'],
                        $ai['airline_id'],
                        $dateStr,
                        $status,
                        $d_time,
                        $a_time,
                        $dep_dt,
                        $arr_dt,
                        $tot_seat,
                        $base_price,
                        $price,
                        $flight_type,
                        $no_of_stops
                    );

                    if (!$ok) {
                        $errors[] = "BIND FAIL {$flight_id}: " . $stmt->error;
                        continue;
                    }

                    if (!$stmt->execute()) {
                        $errors[] = "EXEC FAIL {$flight_id}: " . $stmt->error;
                    } else {
                        // affected_rows: 1 inserted, 2 updated (MySQL sometimes returns 2 for update with ON DUPLICATE)
                        if ($stmt->affected_rows == 1) {
                            $inserted++;
                            echo "<div style='color:green'>Inserted: {$flight_id}</div>";
                        } elseif ($stmt->affected_rows > 1) {
                            $updated++;
                            echo "<div style='color:orange'>Updated (dup): {$flight_id}</div>";
                        } else {
                            // 0 -> no change (duplicate row identical)
                            echo "<div style='color:gray'>No-op (exists): {$flight_id}</div>";
                            $updated++;
                        }
                    }
                } // flights per day
            } // airlines
        } // dst
    } // src
    $cur->modify('+1 day');
}

$stmt->close();

echo "<h4>Summary</h4>";
echo "<p>Inserted: <strong>{$inserted}</strong></p>";
echo "<p>Updated/No-op: <strong>{$updated}</strong></p>";
if (!empty($errors)) {
    echo "<h4>Errors</h4><pre>" . htmlspecialchars(implode("\n", $errors)) . "</pre>";
}
echo "<p><a href='/FLIGHT_FRONTEND/tools/check_flights.php'>Run check_flights now</a></p>";
