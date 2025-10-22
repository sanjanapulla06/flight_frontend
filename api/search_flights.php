<?php
// api/search_flights.php
require_once __DIR__ . '/../includes/db.php'; // $mysqli
if (session_status() === PHP_SESSION_NONE) session_start();

$src_raw = trim($_POST['source'] ?? '');
$dst_raw = trim($_POST['destination'] ?? '');
$flight_date = trim($_POST['flight_date'] ?? '');
$non_stop = isset($_POST['non_stop']) && $_POST['non_stop'] == '1';
$airlines = $_POST['airlines'] ?? []; // ignored in this simplified version
$sort = trim($_POST['sort'] ?? ''); // 'price', 'duration', 'departure'

// require source & destination
if ($src_raw === '' || $dst_raw === '') {
    echo "<div class='alert alert-warning'>Please enter both source and destination.</div>";
    exit;
}

// ------------------- ADDED: record this search in search_history -------------------
/*
  This inserts a row into `search_history` (create table SQL provided below in comments).
  If you track logged-in users, store user_id in $_SESSION['user_id'].
  Keeps the insert silent on failure (error logged).
*/
$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
$flight_date_param = ($flight_date === '') ? null : $flight_date;
$non_stop_param = $non_stop ? 1 : 0;
$insert_sql = "INSERT INTO search_history (user_id, source, destination, flight_date, non_stop, sort)
               VALUES (?, ?, ?, ?, ?, ?)";
$ins_stmt = $mysqli->prepare($insert_sql);
if ($ins_stmt) {
    // bind params: i - user_id (can be null), s - source, s - destination, s - flight_date (can be null), i - non_stop, s - sort
    // to allow NULL user_id / flight_date, we pass actual PHP nulls for those variables when appropriate
    $bind_user_id = $user_id; // may be null
    $bind_src = $src_raw;
    $bind_dst = $dst_raw;
    $bind_flight_date = $flight_date_param; // may be null
    $bind_non_stop = $non_stop_param;
    $bind_sort = $sort;
    // Use 'is s s s i s' -> 'ississ'
    $ins_stmt->bind_param('ississ', $bind_user_id, $bind_src, $bind_dst, $bind_flight_date, $bind_non_stop, $bind_sort);
    $ins_stmt->execute();
    // ignore errors in UI; log for debugging
    if ($ins_stmt->errno) {
        error_log("search_history insert failed: " . $ins_stmt->error);
    }
    $ins_stmt->close();
} else {
    error_log("search_history prepare failed: " . $mysqli->error);
}
// -------------------------------------------------------------------------------

// normalize for LIKE searches
$src_like = '%' . mb_strtolower($src_raw) . '%';
$dst_like = '%' . mb_strtolower($dst_raw) . '%';

$where = [];
$params = [];
$types = '';

// match source OR airport.city OR airport_code
$where[] = "(
    LOWER(f.source) LIKE ?
    OR LOWER(src.airport_name) LIKE ?
    OR LOWER(src.city) LIKE ?
    OR LOWER(COALESCE(src.airport_code,'')) LIKE ?
)";
$params = array_merge($params, [$src_like, $src_like, $src_like, $src_like]);
$types .= str_repeat('s', 4);

// destination
$where[] = "(
    LOWER(f.destination) LIKE ?
    OR LOWER(dst.airport_name) LIKE ?
    OR LOWER(dst.city) LIKE ?
    OR LOWER(COALESCE(dst.airport_code,'')) LIKE ?
)";
$params = array_merge($params, [$dst_like, $dst_like, $dst_like, $dst_like]);
$types .= str_repeat('s', 4);

// optional: date filter (compare date portion of departure_time)
if ($flight_date !== '') {
    $where[] = "DATE(f.departure_time) = ?";
    $params[] = $flight_date;
    $types .= 's';
}

// non-stop filter
if ($non_stop) {
    $where[] = "(f.no_of_stops IS NULL OR f.no_of_stops = 0)";
}

// NOTE: airline filtering disabled in this quick fix if your schema has no airline_id/airline column

// Build final SQL (no airline join)
$sql = "SELECT f.flight_id,
               f.flight_code,
               f.source AS f_source,
               f.destination AS f_destination,
               f.departure_time,
               f.arrival_time,
               COALESCE(f.base_price, f.price, 0) AS base_price,
               f.price
        FROM flights f
        LEFT JOIN airport src ON src.airport_name = f.source
        LEFT JOIN airport dst ON dst.airport_name = f.destination
        WHERE " . implode(' AND ', $where);

// Sorting
$order = " ORDER BY f.departure_time ASC";
if ($sort === 'price') $order = " ORDER BY base_price ASC";
elseif ($sort === 'duration') $order = " ORDER BY (TIMESTAMPDIFF(MINUTE, f.departure_time, f.arrival_time)) ASC";
elseif ($sort === 'departure') $order = " ORDER BY f.departure_time ASC";

$sql .= $order . " LIMIT 200";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    echo "<div class='alert alert-danger'>DB prepare error: " . htmlspecialchars($mysqli->error) . "</div>";
    exit;
}

// bind params dynamically
if (!empty($params)) {
    $bind_names = [];
    $bind_names[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bind_name = 'bind' . $i;
        $$bind_name = $params[$i];
        $bind_names[] = &$$bind_name;
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_names);
}

$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo "<div class='alert alert-warning'>No flights found for <strong>" . htmlspecialchars($src_raw) . "</strong> → <strong>" . htmlspecialchars($dst_raw) . "</strong>.</div>";
    $stmt->close();
    exit;
}

// render cards — show date + time
while ($row = $res->fetch_assoc()) {
    $fid = htmlspecialchars($row['flight_id']);
    $source = htmlspecialchars($row['f_source'] ?? $row['source'] ?? '');
    $dest = htmlspecialchars($row['f_destination'] ?? $row['destination'] ?? '');

    // raw datetimes from DB
    $dt_raw = $row['departure_time'] ?? '';
    $at_raw = $row['arrival_time'] ?? '';

    // formatted date + time
    $dt_date = $dt_raw ? date('M d, Y', strtotime($dt_raw)) : '';
    $dt_time = $dt_raw ? date('H:i', strtotime($dt_raw)) : '';
    $at_date = $at_raw ? date('M d, Y', strtotime($at_raw)) : '';
    $at_time = $at_raw ? date('H:i', strtotime($at_raw)) : '';

    $airline = htmlspecialchars($row['airline_name'] ?? '');
    $price = htmlspecialchars($row['base_price'] ?? $row['price'] ?? '0');

    echo "
    <div class='card mb-3'>
      <div class='card-body d-flex justify-content-between align-items-center'>
        <div>
          <h5 class='mb-1'>{$fid} — {$source} → {$dest}</h5>
          <div class='text-muted'>
            <strong>Departure:</strong> {$dt_date} {$dt_time} &nbsp; 
            <strong>Arrival:</strong> {$at_date} {$at_time} &nbsp; 
            <strong>Airline:</strong> {$airline} &nbsp; 
            <strong>₹{$price}</strong>
          </div>
        </div>
        <div>
          <a class='btn btn-success book-btn' href='/FLIGHT_FRONTEND/book_flight.php?flight_id={$fid}'>Book</a>
        </div>
      </div>
    </div>";
}
