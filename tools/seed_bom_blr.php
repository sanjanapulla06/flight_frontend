<?php
// tools/seed_bom_blr.php
require_once __DIR__ . '/../includes/db.php';
ini_set('display_errors',1);
error_reporting(E_ALL);

// config: date range inclusive
$start = new DateTime('2025-10-25');
$end   = new DateTime('2025-11-20');

// find BOM and BLR ids
$bom = $mysqli->query("SELECT airport_id FROM airport WHERE airport_code = 'BOM' LIMIT 1")->fetch_assoc();
$blr = $mysqli->query("SELECT airport_id FROM airport WHERE airport_code = 'BLR' LIMIT 1")->fetch_assoc();
if (!$bom || !$blr) {
    die("Missing BOM or BLR airport rows. Run the airport inserts first.");
}
$BOM = (int)$bom['airport_id'];
$BLR = (int)$blr['airport_id'];

// fetch airlines
$airs = $mysqli->query("SELECT airline_id, iata_code FROM airline")->fetch_all(MYSQLI_ASSOC);
if (empty($airs)) die("No airlines found.");

// prepare insert
$sql = "INSERT INTO flights
    (flight_id, source_id, destination_id, airline_id, flight_date, status, d_time, a_time, departure_time, arrival_time, tot_seat, base_price, price, flight_type, no_of_stops)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE price = VALUES(price), departure_time = VALUES(departure_time), arrival_time = VALUES(arrival_time)";
$stmt = $mysqli->prepare($sql);
if (!$stmt) die("Prepare failed: " . $mysqli->error);

$inserted = 0; $skipped = 0;
$cur = clone $start;
while ($cur <= $end) {
    $date = $cur->format('Y-m-d');
    foreach ($airs as $ai) {
        // create one flight BOM->BLR and one BLR->BOM per airline per day (you can increase count)
        foreach ([[$BOM, $BLR, '09:00:00', 90], [$BLR, $BOM, '15:00:00', 90]] as $idx => $pair) {
            list($src, $dst, $dep_time, $duration_min) = $pair;
            $dep_dt = $date . ' ' . $dep_time;
            $arr_dt = date('Y-m-d H:i:s', strtotime($dep_dt . " + {$duration_min} minutes"));
            // flight id: IATA-srcdst-date-index
            $flight_id = strtoupper($ai['iata_code'] . '-' . $src . $dst . '-' . $cur->format('Ymd') . '-' . ($idx+1));
            $status = 'On Time';
            $a_time = date('H:i:s', strtotime($arr_dt));
            $tot_seat = 180;
            $base_price = 3000 + rand(0,1000);
            $price = $base_price + rand(100,500);
            $flight_type = 'Domestic';
            $no_of_stops = 0;

            $ok = $stmt->bind_param('siiissssssiddsi',
                $flight_id,
                $src,
                $dst,
                $ai['airline_id'],
                $date,
                $status,
                $dep_time,
                $a_time,
                $dep_dt,
                $arr_dt,
                $tot_seat,
                (float)$base_price,
                (float)$price,
                $flight_type,
                $no_of_stops
            );
            if (!$ok) { $skipped++; continue; }
            if ($stmt->execute()) { $inserted++; } else { $skipped++; }
        }
    }
    $cur->modify('+1 day');
}
$stmt->close();

echo "<h3>Seed BOM↔BLR complete</h3>";
echo "<p>Inserted: $inserted — Skipped: $skipped</p>";
echo "<p><a href='/FLIGHT_FRONTEND/tools/check_flights.php'>Run check</a></p>";
