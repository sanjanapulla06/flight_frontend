<?php
// /FLIGHT_FRONTEND/api/book_ticket.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/loyalty.php'; // 👈 include your loyalty function
if (session_status() === PHP_SESSION_NONE) session_start();

function respond($code, $data) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST')
    respond(405, ['ok'=>false,'error'=>'Invalid method']);

if (empty($_SESSION['passport_no']))
    respond(401, ['ok'=>false,'error'=>'Not logged in']);

$flight_id   = trim($_POST['flight_id'] ?? '');
$class       = trim($_POST['class'] ?? 'Economy');
$passengers  = $_POST['passengers'] ?? [];
$passport_no = $_SESSION['passport_no']; // the booker

if ($flight_id === '' || empty($passengers))
    respond(400, ['ok'=>false,'error'=>'Missing flight ID or passengers']);

// Detect correct flight table
$flight_table = 'flight';
if ($mysqli->query("SHOW TABLES LIKE 'flights'")->num_rows > 0) $flight_table = 'flights';

// Fetch flight details
$stmt = $mysqli->prepare("SELECT flight_id, COALESCE(base_price, price, 0) AS price FROM {$flight_table} WHERE flight_id = ? LIMIT 1");
$stmt->bind_param('s', $flight_id);
$stmt->execute();
$flight = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$flight) respond(404, ['ok'=>false,'error'=>'Flight not found']);

$total_price = 0;
$booking_ids = [];
$tickets = [];

try {
    $mysqli->begin_transaction();

    foreach ($passengers as $i => $p) {
        $name    = trim($p['name'] ?? '');
        $gender  = trim($p['gender'] ?? '');
        $seat_no = trim($p['seat_no'] ?? '');

        if ($name === '' || $seat_no === '') {
            throw new Exception("Missing data for passenger #" . ($i + 1));
        }

        // 🛫 Seat availability check
        $chk = $mysqli->prepare("SELECT booking_id FROM bookings WHERE flight_id = ? AND seat_no = ? AND status != 'cancelled' LIMIT 1");
        $chk->bind_param('ss', $flight_id, $seat_no);
        $chk->execute();
        $taken = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($taken) throw new Exception("Seat {$seat_no} already taken.");

        // ✈️ Insert booking
        $stmtB = $mysqli->prepare("
            INSERT INTO bookings (flight_id, passport_no, seat_no, class, booking_date, status)
            VALUES (?, ?, ?, ?, NOW(), 'booked')
        ");
        $stmtB->bind_param('ssss', $flight_id, $passport_no, $seat_no, $class);
        $stmtB->execute();
        $booking_id = $mysqli->insert_id;
        $stmtB->close();
        $booking_ids[] = $booking_id;

        // 🎟️ Insert ticket if table exists
        if ($mysqli->query("SHOW TABLES LIKE 'ticket'")->num_rows > 0) {
            $ticket_no = strtoupper(substr($flight_id, 0, 6)) . "-PAX" . ($i + 1) . "-" . $booking_id;
            $price_s = (string)$flight['price'];
            $stmtT = $mysqli->prepare("
                INSERT INTO ticket (ticket_no, flight_id, passport_no, price, seat_no, class)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmtT->bind_param('ssssss', $ticket_no, $flight_id, $passport_no, $price_s, $seat_no, $class);
            $stmtT->execute();
            $stmtT->close();
            $tickets[] = $ticket_no;
        }

        $total_price += floatval($flight['price']);
    }

    // ✅ All good → commit
    $mysqli->commit();

    // 🪙 Credit loyalty points to booker
    $ok_loyalty = credit_loyalty_points($mysqli, $passport_no, $total_price, "BOOK_{$flight_id}_" . time());

    respond(200, [
        'ok'             => true,
        'flight_id'      => $flight_id,
        'num_passengers' => count($passengers),
        'total_amount'   => $total_price,
        'loyalty_credit' => $ok_loyalty ? 'credited' : 'failed',
        'booking_ids'    => $booking_ids,
        'tickets'        => $tickets
    ]);

} catch (Throwable $e) {
    $mysqli->rollback();
    respond(500, ['ok'=>false,'error'=>$e->getMessage()]);
}
