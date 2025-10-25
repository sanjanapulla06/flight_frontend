<?php
// /FLIGHT_FRONTEND/cancel_booking.php (verbose debug version)
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
safe_start_session();

$DEBUG_LOG = __DIR__ . '/tools/cancel_debug.log';
function dbg($m) {
    global $DEBUG_LOG;
    @file_put_contents($DEBUG_LOG, date('[Y-m-d H:i:s] ') . $m . PHP_EOL, FILE_APPEND);
}

if (empty($_SESSION['passport_no'])) {
    header('Location: /FLIGHT_FRONTEND/auth/login.php');
    exit;
}

$passport = $_SESSION['passport_no'];
$booking_id = isset($_POST['booking_id']) ? $_POST['booking_id'] : null;

if (!$booking_id) {
    $_SESSION['flash_error'] = "Invalid booking id.";
    header('Location: /FLIGHT_FRONTEND/my_bookings.php');
    exit;
}

if (!ctype_digit((string)$booking_id)) {
    $_SESSION['flash_error'] = "Invalid booking id format.";
    header('Location: /FLIGHT_FRONTEND/my_bookings.php');
    exit;
}
$booking_id = intval($booking_id);

// detect flight table name (flight or flights)
$flight_table = 'flight';
$chk = $mysqli->query("SHOW TABLES LIKE 'flight'");
if (!$chk || $chk->num_rows === 0) {
    $chk2 = $mysqli->query("SHOW TABLES LIKE 'flights'");
    if ($chk2 && $chk2->num_rows > 0) $flight_table = 'flights';
}

