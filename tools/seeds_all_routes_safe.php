<?php
// flight_frontend/tools/seeds_all_routes_safe.php
require_once __DIR__ . '/../includes/db.php'; // adjust path if needed
ini_set('display_errors',1);
error_reporting(E_ALL);

// fetch airports
$res = $mysqli->query("SELECT airport_name, airport_code, city FROM airport ORDER BY airport_code ASC");
$airports = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

if (count($airports) < 2) {
    echo "<h2 style='color:crimson'>Need at least 2 airports in `airport` table. Found: ".count($airports)."</h2>";
    exit;
}

// fetch real airline ids; if none, create defaults
$airRes = $mysqli->query("SELECT airline_id FROM airline ORDER BY airline_id ASC");
$airlines = $airRes ? $airRes->fetch_all(MYSQLI_ASSOC) : [];

if (empty($airlines)) {
    $sample = [
        ['airline_id'=>111, 'airline_name'=>'AirOne'],
        ['airline_id'=>112, 'airline_name'=>'BlueSky Airways'],
        ['airline_id'=>113, 'airline_name'=>'IndiSwift']
    ];
    $insA = $mysqli->prepare("INSERT INTO airline (airline_id, airline_name) VALUES (?, ?)");
    foreach ($sample as $s) {
        $insA->bind_param('is', $s['airline_id'], $s['airline_name']);
        $insA->execute();
    }
    if (isset($insA)) $insA->close();
    $airRes = $mysqli->query("SELECT airline_id FROM airline ORDER BY airline_id ASC");
    $airlines = $airRes ? $airRes->fetch_all(MYSQLI_ASSOC) : [];
}

$air_ids = array_map(function($r){ return (int)$r['airline_id']; }, $airlines);

// prepare insert (using columns your DB has: flight_id, source, destination, status, d_time, a_time, airline_id, tot_seat, flight_type, layover_time, no_of_stops, flight_date)
$insert_sql = "INSERT INTO flight 
    (flight_id, source, destination, status, d_time, a_time, airline_id, tot_seat, flight_type, layover_time, no_of_stops, flight_date)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $mysqli->prepare($insert_sql);
if (!$stmt) {
    die("Prepare failed: " . $mysqli->error);
}

$inserted = 0;
$skipped = 0;
$errors = [];
$now = time();

foreach ($airports as $src) {
    foreach ($airports as $dst) {
        if ($src['airport_name'] === $dst['airport_name']) continue;

        // flight code unique
        $codeA = strtoupper(preg_replace('/[^A-Z]/','', ($src['airport_code'] ?: substr($src['airport_name'],0,3))));
        $codeB = strtoupper(preg_replace('/[^A-Z]/','', ($dst['airport_code'] ?: substr($dst['airport_name'],0,3))));
        if ($codeA === '') $codeA = substr(strtoupper($src['airport_name']),0,3);
        if ($codeB === '') $codeB = substr(strtoupper($dst['airport_name']),0,3);

        $fid = substr($codeA,0,3) . substr($codeB,0,3) . rand(100,999);

        // pick random airline id from existing list
        $airline_id = $air_ids[array_rand($air_ids)];
        $dep_hour = rand(5,22);
        $arr_hour = ($dep_hour + rand(1,4)) % 24;
        $dep_time = sprintf("%02d:%02d:00", $dep_hour, rand(0,59));
        $arr_time = sprintf("%02d:%02d:00", $arr_hour, rand(0,59));
        $flight_date = date('Y-m-d', strtotime("+".rand(0,14)." days"));
        $status = "On Time";
        $tot_seat = rand(120,220);
        $flight_type = "Domestic";
        $layover_time = 0;
        $no_of_stops = 0;

        // simple duplicate check: if same flight_id exists skip
        $check = $mysqli->prepare("SELECT 1 FROM flight WHERE flight_id = ? LIMIT 1");
        $check->bind_param('s', $fid);
        $check->execute();
        $exists = $check->get_result()->fetch_row();
        $check->close();
        if ($exists) { $skipped++; continue; }

        $stmt->bind_param(
            'ssssssisssis',
            $fid, 
            $src['airport_name'],
            $dst['airport_name'],
            $status,
            $dep_time,
            $arr_time,
            $airline_id,
            $tot_seat,
            $flight_type,
            $layover_time,
            $no_of_stops,
            $flight_date
        );
        if (!$stmt->execute()) {
            $errors[] = "Failed {$fid}: " . $stmt->error;
            $skipped++;
            continue;
        }
        $inserted++;
    }
}

$stmt->close();

echo "<h2>Seeding complete</h2>";
echo "<p style='color:green'>Inserted: {$inserted}</p>";
echo "<p style='color:orange'>Skipped (duplicates/errors): {$skipped}</p>";
if (!empty($errors)) {
    echo "<details><summary>Errors (click)</summary><pre>".htmlspecialchars(implode("\n", $errors))."</pre></details>";
}
?>
