<?php
// /FLIGHT_FRONTEND/api/get_recent_searches.php
require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

$passport_no = $_SESSION['passport_no'] ?? null;
if (!$passport_no) {
    echo json_encode([]); // not logged in -> nothing
    exit;
}

$stmt = $mysqli->prepare("SELECT id, source, destination, depart_date, created_at FROM recent_searches WHERE passport_no = ? ORDER BY created_at DESC LIMIT 10");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error'=>'DB prepare failed']);
    exit;
}
$stmt->bind_param('s', $passport_no);
$stmt->execute();
$res = $stmt->get_result();
$list = [];
while ($r = $res->fetch_assoc()) {
    $list[] = $r;
}
$stmt->close();
echo json_encode($list, JSON_UNESCAPED_UNICODE);
