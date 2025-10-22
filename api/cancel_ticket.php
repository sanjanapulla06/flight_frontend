<?php
session_start();
if (!isset($_SESSION['passport_no'])) {
    $return = urlencode($_SERVER['REQUEST_URI']);
    header("Location: /FLIGHT_FRONTEND/auth/login.php?return=$return");
    exit;
}
?>

<?php
// api/cancel_ticket.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../includes/db.php';

$ticket_no = trim($_POST['ticket_no'] ?? '');
if(!$ticket_no){ echo json_encode(['error'=>'missing ticket_no']); exit; }

// simple cancellation: delete ticket (or you can change this to an update if you add a cancellation column)
if($stmt = $mysqli->prepare("DELETE FROM ticket WHERE ticket_no = ?")){
    $stmt->bind_param('s', $ticket_no);
    $stmt->execute();
    if($stmt->affected_rows > 0){
        echo json_encode(['ok'=>true]);
    }else{
        echo json_encode(['error'=>'not found']);
    }
    $stmt->close();
}else{
    echo json_encode(['error'=>'prepare failed']);
}
