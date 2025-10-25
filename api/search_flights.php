<?php
require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$src_raw = trim($_POST['source'] ?? '');
$dst_raw = trim($_POST['destination'] ?? '');
$flight_date = trim($_POST['flight_date'] ?? '');

if ($src_raw === '' || $dst_raw === '') {
    echo "<div class='alert alert-warning'>Please enter both source and destination.</div>";
    exit;
}

// Normalize search strings
$src_like = '%' . strtolower($src_raw) . '%';
$dst_like = '%' . strtolower($dst_raw) . '%';

// ✅ Main SQL: Join flights ↔ airport (source/destination) ↔ airline
$sql = "
SELECT 
  f.flight_id,
  f.flight_date,
  f.departure_time,
  f.arrival_time,
  f.price,
  f.base_price,
  a.airline_name,
  src.airport_code AS src_code,
  src.airport_name AS src_name,
  dst.airport_code AS dst_code,
  dst.airport_name AS dst_name
FROM flight f
JOIN airport src ON f.source_id = src.airport_id
JOIN airport dst ON f.destination_id = dst.airport_id
JOIN airline a ON f.airline_id = a.airline_id
WHERE (
    LOWER(src.airport_code) LIKE ?
    OR LOWER(src.airport_name) LIKE ?
)
AND (
    LOWER(dst.airport_code) LIKE ?
    OR LOWER(dst.airport_name) LIKE ?
)
";

// Optional date filter
if ($flight_date !== '') {
    $sql .= " AND f.flight_date = ?";
}

// Order by departure time
$sql .= " ORDER BY f.departure_time ASC LIMIT 200";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    echo "<div class='alert alert-danger'>Prepare failed: " . htmlspecialchars($mysqli->error) . "</div>";
    exit;
}

if ($flight_date !== '') {
    $stmt->bind_param('sssss', $src_like, $src_like, $dst_like, $dst_like, $flight_date);
} else {
    $stmt->bind_param('ssss', $src_like, $src_like, $dst_like, $dst_like);
}

$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo "<div class='alert alert-warning'>No flights found for <strong>" . htmlspecialchars($src_raw) . "</strong> → <strong>" . htmlspecialchars($dst_raw) . "</strong>.</div>";
    exit;
}

// ✅ Show matching results
while ($row = $res->fetch_assoc()) {
    $fid = htmlspecialchars($row['flight_id']);
    $src_code = htmlspecialchars($row['src_code']);
    $dst_code = htmlspecialchars($row['dst_code']);
    $airline = htmlspecialchars($row['airline_name']);
    $price = htmlspecialchars($row['price']);
    $dep_time = date('M d, H:i', strtotime($row['departure_time']));
    $arr_time = date('H:i', strtotime($row['arrival_time']));

    echo "
    <div class='card mb-3'>
      <div class='card-body d-flex justify-content-between align-items-center'>
        <div>
          <h5 class='mb-1'>{$src_code} → {$dst_code} | {$airline}</h5>
          <small class='text-muted'>
            Departure: {$dep_time} • Arrival: {$arr_time} • ₹{$price}
          </small>
        </div>
        <div>
          <a class='btn btn-success' href='/FLIGHT_FRONTEND/book_flight.php?flight_id={$fid}'>Book</a>
        </div>
      </div>
    </div>";
}

$stmt->close();
?>
