<?php
require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['passport_no']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit;
}

$action = $_REQUEST['action'] ?? 'list';
if ($action === 'list') {
    $res = $mysqli->query("SELECT * FROM baggage_belt");
    echo json_encode($res->fetch_all(MYSQLI_ASSOC)); exit;
}
if ($action === 'assign') {
    $belt = $_POST['belt_no']; $airport = $_POST['airport_name']; $flight = $_POST['flight_id'];
    $stmt = $mysqli->prepare("REPLACE INTO baggage_belt (belt_no,airport_name,flight_id,status) VALUES (?,?,?,'assigned')");
    $stmt->bind_param('sss',$belt,$airport,$flight); $stmt->execute();
    echo json_encode(['ok'=>true]); exit;
}
echo json_encode(['error'=>'unknown']);
