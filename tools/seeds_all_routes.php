<?php
// tools/seed_all_routes.php
// Seeder: generates flights for many routes between major Indian airports (idempotent).
// Place in FLIGHT_FRONTEND/tools/ and open in browser.
// Requires includes/db.php ($mysqli) to exist.

require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// --- CONFIG: tweak these arrays to add/remove airports, airlines, times, date range ---
$airports = [
    ['name' => 'Chhatrapati Shivaji Maharaj International Airport', 'city' => 'Mumbai', 'code' => 'BOM'],
    ['name' => 'Kempegowda International Airport', 'city' => 'Bengaluru', 'code' => 'BLR'],
    ['name' => 'Indira Gandhi International Airport', 'city' => 'New Delhi', 'code' => 'DEL'],
    ['name' => 'Rajiv Gandhi International Airport', 'city' => 'Hyderabad', 'code' => 'HYD'],
    ['name' => 'Chennai International Airport', 'city' => 'Chennai', 'code' => 'MAA'],
    ['name' => 'Netaji Subhas Chandra Bose International Airport', 'city' => 'Kolkata', 'code' => 'CCU'],
    ['name' => 'Cochin International Airport', 'city' => 'Kochi', 'code' => 'COK'],
    ['name' => 'Pune International Airport', 'city' => 'Pune', 'code' => 'PNQ'],
    ['name' => 'Goa International Airport', 'city' => 'Goa', 'code' => 'GOI'],
    ['name' => 'Trivandrum International Airport', 'city' => 'Thiruvananthapuram', 'code' => 'TRV'],
];

$airlines = [
    ['code' => 'AI', 'name' => 'Air India', 'base_price' => 5200],
    ['code' => '6E', 'name' => 'IndiGo', 'base_price' => 5000],
    ['code' => 'SG', 'name' => 'SpiceJet', 'base_price' => 4900],
    ['code' => 'UK', 'name' => 'Vistara', 'base_price' => 5300],
];

// departure times (HH:MM) and duration minutes — feel free to adjust
$time_slots = [
    ['dep' => '06:00', 'dur' => 90],
    ['dep' => '09:00', 'dur' => 90],
    ['dep' => '13:00', 'dur' => 90],
    ['dep' => '17:00', 'dur' => 90],
    ['dep' => '20:00', 'dur' => 90],
];

// date range (inclusive)
$start = new DateTime('2025-10-25');
$end   = new DateTime('2025-11-20');

// --- helper: safe print for browser ---
function p($s) { echo $s . "<br>\n"; flush(); }

// --- Step 1: insert airports (idempotent) ---
p("<strong>Seeding airports...</strong>");
$insAirportSQL = "INSERT INTO airport (airport_name, city, airport_code) VALUES (?, ?, ?)
                  ON DUPLICATE KEY UPDATE airport_code = airport_code";
$insAirportStmt = $mysqli->prepare($insAirportSQL);
if (!$insAirportStmt) {
    die("<span style='color:red'>Airport INSERT prepare failed: " . htmlspecialchars($mysqli->error) . "</span>");
}
foreach ($airports as $ap) {
    $an = $ap['name'];
    $ct = $ap['city'];
    $ac = $ap['code'];
    $insAirportStmt->bind_param('sss', $an, $ct, $ac);
    $ok = $insAirportStmt->execute();
    if (!$ok) {
        p("Airport insert error for {$ac}: " . htmlspecialchars($insAirportStmt->error));
    }
}
$insAirportStmt->close();
p("Airports seeded (or already present).");

// --- Step 2: prepare flights insert (idempotent) ---
p("<strong>Seeding flights across routes...</strong>");
$insertSQL = "INSERT INTO flights (flight_code, source, destination, departure_time, arrival_time, price, base_price)
              VALUES (?, ?, ?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE price = price";
$insertStmt = $mysqli->prepare($insertSQL);
if (!$insertStmt) {
    die("<span style='color:red'>Flights INSERT prepare failed: " . htmlspecialchars($mysqli->error) . "</span>");
}

// We will iterate over ordered pairs of airports (src != dst)
$airport_count = count($airports);
$totalInserted = 0;
$totalSkipped = 0;
$log = [];

// iterate dates
$cur = clone $start;
while ($cur <= $end) {
    $date = $cur->format('Y-m-d');
    for ($i = 0; $i < $airport_count; $i++) {
        for ($j = 0; $j < $airport_count; $j++) {
            if ($i === $j) continue; // skip same-airport routes

            $src = $airports[$i]['name'];
            $dst = $airports[$j]['name'];

            // for each airline and each time slot, create a flight
            foreach ($airlines as $aiIndex => $ai) {
                foreach ($time_slots as $slotIndex => $slot) {
                    // flight code: e.g. AI20251025-01 (airline+date+seq)
                    $seq = $slotIndex + 1 + ($aiIndex * 10);
                    $flight_code = sprintf('%s%s%02d', $ai['code'], $cur->format('Ymd'), $seq);

                    // departure and arrival datetimes
                    $dep_str = $date . ' ' . $slot['dep'] . ':00';
                    $dep_dt = DateTime::createFromFormat('Y-m-d H:i:s', $dep_str);
                    if (!$dep_dt) {
                        $log[] = "Bad dep dt: $dep_str";
                        continue;
                    }
                    $arr_dt = (clone $dep_dt)->modify('+' . $slot['dur'] . ' minutes');
                    $arr_str = $arr_dt->format('Y-m-d H:i:s');

                    // price variation
                    $price_variation = ($aiIndex * 150) + ($slotIndex * 50);
                    $price = (float)($ai['base_price'] + $price_variation);
                    $base_price = (float)($ai['base_price'] + ($aiIndex * 100));

                    // bind - IMPORTANT: all passed values must be variables (references)
                    $bind_flight_code = $flight_code;
                    $bind_src = $src;
                    $bind_dst = $dst;
                    $bind_dep = $dep_dt->format('Y-m-d H:i:s');
                    $bind_arr = $arr_str;
                    $bind_price = $price;
                    $bind_base = $base_price;

                    // types: s s s s s d d
                    $ok = $insertStmt->bind_param('sssssdd',
                        $bind_flight_code,
                        $bind_src,
                        $bind_dst,
                        $bind_dep,
                        $bind_arr,
                        $bind_price,
                        $bind_base
                    );

                    if ($ok === false) {
                        $log[] = "Bind failed for $flight_code: " . $insertStmt->error;
                        continue;
                    }

                    $execOk = $insertStmt->execute();
                    if ($execOk) {
                        if ($insertStmt->affected_rows > 0) {
                            $totalInserted++;
                        } else {
                            $totalSkipped++;
                        }
                    } else {
                        $log[] = "Execute failed for $flight_code: " . htmlspecialchars($insertStmt->error);
                    }
                } // slots
            } // airlines
        } // dst loop
    } // src loop

    // advance day
    $cur->modify('+1 day');
} // date loop

$insertStmt->close();

p("<strong>Done seeding flights.</strong>");
p("Inserted: <strong>$totalInserted</strong>");
p("Skipped (already existed): <strong>$totalSkipped</strong>");
if (!empty($log)) {
    echo "<details style='max-height:300px;overflow:auto'><summary>Logs (first 200 lines)</summary><pre>";
    echo htmlspecialchars(implode("\n", array_slice($log,0,200)));
    echo "</pre></details>";
}

p("<p><a href='/FLIGHT_FRONTEND/search.php'>Open Search Page</a></p>");
p("<p>Tip: try searching for city names like 'Mumbai' or codes like 'BOM' — search matches by airport name and airport table values if present.</p>");
