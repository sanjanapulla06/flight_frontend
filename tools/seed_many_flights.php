<?php
// tools/seed_many_flights.php
// Safe seeder: inserts many flights for the same routes with multiple airlines up to 2025-11-20.
// Place this file in FLIGHT_FRONTEND/tools/ and open in browser. Requires includes/db.php to exist.

require_once __DIR__ . '/../includes/db.php'; // provides $mysqli
if (session_status() === PHP_SESSION_NONE) session_start();

// CONFIG — adjust if you want other routes or end date
$routes = [
    ['src' => 'Kempegowda International Airport', 'dst' => 'Chhatrapati Shivaji Maharaj International Airport'],
    ['src' => 'Chhatrapati Shivaji Maharaj International Airport', 'dst' => 'Kempegowda International Airport'],
];

$airlines = [
    ['code' => 'AI', 'name' => 'Air India',    'base_price' => 5200],
    ['code' => '6E', 'name' => 'IndiGo',       'base_price' => 5000],
    ['code' => 'SG', 'name' => 'SpiceJet',     'base_price' => 4900],
    ['code' => 'UK', 'name' => 'Vistara',      'base_price' => 5300],
];

$times = [
    ['dep' => '06:30', 'dur' => 90],
    ['dep' => '09:30', 'dur' => 90],
    ['dep' => '14:30', 'dur' => 90],
    ['dep' => '18:30', 'dur' => 90],
];

$start = new DateTime('2025-10-25');
$end   = new DateTime('2025-11-20'); // inclusive

// Prepared INSERT (flight_code unique) — uses ON DUPLICATE KEY UPDATE no-op
$sql = "INSERT INTO flights (flight_code, source, destination, departure_time, arrival_time, price, base_price)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE price = price";

$insertStmt = $mysqli->prepare($sql);
if (!$insertStmt) {
    echo "<h3 style='color:red'>Prepare failed: " . htmlspecialchars($mysqli->error) . "</h3>";
    exit;
}

$cur = clone $start;
$totalInserted = 0;
$totalSkipped = 0;
$log = [];

while ($cur <= $end) {
    $dateStr = $cur->format('Y-m-d');
    foreach ($routes as $route) {
        foreach ($airlines as $aiIndex => $ai) {
            foreach ($times as $slotIndex => $slot) {
                // construct flight_code e.g. AI20251025XX
                $seq = $slotIndex + 1;
                $flight_code = sprintf('%s%s%02d', $ai['code'], $cur->format('Ymd'), $seq + ($aiIndex * 10));
                $dep_dt_str = $dateStr . ' ' . $slot['dep'] . ':00';

                // compute arrival_time by adding duration minutes
                $dep_dt = DateTime::createFromFormat('Y-m-d H:i:s', $dep_dt_str);
                if (!$dep_dt) {
                    $log[] = "Invalid departure datetime format for $flight_code: $dep_dt_str";
                    continue;
                }
                $arr_dt = (clone $dep_dt)->modify('+' . $slot['dur'] . ' minutes');

                // price slightly varies per airline & slot
                $price_variation = ($aiIndex * 150) + ($slotIndex * 50);
                $price = $ai['base_price'] + $price_variation;
                $base_price = $ai['base_price'] + ($aiIndex * 100);

                // IMPORTANT: bind_param requires variables (passed by reference), not expressions
                $dep_str = $dep_dt->format('Y-m-d H:i:s');
                $arr_str = $arr_dt->format('Y-m-d H:i:s');
                $price_val = (float)$price;
                $base_price_val = (float)$base_price;

                // types: s s s s s d d  -> 5 strings (flight_code, source, dest, dep, arr) and 2 doubles
                $ok = $insertStmt->bind_param(
                    'sssssdd',
                    $flight_code,
                    $route['src'],
                    $route['dst'],
                    $dep_str,
                    $arr_str,
                    $price_val,
                    $base_price_val
                );

                if ($ok === false) {
                    $log[] = "Bind failed for $flight_code: " . htmlspecialchars($insertStmt->error);
                    continue;
                }

                $execOk = $insertStmt->execute();
                if ($execOk) {
                    // affected_rows > 0 means a new row was inserted; 0 means duplicate (no-op)
                    if ($insertStmt->affected_rows > 0) {
                        $totalInserted++;
                        $log[] = "Inserted $flight_code {$route['src']}→{$route['dst']} on {$dep_str}";
                    } else {
                        $totalSkipped++;
                    }
                } else {
                    $log[] = "ERROR inserting $flight_code: " . htmlspecialchars($insertStmt->error);
                }
            } // times
        } // airlines
    } // routes

    $cur->modify('+1 day');
}

// finish
$insertStmt->close();

echo "<h2>Seeder finished</h2>";
echo "<p>Total inserted: <strong>{$totalInserted}</strong></p>";
echo "<p>Total skipped (already existed): <strong>{$totalSkipped}</strong></p>";

echo "<details style='max-height:400px;overflow:auto'><summary>View log (first 500 lines)</summary><pre>";
$show = array_slice($log, 0, 500);
echo htmlspecialchars(implode("\n", $show));
if (count($log) > 500) echo "\n\n... (" . (count($log)-500) . " more lines)";
echo "</pre></details>";

echo "<p><a href='/FLIGHT_FRONTEND/search.php'>Open Search Page</a></p>";
