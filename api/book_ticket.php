<?php
// api/book_ticket.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
if (function_exists('safe_start_session')) safe_start_session();
else if (session_status() === PHP_SESSION_NONE) session_start();

// must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

// must be logged in
if (empty($_SESSION['passport_no'])) {
    http_response_code(401);
    echo json_encode(['error' => 'You must be logged in to book a flight.']);
    exit;
}

// read & sanitize POST
$flight_id = intval($_POST['flight_id'] ?? 0);
$passport_no = trim($_POST['passport_no'] ?? '');
$name = trim($_POST['name'] ?? '');
$seat_no = trim($_POST['seat_no'] ?? '');
$class = trim($_POST['class'] ?? 'Economy');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');
$gender = trim($_POST['gender'] ?? '');
$dob = trim($_POST['dob'] ?? '');

// basic validation
if (!$flight_id || !$passport_no || !$name || !$seat_no) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required booking details.']);
    exit;
}

// verify flight exists
$stmt = $mysqli->prepare("SELECT flight_code, source, destination, COALESCE(base_price, price, 0) AS price FROM flights WHERE flight_id = ? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error: ' . $mysqli->error]);
    exit;
}
$stmt->bind_param('i', $flight_id);
$stmt->execute();
$flight = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$flight) {
    http_response_code(404);
    echo json_encode(['error' => 'Flight not found.']);
    exit;
}

// optional: update passenger profile fields in passenger table (non-destructive)
// safer to attempt update only if fields provided
$updateFields = [];
$updateTypes = '';
$updateVals = [];
if ($email !== '') { $updateFields[] = "email = ?"; $updateTypes .= 's'; $updateVals[] = $email; }
if ($phone !== '') { $updateFields[] = "phone = ?"; $updateTypes .= 's'; $updateVals[] = $phone; }
if ($address !== '') { $updateFields[] = "address = ?"; $updateTypes .= 's'; $updateVals[] = $address; }
if ($gender !== '') { $updateFields[] = "gender = ?"; $updateTypes .= 's'; $updateVals[] = $gender; }
if ($dob !== '') { $updateFields[] = "dob = ?"; $updateTypes .= 's'; $updateVals[] = $dob; }

if (!empty($updateFields)) {
    $sqlUpd = "UPDATE passenger SET " . implode(',', $updateFields) . " WHERE passport_no = ?";
    $updateVals[] = $passport_no;
    $updateTypes .= 's';
    $updStmt = $mysqli->prepare($sqlUpd);
    if ($updStmt) {
        // bind dynamically
        $bindNames = array_merge([$updateTypes], $updateVals);
        $refs = [];
        foreach ($bindNames as $i => $v) {
            $refs[$i] = &$bindNames[$i];
        }
        call_user_func_array([$updStmt, 'bind_param'], $refs);
        $updStmt->execute();
        $updStmt->close();
    }
}

// prevent duplicate booking for same flight & passport
$chk = $mysqli->prepare("SELECT booking_id FROM bookings WHERE flight_id = ? AND passport_no = ? LIMIT 1");
$chk->bind_param('is', $flight_id, $passport_no);
$chk->execute();
$exists = $chk->get_result()->fetch_assoc();
$chk->close();
if ($exists) {
    http_response_code(409);
    echo json_encode(['error' => 'You already have a booking for this flight.']);
    exit;
}

// insert booking into bookings table (ensure table exists)
$ins = $mysqli->prepare("INSERT INTO bookings (flight_id, passport_no, seat_no, class, booking_date, status) VALUES (?, ?, ?, ?, NOW(), 'booked')");
if (!$ins) {
    // if bookings table lacks columns seat_no/class, try fallback insert without them
    // fallback: bookings (flight_id, passport_no, booking_date, status)
    $ins2 = $mysqli->prepare("INSERT INTO bookings (flight_id, passport_no, booking_date, status) VALUES (?, ?, NOW(), 'booked')");
    if ($ins2) {
        $ins2->bind_param('is', $flight_id, $passport_no);
        $ok2 = $ins2->execute();
        if (!$ok2) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to insert booking: ' . $ins2->error]);
            exit;
        }
        $ins2->close();
        $booking_id = $mysqli->insert_id;
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'DB error (bookings insert): ' . $mysqli->error]);
        exit;
    }
} else {
    $ins->bind_param('isss', $flight_id, $passport_no, $seat_no, $class);
    $ok = $ins->execute();
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to insert booking: ' . $ins->error]);
        exit;
    }
    $booking_id = $mysqli->insert_id;
    $ins->close();
}

// generate a ticket_no (simple deterministic format)
$ticket_no = strtoupper(substr($flight['flight_code'], 0, 6)) . '-' . $passport_no . '-' . $booking_id;

// success response
echo json_encode([
    'ok' => true,
    'ticket_no' => $ticket_no,
    'seat_no' => $seat_no,
    'class' => $class,
    'price' => $flight['price'],
    'message' => 'Booking confirmed'
]);
exit;