try {
    // Confirm booking exists and belongs to this passport
    $sql = "
      SELECT b.booking_id AS booking_id,
             b.status AS booking_status,
             COALESCE(f.base_price, f.price, 0) AS price,
             b.passport_no AS passport_no
      FROM bookings b
      JOIN {$flight_table} f ON b.flight_id = f.flight_id
      WHERE b.booking_id = ? AND b.passport_no = ?
      LIMIT 1
    ";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        $err = "Prepare failed (lookup): " . $mysqli->error;
        dbg($err);
        throw new Exception($err);
    }
    $stmt->bind_param('is', $booking_id, $passport);
    if (!$stmt->execute()) {
        $err = "Execute failed (lookup): " . $stmt->error;
        dbg($err);
        $stmt->close();
        throw new Exception($err);
    }
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        $_SESSION['flash_error'] = "Booking not found or you don't have permission.";
        header('Location: /FLIGHT_FRONTEND/my_bookings.php');
        exit;
    }

    $curr_status = $booking['booking_status'] ?? null;
    if ($curr_status !== null && strtolower($curr_status) === 'cancelled') {
        $_SESSION['flash_message'] = "This booking is already cancelled.";
        header('Location: /FLIGHT_FRONTEND/my_bookings.php');
        exit;
    }

    // begin transaction
    if (!$mysqli->begin_transaction()) {
        $err = "Failed to start transaction: " . $mysqli->error;
        dbg($err);
        throw new Exception($err);
    }

    // get bookings columns
    $bookCols = [];
    $colsRes = $mysqli->query("SHOW COLUMNS FROM bookings");
    if ($colsRes) while ($r = $colsRes->fetch_assoc()) $bookCols[] = $r['Field'];

    // build update statement dynamically
    $updates = [];
    $types = '';
    $params = [];

    if (in_array('status', $bookCols)) {
        $updates[] = "status = ?";
        $types .= 's';
        $params[] = 'cancelled';
    }
    if (in_array('cancelled_at', $bookCols)) {
        $updates[] = "cancelled_at = NOW()";
    }
    if (in_array('cancelled_by', $bookCols)) {
        $updates[] = "cancelled_by = ?";
        $types .= 's';
        $params[] = $_SESSION['name'] ?? $passport;
    }

    if (empty($updates)) {
        // fallback update
        $updSql = "UPDATE bookings SET status = 'cancelled' WHERE booking_id = ? AND passport_no = ?";
        $updStmt = $mysqli->prepare($updSql);
        if (!$updStmt) {
            $err = "Prepare failed (fallback update): " . $mysqli->error;
            dbg($err);
            throw new Exception($err);
        }
        $updStmt->bind_param('is', $booking_id, $passport);
        if (!$updStmt->execute()) {
            $err = "Execute failed (fallback update): " . $updStmt->error;
            dbg($err);
            $updStmt->close();
            throw new Exception($err);
        }
        $updStmt->close();
    } else {
        $setSql = implode(', ', $updates);
        $updSql = "UPDATE bookings SET {$setSql} WHERE booking_id = ? AND passport_no = ?";
        $updStmt = $mysqli->prepare($updSql);
        if (!$updStmt) {
            $err = "Prepare failed (update): " . $mysqli->error;
            dbg($err);
            throw new Exception($err);
        }

        // prepare bind args
        $bindArgs = [];
        if ($types !== '') {
            $bindTypes = $types . 'i' . 's'; // dynamic types + booking_id (i) + passport (s)
            $bindArgs[] = $bindTypes;
            foreach ($params as $p) $bindArgs[] = $p;
            $bindArgs[] = $booking_id;
            $bindArgs[] = $passport;
        } else {
            $bindArgs[] = 'is';
            $bindArgs[] = $booking_id;
            $bindArgs[] = $passport;
        }

        // convert to references
        $refs = [];
        foreach ($bindArgs as $i => $v) $refs[$i] = &$bindArgs[$i];
        call_user_func_array([$updStmt, 'bind_param'], $refs);

        if (!$updStmt->execute()) {
            $err = "Execute failed (update): " . $updStmt->error;
            dbg($err);
            $updStmt->close();
            throw new Exception($err);
        }
        $updStmt->close();
    }

    // optionally create refund if table exists
    $refund_amount = floatval($booking['price']) * 0.9;
    $refundExists = ($mysqli->query("SHOW TABLES LIKE 'refunds'")->num_rows > 0);
    if ($refundExists) {
        $rstmt = $mysqli->prepare("INSERT INTO refunds (booking_id, passport_no, amount, status, processed_at) VALUES (?, ?, ?, 'completed', NOW())");
        if ($rstmt) {
            $rstmt->bind_param('isd', $booking_id, $passport, $refund_amount);
            if (!$rstmt->execute()) {
                dbg("Refund insert failed: " . $rstmt->error);
                // don't abort entire transaction for refund failure; just log
            } else {
                $rid = $rstmt->insert_id;
                if (in_array('refund_id', $bookCols)) {
                    $link = $mysqli->prepare("UPDATE bookings SET refund_id = ? WHERE booking_id = ?");
                    if ($link) {
                        $link->bind_param('ii', $rid, $booking_id);
                        $link->execute();
                        $link->close();
                    } else {
                        dbg("Failed to prepare refund link update: " . $mysqli->error);
                    }
                }
            }
            $rstmt->close();
        } else {
            dbg("Prepare refunds insert failed: " . $mysqli->error);
        }
    }

    if (!$mysqli->commit()) {
        $err = "Commit failed: " . $mysqli->error;
        dbg($err);
        throw new Exception($err);
    }

    $_SESSION['flash_message'] = "Booking cancelled. Refund processed (simulated).";
    header('Location: /FLIGHT_FRONTEND/my_bookings.php');
    exit;

} catch (Throwable $e) {
    // rollback and show error
    @$mysqli->rollback();
    $msg = $e->getMessage();
    dbg("Exception: " . $msg . " -- Trace: " . $e->getTraceAsString());
    // show a friendly message + small hint; full error is in log
    $_SESSION['flash_error'] = "Failed to cancel booking. See tools/cancel_debug.log for details.";
    header('Location: /FLIGHT_FRONTEND/my_bookings.php');
    exit;
}
