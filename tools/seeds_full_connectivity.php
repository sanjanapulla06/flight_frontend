<?php
// ✅ tools/seeds_full_connectivity.php
require_once __DIR__ . '/../includes/db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

set_time_limit(0);

$flights_per_route_per_day = 3; // how many flights per route per day
$days_to_generate = 20; // how many future days

echo "<h2>🌍 Seeding full connectivity...</h2>";

// fetch airports
$res = $mysqli->query("SELECT airport_name, airport_code FROM airport ORDER BY airport_name ASC");
$airports = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
if (count($airports) < 2) die("❌ Need at least 2 airports in your DB.");

// fetch or create airlines
$airRes = $mysqli->query("SELECT airline_id FROM airline");
$airlines = $airRes ? array_column($airRes->fetch_all(MYSQLI_ASSOC), 'airline_id') : [];
if (empty($airlines)) {
    echo "✈️ Creating sample airlines...<br>";
    $defaults = [[111, 'AirOne'], [112, 'SkyJet'], [113, 'IndiSwift']];
    $stmtA = $mysqli->prepare("INSERT IGNORE INTO airline (airline_id, airline_name) VALUES (?, ?)");
    foreach ($defaults as $d) { $stmtA->bind_param('is', $d[0], $d[1]); $stmtA->execute(); }
    $stmtA->close();
    $airRes = $mysqli->query("SELECT airline_id FROM airline");
    $airlines = $airRes ? array_column($airRes->fetch_all(MYSQLI_ASSOC), 'airline_id') : [];
}

// prepare insert — IGNORE avoids duplicates 🔥
$sql = "INSERT IGNORE INTO flight 
        (flight_id, airline_id, source, destination, d_time, a_time, flight_date, status, tot_seat, flight_type, no_of_stops, price)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $mysqli->prepare($sql);
if (!$stmt) die("❌ Prepare failed: " . $mysqli->error);

// helper: get 3-letter code
function code3($airport) {
    $code = trim($airport['airport_code'] ?? '');
    if ($code) {
        $c = preg_replace('/[^A-Z]/', '', strtoupper($code));
        return str_pad(substr($c, 0, 3), 3, 'X');
    }
    $name = preg_replace('/[^A-Za-z ]/', '', $airport['airport_name']);
    $parts = preg_split('/\s+/', trim($name));
    if (count($parts) >= 2) {
        $c = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 2));
        return str_pad(substr($c, 0, 3), 3, 'X');
    }
    return str_pad(strtoupper(substr($name, 0, 3)), 3, 'X');
}

$inserted = 0; 
$skipped = 0;

foreach ($airports as $src) {
    foreach ($airports as $dst) {
        if ($src['airport_name'] === $dst['airport_name']) continue;
        $src3 = code3($src); 
        $dst3 = code3($dst);

        for ($day = 0; $day < $days_to_generate; $day++) {
            $flight_date = date('Y-m-d', strtotime("+{$day} days"));
            for ($n = 1; $n <= $flights_per_route_per_day; $n++) {
                $dep_hour = rand(5, 20); 
                $dep_min = rand(0, 59);
                $dur_hours = rand(1, 3); 
                $arr_hour = ($dep_hour + $dur_hours) % 24; 
                $arr_min = rand(0, 59);

                $d_time = sprintf("%02d:%02d:00", $dep_hour, $dep_min);
                $a_time = sprintf("%02d:%02d:00", $arr_hour, $arr_min);

                // ✈️ unique flight_id with random suffix
                $day_dd = date('d', strtotime($flight_date)); 
                $seq = sprintf('%02d', $n);
                $rand = rand(10, 99);
                $flight_id = substr($src3, 0, 3) . substr($dst3, 0, 3) . $day_dd . $seq . $rand;

                $airline_id = $airlines[array_rand($airlines)];
                $status = 'ON_TIME';
                $tot_seat = rand(150, 250);
                $flight_type = 'Domestic';
                $no_of_stops = 0;

                // 💸 random price generator
                $price = round(rand(2500, 9000) + rand(1, 200), 2);

                // bind 12 params: sissssssisid
                if (!$stmt->bind_param(
                    'sissssssisid',
                    $flight_id,
                    $airline_id,
                    $src['airport_name'],
                    $dst['airport_name'],
                    $d_time,
                    $a_time,
                    $flight_date,
                    $status,
                    $tot_seat,
                    $flight_type,
                    $no_of_stops,
                    $price
                )) {
                    $skipped++;
                    continue;
                }

                if (!$stmt->execute()) {
                    $skipped++;
                    continue;
                }
                $inserted++;
            }
        }
    }
}

$stmt->close();
echo "<h3>✅ Seeding complete!</h3>";
echo "<p>Inserted flights: <strong>{$inserted}</strong></p>";
echo "<p>Skipped (duplicates/errors): <strong>{$skipped}</strong></p>";
echo "<p>Now you can search for routes like <strong>BLR → DEL</strong> or <strong>MAA → GOI</strong></p>";
