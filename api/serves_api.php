<?php
// api/serves_api.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../includes/db.php';

$e_id = trim($_POST['e_id'] ?? '');
$flight_id = trim($_POST['flight_id'] ?? '');
$role = trim($_POST['role'] ?? null);

if(!$e_id || !$flight_id){ echo json_encode(['error'=>'missing']); exit; }

if($stmt = $mysqli->prepare("INSERT INTO serves (e_id, flight_id, role) VALUES (?, ?, ?)")){
    $stmt->bind_param('sss', $e_id, $flight_id, $role);
    $ok = $stmt->execute();
    if($ok) echo json_encode(['ok'=>true]);
    else echo json_encode(['error'=>$stmt->error]);
    $stmt->close();
}else echo json_encode(['error'=>'prepare failed']);
