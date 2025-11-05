<?php
// /FLIGHT_FRONTEND/api/cancel_ticket.php
// Cancels a booking + ticket, applies fixed surcharge (server-side), logs cancellation time
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
safe_start_session();

function dbg($m) {
    error_log("[".date('Y-m-d H:i:s')."] " . $m . PHP_EOL, 3, __DIR__ . '/../tools/cancel_ticket_debug.log');
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
        exit;
    }

    // server-enforced fixed surcharge (change this as needed)
    $FIXED_SURCHARGE_AMT = 250.00;

    $booking_id  = trim($_POST['booking_id'] ?? '');
    $reason      = trim($_POST['reason'] ?? '');
    $passport_no = $_SESSION['passport_no'] ?? '';
    $is_admin    = !empty($_SESSION['is_admin']);

    if ($booking_id === '') {
        echo json_encode(['ok'=>false,'error'=>'Missing booking_id']);
        exit;
    }
    if ($passport_no === '') {
        echo json_encode(['ok'=>false,'error'=>'Not authenticated']);
        exit;
    }

    // fetch booking (defensive)
    $stmt = $mysqli->prepare("SELECT booking_id, flight_id, passport_no, status FROM bookings WHERE booking_id = ? LIMIT 1");
    if (!$stmt) {
        dbg("prepare booking lookup failed: " . $mysqli->error);
        echo json_encode(['ok'=>false,'error'=>'DB error']);
        exit;
    }
    $stmt->bind_param('s', $booking_id);
    $stmt->execute();
    $brow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$brow) {
        echo json_encode(['ok'=>false,'error'=>'Booking not found']);
        exit;
    }

    // auth check
    if ($brow['passport_no'] !== $passport_no && !$is_admin) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
        exit;
    }

    if (strtolower($brow['status']) === 'cancelled' || strtolower($brow['status']) === 'canceled') {
        echo json_encode(['ok'=>false,'error'=>'Already cancelled']);
        exit;
    }

    // Step: get ticket price (best-effort - try ticket table then flights table fallback)
    $price = 0.00;
    $tp = $mysqli->prepare("SELECT price FROM ticket WHERE booking_id = ? LIMIT 1");
    if ($tp) {
        $tp->bind_param('s', $booking_id);
        $tp->execute();
        $res = $tp->get_result()->fetch_assoc();
        $tp->close();
        if ($res && isset($res['price'])) $price = floatval($res['price']);
    }
    // fallback: try bookings->flight table price
    if ($price <= 0) {
        // try to find a price on flight/bookings if present
        $qb = $mysqli->prepare("SELECT COALESCE(f.base_price, f.price, 0) AS price FROM bookings b JOIN flight f ON b.flight_id = f.flight_id WHERE b.booking_id = ? LIMIT 1");
        if ($qb) {
            $qb->bind_param('s', $booking_id);
            $qb->execute();
            $br = $qb->get_result()->fetch_assoc();
            $qb->close();
            if ($br && isset($br['price'])) $price = floatval($br['price']);
        }
    }

    // Apply fixed surcharge (server-side only)
    $surcharge = $FIXED_SURCHARGE_AMT;
    if ($surcharge > $price) $surcharge = $price; // don't make refund negative
    $refund = max(0, $price - $surcharge);

    // Ensure cancellation_tx table exists (non-fatal if create fails)
    $mysqli->query("CREATE TABLE IF NOT EXISTS cancellation_tx (
        id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id VARCHAR(64),
        passport_no VARCHAR(64),
        flight_id VARCHAR(64),
        reason TEXT,
        surcharge DECIMAL(10,2),
        refund DECIMAL(10,2),
        cancelled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        processed_by VARCHAR(64)
    ) ENGINE=InnoDB");

    // Insert cancellation_tx record
    $sql = "INSERT INTO cancellation_tx (booking_id, passport_no, flight_id, reason, surcharge, refund, processed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        dbg("prepare insert cancellation_tx failed: " . $mysqli->error);
        echo json_encode(['ok'=>false,'error'=>'DB error recording cancellation']);
        exit;
    }
    $stmt->bind_param('ssssdds', $booking_id, $passport_no, $brow['flight_id'], $reason, $surcharge, $refund, $passport_no);
    if (! $stmt->execute()) {
        dbg("execute insert cancellation_tx failed: " . $stmt->error);
        $stmt->close();
        echo json_encode(['ok'=>false,'error'=>'DB error recording cancellation']);
        exit;
    }
    $cancel_id = $mysqli->insert_id;
    $stmt->close();

    // Update bookings row: set status and cancelled_at (schema-aware)
    $bookCols = [];
    $cres = $mysqli->query("SHOW COLUMNS FROM bookings");
    if ($cres) while ($c = $cres->fetch_assoc()) $bookCols[] = $c['Field'];

    $updates = [];
    if (in_array('status', $bookCols)) $updates[] = "status = 'cancelled'";
    if (in_array('cancelled_at', $bookCols)) $updates[] = "cancelled_at = NOW()";
    if (in_array('cancelled_by', $bookCols)) $updates[] = "cancelled_by = '" . $mysqli->real_escape_string($passport_no) . "'";

    if (!empty($updates)) {
        $sqlu = "UPDATE bookings SET " . implode(', ', $updates) . " WHERE booking_id = '" . $mysqli->real_escape_string($booking_id) . "' LIMIT 1";
        $mysqli->query($sqlu);
    } else {
        // fallback
        $mysqli->query("UPDATE bookings SET status = 'cancelled' WHERE booking_id = '" . $mysqli->real_escape_string($booking_id) . "' LIMIT 1");
    }

    // Update ticket table if present
    $tcols = [];
    $tres = $mysqli->query("SHOW TABLES LIKE 'ticket'");
    if ($tres && $tres->num_rows > 0) {
        $tc = $mysqli->query("SHOW COLUMNS FROM ticket");
        if ($tc) while ($c = $tc->fetch_assoc()) $tcols[] = $c['Field'];

        $updates = [];
        if (in_array('status', $tcols)) $updates[] = "status = 'cancelled'";
        if (in_array('cancelled_at', $tcols)) $updates[] = "cancelled_at = NOW()";

        if (!empty($updates)) {
            if (in_array('booking_id', $tcols)) {
                $sqlt = "UPDATE ticket SET " . implode(', ', $updates) . " WHERE booking_id = ? LIMIT 1";
                $stmtt = $mysqli->prepare($sqlt);
                if ($stmtt) {
                    $stmtt->bind_param('s', $booking_id);
                    $stmtt->execute();
                    $stmtt->close();
                }
            } else {
                // fallback: use passport + flight
                $sqlt2 = "UPDATE ticket SET " . implode(', ', $updates) . " WHERE passport_no = ? AND flight_id = ? LIMIT 5";
                $stmtt2 = $mysqli->prepare($sqlt2);
                if ($stmtt2) {
                    $stmtt2->bind_param('ss', $passport_no, $brow['flight_id']);
                    $stmtt2->execute();
                    $stmtt2->close();
                }
            }
        }
    }

    // Optionally create refunds record (server behavior). Use refunds table if exists.
    if ($mysqli->query("SHOW TABLES LIKE 'refunds'")->num_rows > 0) {
        $rstmt = $mysqli->prepare("INSERT INTO refunds (booking_id, passport_no, amount, status, processed_at) VALUES (?, ?, ?, 'completed', NOW())");
        if ($rstmt) {
            $refund_amount = $refund;
            $rstmt->bind_param('ssd', $booking_id, $passport_no, $refund_amount);
            if (! $rstmt->execute()) {
                dbg("Refund insertion failed: " . $rstmt->error);
                // don't fail entire flow; log only
            } else {
                $rid = $rstmt->insert_id;
                // optionally link refund id back to bookings if schema has refund_id
                if (in_array('refund_id', $bookCols)) {
                    $link = $mysqli->prepare("UPDATE bookings SET refund_id = ? WHERE booking_id = ? LIMIT 1");
                    if ($link) {
                        $link->bind_param('is', $rid, $booking_id);
                        $link->execute();
                        $link->close();
                    }
                }
            }
            $rstmt->close();
        }
    }

    $cancelled_at = date('Y-m-d H:i:s');

    echo json_encode([
        'ok' => true,
        'message' => 'Ticket cancelled successfully',
        'surcharge' => number_format($surcharge, 2),
        'refund' => number_format($refund, 2),
        'cancelled_at' => $cancelled_at,
        'cancel_id' => $cancel_id
    ]);
    exit;

} catch (Throwable $e) {
    dbg("Exception cancel_ticket: " . $e->getMessage());
    echo json_encode(['ok'=>false,'error'=>'Internal server error']);
    exit;
}
