<?php
// api/view_ticket.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../includes/db.php';
$ticket_no = trim($_GET['ticket_no'] ?? '');
if(!$ticket_no){ echo json_encode([]); exit; }

$sql = "SELECT t.ticket_no, t.seat_no, t.class, t.price, t.d_time, t.a_time,
               t.source, t.destination, t.flight_id,
               p.name AS passenger_name, p.passport_no,
               f.airline_id, a.airline_name
        FROM ticket t
        LEFT JOIN passenger p ON t.passport_no = p.passport_no
        LEFT JOIN flight f ON t.flight_id = f.flight_id
        LEFT JOIN airline a ON f.airline_id = a.airline_id
        WHERE t.ticket_no = ? LIMIT 1";

if($stmt = $mysqli->prepare($sql)){
    $stmt->bind_param('s', $ticket_no);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    echo json_encode($row ?: []);
    $stmt->close();
}else{
    echo json_encode([]);
}
