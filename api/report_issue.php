<?php
require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

$flight_id = $_POST['flight_id'] ?? '';
$type = $_POST['issue_type'] ?? 'OTHER';
$msg = $_POST['message'] ?? '';
$reported_by = $_SESSION['passport_no'] ?? ($_POST['reported_by'] ?? 'system');

$stmt = $mysqli->prepare("INSERT INTO flight_issue (flight_id, reported_by, issue_type, message) VALUES (?, ?, ?, ?)");
$stmt->bind_param('ssss',$flight_id,$reported_by,$type,$msg);
if ($stmt->execute()) echo json_encode(['ok'=>true,'id'=>$mysqli->insert_id]); else echo json_encode(['error'=>$stmt->error]);
