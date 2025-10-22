<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../includes/db.php';

$flight_id = trim($_GET['flight_id'] ?? '');
if (!$flight_id) {
    echo json_encode(['error' => 'Missing flight_id']);
    exit;
}

$sql = "SELECT seat_no FROM ticket WHERE flight_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('s', $flight_id);
$stmt->execute();
$result = $stmt->get_result();

$booked = [];
while ($row = $result->fetch_assoc()) {
    $booked[] = $row['seat_no'];
}

echo json_encode(['flight_id' => $flight_id, 'booked' => $booked]);
?>
