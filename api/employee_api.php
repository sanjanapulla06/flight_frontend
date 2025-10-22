<?php
// api/employee_api.php
require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['passport_no']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error'=>'Forbidden']); exit;
}

$action = $_REQUEST['action'] ?? 'list';

if ($action === 'list') {
    $res = $mysqli->query("SELECT e_id, fname, mname, lname, phone, jobtype, shift, salary, airport_name, position FROM employee");
    echo json_encode(['data'=>$res->fetch_all(MYSQLI_ASSOC)]); exit;
}

if ($action === 'create') {
    $e_id = $_POST['e_id']; $fname = $_POST['fname']; $lname = $_POST['lname']; $phone = $_POST['phone'];
    $job = $_POST['jobtype']; $shift = $_POST['shift']; $airport = $_POST['airport_name'];
    $stmt = $mysqli->prepare("INSERT INTO employee (e_id,fname,lname,phone,jobtype,shift,airport_name) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param('sssssss',$e_id,$fname,$lname,$phone,$job,$shift,$airport);
    if ($stmt->execute()) echo json_encode(['ok'=>true]); else echo json_encode(['error'=>$stmt->error]);
    exit;
}

if ($action === 'delete') {
    $id = $_POST['id'] ?? '';
    $stmt = $mysqli->prepare("DELETE FROM employee WHERE e_id = ?");
    $stmt->bind_param('s',$id); $stmt->execute();
    echo json_encode(['ok'=>true]); exit;
}

echo json_encode(['error'=>'unknown action']);
