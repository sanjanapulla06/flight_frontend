<?php
// api/book_ticket.php  (robust debug-enabled transactional implementation)
// Safe for local dev. Remove dev logs in production.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
if (function_exists('safe_start_session')) safe_start_session();
else if (session_status() === PHP_SESSION_NONE) session_start();

$debug_log = __DIR__ . '/../tools/book_ticket_debug.log';
function dev_log($msg) {
    global $debug_log;
    @file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}
function respond($code, $payload) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(400, ['ok'=>false,'error'=>'Invalid request method.']);
    }

    if (empty($_SESSION['passport_no'])) {
        respond(401, ['ok'=>false,'error'=>'Not logged in.']);
    }

    // read & sanitize inputs (flight_id is string)
    $flight_id   = trim((string)($_POST['flight_id'] ?? ''));
    $passport_no = trim((string)($_SESSION['passport_no'] ?? ''));
    $name        = trim((string)($_POST['name'] ?? ''));
    $seat_no     = trim((string)($_POST['seat_no'] ?? ''));
    $class       = trim((string)($_POST['class'] ?? 'Economy'));
    $email       = trim((string)($_POST['email'] ?? ''));
    $phone       = trim((string)($_POST['phone'] ?? ''));
    $address     = trim((string)($_POST['address'] ?? ''));
    $gender      = trim((string)($_POST['gender'] ?? ''));
    $dob         = trim((string)($_POST['dob'] ?? ''));

    if ($flight_id === '' || $passport_no === '' || $name === '' || $seat_no === '') {
        respond(400, ['ok'=>false,'error'=>'Missing required booking details.','detail'=>['flight_id'=>$flight_id,'passport'=>$passport_no,'name'=>$name,'seat'=>$seat_no]]);
    }

    // detect flight table name
    $flight_table = 'flight';
    $check = $mysqli->query("SHOW TABLES LIKE 'flight'");
    if (!$check || $check->num_rows === 0) {
        $check2 = $mysqli->query("SHOW TABLES LIKE 'flights'");
        if ($check2 && $check2->num_rows > 0) $flight_table = 'flights';
    }

    // verify flight exists (string id)
    $stmt = $mysqli->prepare("SELECT f.flight_id, COALESCE(f.base_price, f.price, 0) AS price FROM {$flight_table} f WHERE f.flight_id = ? LIMIT 1");
    if (!$stmt) {
        dev_log("Prepare flight lookup failed: " . $mysqli->error);
        respond(500, ['ok'=>false,'error'=>'DB prepare error (flight lookup)','detail'=>$mysqli->error]);
    }
    $stmt->bind_param('s', $flight_id);
    if (!$stmt->execute()) {
        dev_log("Execute flight lookup failed: " . $stmt->error);
        respond(500, ['ok'=>false,'error'=>'DB execute error (flight lookup)','detail'=>$stmt->error]);
    }
    $flight = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$flight) {
        respond(404, ['ok'=>false,'error'=>'Flight not found.','detail'=>['flight_id'=>$flight_id]]);
    }

    // begin transaction
    $mysqli->begin_transaction();

    // update passenger contact fields if provided (non-destructive)
    $update_fields = [];
    $update_types = '';
    $update_vals = [];
    if ($email !== '') { $update_fields[] = "email = ?"; $update_types .= 's'; $update_vals[] = $email; }
    if ($phone !== '') { $update_fields[] = "phone = ?"; $update_types .= 's'; $update_vals[] = $phone; }
    if ($address !== '') { $update_fields[] = "address = ?"; $update_types .= 's'; $update_vals[] = $address; }
    if ($gender !== '') { $update_fields[] = "gender = ?"; $update_types .= 's'; $update_vals[] = $gender; }
    if ($dob !== '') { $update_fields[] = "dob = ?"; $update_types .= 's'; $update_vals[] = $dob; }

    if (!empty($update_fields)) {
        $sqlUpd = "UPDATE passenger SET " . implode(',', $update_fields) . " WHERE passport_no = ?";
        $update_vals[] = $passport_no;
        $update_types .= 's';
        $updStmt = $mysqli->prepare($sqlUpd);
        if ($updStmt) {
            // dynamic bind
            $bind_names = array_merge([$update_types], $update_vals);
            $refs = [];
            foreach ($bind_names as $i => $v) $refs[$i] = &$bind_names[$i];
            call_user_func_array([$updStmt, 'bind_param'], $refs);
            if (!$updStmt->execute()) {
                dev_log("Passenger update failed: " . $updStmt->error);
                // not fatal — continue but log
            }
            $updStmt->close();
        } else {
            dev_log("Prepare passenger update failed: " . $mysqli->error);
        }
    }

    // ensure bookings table exists
    $checkB = $mysqli->query("SHOW TABLES LIKE 'bookings'");
    if (!$checkB || $checkB->num_rows === 0) {
        $mysqli->rollback();
        dev_log("bookings table missing");
        respond(500, ['ok'=>false,'error'=>'bookings table missing on DB.']);
    }

    // ---------- NEW: seat / duplicate logic ----------
    // 1) Check if requested seat is already taken by ANY passenger (active booking)
    $chkSeat = $mysqli->prepare("SELECT booking_id, passport_no, status FROM bookings WHERE flight_id = ? AND seat_no = ? LIMIT 1");
    if (!$chkSeat) {
        dev_log("Prepare seat-check failed: " . $mysqli->error);
        // fallback: continue to duplicate/passenger checks but risk seat collisions
    } else {
        $chkSeat->bind_param('ss', $flight_id, $seat_no);
        if (!$chkSeat->execute()) {
            dev_log("Execute seat-check failed: " . $chkSeat->error);
        } else {
            $seatRow = $chkSeat->get_result()->fetch_assoc();
            if ($seatRow && (!isset($seatRow['status']) || strtolower($seatRow['status']) !== 'cancelled')) {
                // if seat is taken by someone else (or by same passport) we handle next
                if ($seatRow['passport_no'] !== $passport_no) {
                    $mysqli->rollback();
                    respond(409, ['ok'=>false,'error'=>'Seat already taken by another passenger on this flight.']);
                }
                // if seatRow belongs to same passport_no we'll detect duplicate below
            }
        }
        $chkSeat->close();
    }

    // 2) Fetch any existing bookings for this passport+flight (to detect exact duplicate seat by same passport)
    $chk = $mysqli->prepare("SELECT booking_id, seat_no, status FROM bookings WHERE flight_id = ? AND passport_no = ?");
    if (!$chk) {
        $mysqli->rollback();
        dev_log("Prepare dup check failed: " . $mysqli->error);
        respond(500, ['ok'=>false,'error'=>'DB prepare error (dup check)','detail'=>$mysqli->error]);
    }
    $chk->bind_param('ss', $flight_id, $passport_no);
    if (!$chk->execute()) {
        $mysqli->rollback();
        dev_log("Execute dup check failed: " . $chk->error);
        respond(500, ['ok'=>false,'error'=>'DB execute error (dup check)','detail'=>$chk->error]);
    }
    $existingRows = $chk->get_result()->fetch_all(MYSQLI_ASSOC);
    $chk->close();

    if (!empty($existingRows)) {
        // if an existing booking for same passport exists with same seat and not cancelled => reject
        foreach ($existingRows as $r) {
            $status = isset($r['status']) ? strtolower($r['status']) : '';
            $existingSeat = $r['seat_no'] ?? '';
            if ($existingSeat === $seat_no && $status !== 'cancelled') {
                $mysqli->rollback();
                respond(409, ['ok'=>false,'error'=>'You already have this seat booked on this flight.']);
            }
        }
        // otherwise passenger has other booking(s) on same flight but different seat(s) -> allow new booking
    }
    // ---------- end seat / duplicate logic ----------

    // detect if bookings table has seat_no & class columns
    $colsRes = $mysqli->query("SHOW COLUMNS FROM bookings");
    $cols = [];
    if ($colsRes) {
        while ($r = $colsRes->fetch_assoc()) $cols[] = $r['Field'];
    }
    $has_seat = in_array('seat_no', $cols);
    $has_class = in_array('class', $cols);

    // prepare insert depending on columns
    if ($has_seat && $has_class) {
        $ins = $mysqli->prepare("INSERT INTO bookings (flight_id, passport_no, seat_no, class, booking_date, status) VALUES (?, ?, ?, ?, NOW(), 'booked')");
        if (!$ins) {
            $mysqli->rollback();
            dev_log("Prepare bookings insert failed: " . $mysqli->error);
            respond(500, ['ok'=>false,'error'=>'DB prepare error (insert booking)','detail'=>$mysqli->error]);
        }
        $ins->bind_param('ssss', $flight_id, $passport_no, $seat_no, $class);
        if (!$ins->execute()) {
            $mysqli->rollback();
            dev_log("Execute bookings insert failed: " . $ins->error);
            respond(500, ['ok'=>false,'error'=>'DB execute error (insert booking)','detail'=>$ins->error]);
        }
        $booking_id = $mysqli->insert_id;
        $ins->close();
    } else {
        // fallback: minimal insert if schema different
        $ins2 = $mysqli->prepare("INSERT INTO bookings (flight_id, passport_no, booking_date, status) VALUES (?, ?, NOW(), 'booked')");
        if (!$ins2) {
            $mysqli->rollback();
            dev_log("Fallback bookings prepare failed: " . $mysqli->error);
            respond(500, ['ok'=>false,'error'=>'DB prepare error (fallback insert)','detail'=>$mysqli->error]);
        }
        $ins2->bind_param('ss', $flight_id, $passport_no);
        if (!$ins2->execute()) {
            $mysqli->rollback();
            dev_log("Fallback bookings execute failed: " . $ins2->error);
            respond(500, ['ok'=>false,'error'=>'DB execute error (fallback insert)','detail'=>$ins2->error]);
        }
        $booking_id = $mysqli->insert_id;
        $ins2->close();
    }

    // optional: insert into ticket if table exists and columns present
    $ticket_no = strtoupper(preg_replace('/[^A-Z0-9\-]/','', substr($flight_id,0,8))) . '-' . $passport_no . '-' . $booking_id;
    $ticketExists = $mysqli->query("SHOW TABLES LIKE 'ticket'")->num_rows > 0;
    if ($ticketExists) {
        $tstmt = $mysqli->prepare("INSERT INTO ticket (ticket_no, flight_id, passport_no, price, seat_no, class) VALUES (?, ?, ?, ?, ?, ?)");
        if ($tstmt) {
            // price might be decimal; bind as string to be safe
            $price_s = (string)$flight['price'];
            // seat_no/class may not be in ticket schema — hope they exist; if execute fails we log and continue
            $tstmt->bind_param('ssssss', $ticket_no, $flight_id, $passport_no, $price_s, $seat_no, $class);
            if (!$tstmt->execute()) {
                dev_log("Ticket insert failed: " . $tstmt->error);
                // don't fail booking because of ticket problem
            }
            $tstmt->close();
        } else {
            dev_log("Ticket prepare failed: " . $mysqli->error);
        }
    }

    // commit transaction
    $mysqli->commit();

    $resp = ['ok'=>true,'ticket_no'=>$ticket_no,'booking_id'=>$booking_id,'price'=>(float)$flight['price'],'seat_no'=>$seat_no,'class'=>$class];
    dev_log("Booking success: " . json_encode($resp));
    respond(200, $resp);

} catch (Throwable $e) {
    // attempt rollback and report
    if ($mysqli->errno) {
        @$mysqli->rollback();
    }
    dev_log("Exception in booking: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    respond(500, ['ok'=>false,'error'=>'Internal server error','detail'=>$e->getMessage()]);
}
