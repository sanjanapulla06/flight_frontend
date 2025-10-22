<?php
require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['passport_no']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit;
}

$action = $_REQUEST['action'] ?? 'list';
if ($action === 'list') {
    $res = $mysqli->query("SELECT * FROM check_in");
    echo json_encode($res->fetch_all(MYSQLI_ASSOC)); exit;
}
if ($action === 'assign') {
    $counter = $_POST['counter_no']; $airport = $_POST['airport_name']; $airline = $_POST['airline_id'];
    $stmt = $mysqli->prepare("REPLACE INTO check_in (counter_no,airport_name,airline_id) VALUES (?,?,?)");
    $stmt->bind_param('ssi',$counter,$airport,$airline); $stmt->execute();
    echo json_encode(['ok'=>true]); exit;
}
echo json_encode(['error'=>'unknown']);
