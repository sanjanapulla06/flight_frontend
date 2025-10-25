<?php
// tools/seed_goi_pairs.php
// Seeds GOI <-> all other airports for date range with multiple flights per day.
// Run from CLI: C:\xampp\php\php.exe C:\xampp\htdocs\flight_frontend\tools\seed_goi_pairs.php

require_once __DIR__ . '/../includes/db.php';
ini_set('display_errors',1); error_reporting(E_ALL);

// config
$start = new DateTime('2025-10-25');
$end   = new DateTime('2025-11-20');
$flights_per_day = 3; // change to taste

// find GOI
$g = $mysqli->query("SELECT airport_id, airport_code FROM airport WHERE airport_code='GOI' LIMIT 1");
if (!$g || $g->num_rows === 0) { die("GOI not found. Add airport first.\n"); }
$grow = $g->fetch_assoc();
$GOI = (int)$grow['airport_id'];
echo "GOI airport_id = {$GOI}\n";

// get other airports
$res = $mysqli->query("SELECT airport_id FROM airport WHERE airport_id <> {$GOI}");
if (!$res) { die("Failed to fetch airports: " . $mysqli->error . "\n"); }
$others = $res->fetch_all(MYSQLI_ASSOC);

// get airlines
$airRes = $mysqli->query("SELECT airline_id, iata_code, airline_name FROM airline");
if (!$airRes) { die("Failed to fetch airlines: " . $mysqli->error . "\n"); }
$airlines = $airRes->fetch_all(MYSQLI_ASSOC);
if (empty($airlines)) die("No airlines found.\n");

echo "Found " . count($others) . " other airports, " . count($airlines) . " airlines.\n";

// prepare insert statement
$sql = "INSERT INTO flight 
        (flight_id, source_id, destination_id, airline_id, flight_date, status, d_time, a_time, departure_time, arrival_time, tot_seat, base_price, price, flight_type, no_of_stops)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE price=VALUES(price), departure_time=VALUES(departure_time), arrival_time=VALUES(arrival_time)";

$stmt = $mysqli->prepare($sql);
if (!$stmt) die("Prepare failed: " . $mysqli->error . "\n");

// static bind param template types
$types = 'siiissssssiddsi';

// counters
$cur = clone $start;
$ins = 0;
$total = 0;

// helper to keep flight_id short and deterministic-ish
function make_fid($iata, DateTime $dt, $src_id, $dst_id, $fnum) {
    $short_date = $dt->format('ymd'); // yymmdd
    $src_mod = str_pad((string)($src_id % 100), 2, '0', STR_PAD_LEFT);
    $dst_mod = str_pad((string)($dst_id % 100), 2, '0', STR_PAD_LEFT);
    $base = strtoupper(preg_replace('/[^A-Z0-9]/','', ($iata ?: 'XX')));
    $fid = "{$base}{$short_date}{$src_mod}{$dst_mod}{$fnum}";
    // ensure <= 20
    if (strlen($fid) > 20) $fid = substr($fid, 0, 20);
    return $fid;
}

echo "Seeding from " . $start->format('Y-m-d') . " to " . $end->format('Y-m-d') . " ...\n";

while ($cur <= $end) {
    $date = $cur->format('Y-m-d');
    foreach ($others as $o) {
        $dst = (int)$o['airport_id'];
        // two directions
        $pairs = [ [$GOI, $dst], [$dst, $GOI] ];
        foreach ($pairs as $pair) {
            list($src_id, $dst_id) = $pair;
            foreach ($airlines as $aiIndex => $ai) {
                $ai_id = (int)$ai['airline_id'];
                $iata = strtoupper(preg_replace('/[^A-Z0-9]/','', $ai['iata_code'] ?? 'XX'));
                for ($f=1; $f<=$flights_per_day; $f++) {
                    // compute times
                    $dep_hour = 6 + (($f * 3 + $aiIndex) % 12);
                    $dep_min  = (($aiIndex * 7 + $f * 11) % 60);
                    $d_time = sprintf("%02d:%02d:00", $dep_hour, $dep_min);
                    $dep_dt = $date . ' ' . $d_time;
                    $duration = 60 + (($aiIndex + $f + $src_id + $dst_id) % 300); // 60..359
                    $arrival_dt = date('Y-m-d H:i:s', strtotime("$dep_dt + {$duration} minutes"));
                    $a_time = date('H:i:s', strtotime($arrival_dt));

                    // ids and pricing
                    $flight_id = make_fid($iata, $cur, $src_id, $dst_id, $f);
                    $tot_seat = 160;
                    $base = 2000 + (($src_id + $dst_id) % 4000);
                    $price = $base + $f * 100;

                    // prepare all bind variables (must be variables — not expressions)
                    $flight_id_var = $flight_id;
                    $src_var = (int)$src_id;
                    $dst_var = (int)$dst_id;
                    $airline_var = $ai_id;
                    $date_var = $date;
                    $status_var = 'On Time';
                    $d_time_var = $d_time;
                    $a_time_var = $a_time;
                    $dep_dt_var = $dep_dt;
                    $arrival_dt_var = $arrival_dt;
                    $tot_seat_var = (int)$tot_seat;
                    $base_price_var = (float)$base;
                    $price_var = (float)$price;
                    $flight_type_var = 'Mixed';
                    $no_of_stops_var = 0;

                    // bind and execute
                    $bindOk = $stmt->bind_param(
                        $types,
                        $flight_id_var,
                        $src_var,
                        $dst_var,
                        $airline_var,
                        $date_var,
                        $status_var,
                        $d_time_var,
                        $a_time_var,
                        $dep_dt_var,
                        $arrival_dt_var,
                        $tot_seat_var,
                        $base_price_var,
                        $price_var,
                        $flight_type_var,
                        $no_of_stops_var
                    );

                    if (!$bindOk) {
                        // log and continue
                        fwrite(STDERR, "BIND FAILED for {$flight_id}: " . $stmt->error . PHP_EOL);
                        continue;
                    }

                    if (!$stmt->execute()) {
                        fwrite(STDERR, "EXEC FAILED for {$flight_id}: " . $stmt->error . PHP_EOL);
                        continue;
                    }

                    $total++;
                    if ($stmt->affected_rows > 0) $ins++;

                    // small progress print every 1000 inserts to avoid huge spam
                    if ($total % 1000 == 0) {
                        echo "Processed {$total} rows — inserted: {$ins}\n";
                    }
                } // flights per day
            } // airlines
        } // pairs
    } // others

    $cur->modify('+1 day');
}

// done
$stmt->close();
echo "Done. Total processed: {$total}\nInserted: {$ins}\n";
