<?php
// tools/recalc_prices.php
// Usage: php recalc_prices.php
require_once __DIR__ . '/../includes/db.php';
ini_set('display_errors',1);
error_reporting(E_ALL);

// config (tweak)
$days_window = 45;            // recalc flights up to N days from now
$min_multiplier = 0.8;
$max_multiplier = 5.0;
$now = new DateTimeImmutable('now');

// helper: detect columns
function col_exists($mysqli, $table, $col) {
    $res = $mysqli->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    return ($res && $res->num_rows > 0);
}

// detect flight table name
$flight_table = 'flight';
if ($mysqli->query("SHOW TABLES LIKE 'flight'")->num_rows === 0) {
    if ($mysqli->query("SHOW TABLES LIKE 'flights'")->num_rows) $flight_table = 'flights';
}
echo "Using flight table: {$flight_table}\n";

// required columns (adjust if your schema differs)
$has_base = col_exists($mysqli, $flight_table, 'base_price');
$has_price = col_exists($mysqli, $flight_table, 'price');
$has_dep = col_exists($mysqli, $flight_table, 'departure_time') || col_exists($mysqli, $flight_table, 'd_time');
$has_totseat = col_exists($mysqli, $flight_table, 'tot_seat') || col_exists($mysqli, $flight_table, 'total_seats');
$has_bookings = $mysqli->query("SHOW TABLES LIKE 'bookings'")->num_rows > 0;

if (!($has_price && $has_dep)) {
    echo "Required columns missing (price/departure_time). Aborting.\n";
    exit(1);
}

// get list of flights in window
$end = $now->modify("+{$days_window} days")->format('Y-m-d');
$start = $now->format('Y-m-d');

$q = "SELECT flight_id, " .
     ($has_base ? "base_price," : "NULL AS base_price,") .
     ($has_price ? "price," : "0 AS price,") .
     (col_exists($mysqli, $flight_table, 'departure_time') ? "departure_time" : "d_time") .
     ($has_totseat ? ", tot_seat" : ", NULL AS tot_seat") .
     " FROM {$flight_table} WHERE DATE(" . (col_exists($mysqli,$flight_table,'departure_time')?'departure_time':'d_time') . ") BETWEEN ? AND ?";

$stmt = $mysqli->prepare($q);
if (!$stmt) { echo "Prepare failed: " . $mysqli->error . PHP_EOL; exit(1); }
$stmt->bind_param('ss', $start, $end);
$stmt->execute();
$res = $stmt->get_result();

$updateStmt = $mysqli->prepare("UPDATE {$flight_table} SET price = ? WHERE flight_id = ?");
if (!$updateStmt) { echo "Prepare update failed: " . $mysqli->error . PHP_EOL; exit(1); }

$updated = 0;
while ($row = $res->fetch_assoc()) {
    $fid = $row['flight_id'];
    $base = $row['base_price'] !== null ? floatval($row['base_price']) : floatval($row['price']);
    $dep = $row['departure_time'] ?? $row['d_time'];
    $dep_dt = new DateTimeImmutable($dep);
    $interval = $now->diff($dep_dt);
    $hours_to_departure = max(0, ($dep_dt->getTimestamp() - $now->getTimestamp()) / 3600.0);

    // seats left: if bookings table exists, compute used seats for this flight
    $tot_seat = $row['tot_seat'] ? intval($row['tot_seat']) : 160;
    $seats_booked = 0;
    if ($has_bookings) {
        $chk = $mysqli->prepare("SELECT COUNT(*) AS cnt FROM bookings WHERE flight_id = ?");
        if ($chk) {
            $chk->bind_param('s', $fid);
            $chk->execute();
            $g = $chk->get_result()->fetch_assoc();
            $seats_booked = intval($g['cnt'] ?? 0);
            $chk->close();
        }
    }
    $seats_left = max(0, $tot_seat - $seats_booked);

    // demand factor: simple heuristic: number of searches for same route in last 24 hours (optional)
    $demand_factor = 1.0;
    if ($mysqli->query("SHOW TABLES LIKE 'search_history'")->num_rows) {
        $q2 = "SELECT COUNT(*) AS cnt FROM search_history WHERE created_at >= (NOW() - INTERVAL 1 DAY) AND source = (SELECT source_id FROM {$flight_table} WHERE flight_id = ?) LIMIT 1";
        // skip complex: leave at 1.0 unless you want to implement route-based counters.
    }

    // day factor (weekend)
    $dow = intval($dep_dt->format('N')); // 1..7
    $day_factor = ($dow >= 6) ? 1.12 : 1.0;

    // time factor: increases as departure gets close
    $time_factor = 1.0;
    if ($hours_to_departure <= 0) $time_factor = 1.6;
    else $time_factor = 1.0 + min(0.9, 0.5 * exp(-$hours_to_departure / 72.0));

    // seats factor
    $seats_factor = 1.0;
    if ($tot_seat > 0) {
        $scarcity = max(0, (100 - ($seats_left / max(1,$tot_seat) * 100)));
        $seats_factor = 1.0 + ($scarcity / 200.0); // 0..0.5 approx
    }

    $raw_price = $base * $time_factor * $seats_factor * $day_factor * $demand_factor;
    // clamp
    $min_price = $base * $min_multiplier;
    $max_price = $base * $max_multiplier;
    $new_price = max($min_price, min($raw_price, $max_price));
    // round
    $new_price = round($new_price, 2);

    // update
    $updateStmt->bind_param('ds', $new_price, $fid);
    if ($updateStmt->execute()) {
        $updated++;
    } else {
        echo "Update failed for {$fid}: " . $updateStmt->error . PHP_EOL;
    }
}

echo "Repriced {$updated} flights between {$start} and {$end}.\n";
$updateStmt->close();
$stmt->close();
$mysqli->close();
