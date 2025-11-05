<?php
// /FLIGHT_FRONTEND/cancel_booking.php
// Defensive cancel endpoint — returns JSON for AJAX.
// Applies fixed surcharge (server-side) and cancels booking & ticket rows.
// Does NOT write into refunds table (to avoid schema issues).

ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
safe_start_session();

$DEBUG_LOG = __DIR__ . '/tools/cancel_debug.log';
function dbg($m) {
    global $DEBUG_LOG;
    @file_put_contents($DEBUG_LOG, date('[Y-m-d H:i:s] ') . $m . PHP_EOL, FILE_APPEND);
}
function dbg_exc($e) {
    dbg("EXCEPTION: " . $e->getMessage());
    dbg($e->getTraceAsString());
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
        exit;
    }

    if (empty($_SESSION['passport_no'])) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'Not authenticated']);
        exit;
    }

    $passport = $_SESSION['passport_no'];
    $booking_id = trim($_POST['booking_id'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if ($booking_id === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Missing booking_id']);
        exit;
    }

    dbg("Cancel request booking_id={$booking_id} passport={$passport}");

    // fetch bookings columns to be flexible
    $bkCols = [];
    $cres = $mysqli->query("SHOW COLUMNS FROM bookings");
    if ($cres) while ($c = $cres->fetch_assoc()) $bkCols[] = $c['Field'];

    $bk_booking_id_col = in_array('booking_id', $bkCols) ? 'booking_id' : (in_array('id', $bkCols) ? 'id' : null);
    $bk_passport_col   = in_array('passport_no', $bkCols) ? 'passport_no' : (in_array('passport', $bkCols) ? 'passport' : null);
    $bk_status_col     = in_array('status', $bkCols) ? 'status' : null;
    $bk_flight_col     = in_array('flight_id', $bkCols) ? 'flight_id' : (in_array('flight', $bkCols) ? 'flight' : null);
    $bk_cancelled_at   = in_array('cancelled_at', $bkCols) ? 'cancelled_at' : null;
    $bk_cancelled_by   = in_array('cancelled_by', $bkCols) ? 'cancelled_by' : null;

    if (!$bk_booking_id_col || !$bk_passport_col) {
        dbg("Bookings table missing key columns");
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Server misconfiguration (bookings schema)']);
        exit;
    }

    // fetch booking
    $sql = "SELECT * FROM bookings WHERE `{$bk_booking_id_col}` = ? LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        dbg("Prepare failed fetch booking: " . $mysqli->error . " SQL: $sql");
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Server DB error']);
        exit;
    }
    $stmt->bind_param('s', $booking_id);
    if (!$stmt->execute()) {
        dbg("Execute failed fetch booking: " . $stmt->error);
        $stmt->close();
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Server DB error']);
        exit;
    }
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>'Booking not found']);
        exit;
    }

    // auth check
    $bookPassport = $booking[$bk_passport_col] ?? '';
    $is_admin = !empty($_SESSION['is_admin']);
    if ($bookPassport !== $passport && !$is_admin) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Unauthorized to cancel this booking']);
        exit;
    }

    $curr_status = strtolower($booking[$bk_status_col] ?? '');
    if ($curr_status === 'cancelled' || $curr_status === 'canceled') {
        echo json_encode(['ok'=>false,'error'=>'Booking already cancelled']);
        exit;
    }

    // Determine price: prefer ticket.price, fallback to flight table
    $price = 0.0;
    if ($mysqli->query("SHOW TABLES LIKE 'ticket'")->num_rows > 0) {
        $tCols = [];
        $tres = $mysqli->query("SHOW COLUMNS FROM ticket");
        if ($tres) while ($c = $tres->fetch_assoc()) $tCols[] = $c['Field'];

        if (in_array('booking_id', $tCols) && in_array('price', $tCols)) {
            $tstmt = $mysqli->prepare("SELECT price FROM ticket WHERE booking_id = ? LIMIT 1");
            if ($tstmt) {
                $tstmt->bind_param('s', $booking_id);
                if ($tstmt->execute()) {
                    $trow = $tstmt->get_result()->fetch_assoc();
                    if ($trow && isset($trow['price'])) $price = floatval($trow['price']);
                } else dbg("ticket price execute failed: " . $tstmt->error);
                $tstmt->close();
            } else dbg("prepare ticket price failed: " . $mysqli->error);
        }
    }

    // fallback to flight.price if still zero and booking has flight_id
    if ($price <= 0.0) {
        $flight_val = $booking[$bk_flight_col] ?? null;
        if ($flight_val) {
            $flight_table = $mysqli->query("SHOW TABLES LIKE 'flight'")->num_rows > 0 ? 'flight' :
                            ($mysqli->query("SHOW TABLES LIKE 'flights'")->num_rows > 0 ? 'flights' : null);
            if ($flight_table) {
                $fcols = []; $fres = $mysqli->query("SHOW COLUMNS FROM `{$flight_table}`");
                if ($fres) while ($c = $fres->fetch_assoc()) $fcols[] = $c['Field'];
                $f_price_col = in_array('price', $fcols) ? 'price' : (in_array('base_price', $fcols) ? 'base_price' : null);
                $f_id_col    = in_array('flight_id', $fcols) ? 'flight_id' : (in_array('id', $fcols) ? 'id' : null);
                if ($f_price_col && $f_id_col) {
                    $fsql = "SELECT `{$f_price_col}` AS p FROM `{$flight_table}` WHERE `{$f_id_col}` = ? LIMIT 1";
                    $fstmt = $mysqli->prepare($fsql);
                    if ($fstmt) {
                        $fstmt->bind_param('s', $flight_val);
                        if ($fstmt->execute()) {
                            $frow = $fstmt->get_result()->fetch_assoc();
                            if ($frow && isset($frow['p'])) $price = floatval($frow['p']);
                        } else dbg("flight price execute failed: " . $fstmt->error);
                        $fstmt->close();
                    } else dbg("prepare flight price failed: " . $mysqli->error);
                }
            }
        }
    }

    dbg("price resolved for {$booking_id}: " . number_format($price,2));

    // Fixed surcharge policy (server-side)
    $FIXED_SURCHARGE = 250.00;
    $surcharge = $FIXED_SURCHARGE;
    $refund = max(0.0, round($price - $surcharge, 2));

    dbg("surcharge={$surcharge}, refund={$refund}");

    // Begin transaction
    if (!$mysqli->begin_transaction()) {
        dbg("begin_transaction failed: " . $mysqli->error);
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Server DB error (transaction)']);
        exit;
    }

    // Update bookings table: set status + cancelled_at + cancelled_by if columns present
    $upd_parts = [];
    $upd_params = [];
    $upd_types = '';

    if ($bk_status_col) {
        $upd_parts[] = "`{$bk_status_col}` = ?";
        $upd_types .= 's';
        $upd_params[] = 'cancelled';
    }
    if ($bk_cancelled_at) {
        $upd_parts[] = "`{$bk_cancelled_at}` = NOW()";
    }
    if ($bk_cancelled_by) {
        $upd_parts[] = "`{$bk_cancelled_by}` = ?";
        $upd_types .= 's';
        $upd_params[] = $_SESSION['name'] ?? $passport;
    }

    if (!empty($upd_parts)) {
        $setSql = implode(', ', $upd_parts);
        $updateSql = "UPDATE bookings SET {$setSql} WHERE `{$bk_booking_id_col}` = ? AND `{$bk_passport_col}` = ? LIMIT 1";
        $ustmt = $mysqli->prepare($updateSql);
        if (!$ustmt) {
            dbg("prepare update bookings failed: " . $mysqli->error . " SQL: $updateSql");
            $mysqli->rollback();
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>'Server DB error (update bookings)']);
            exit;
        }
        // bind types
        $bindTypes = $upd_types . 'ss';
        $bindArgs = [$bindTypes];
        foreach ($upd_params as $p) $bindArgs[] = &$p;
        $bindArgs[] = &$booking_id;
        $bindArgs[] = &$passport;
        call_user_func_array([$ustmt, 'bind_param'], $bindArgs);
        if (!$ustmt->execute()) {
            dbg("execute update bookings failed: " . $ustmt->error);
            $ustmt->close();
            $mysqli->rollback();
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>'Server DB error (update bookings exec)']);
            exit;
        }
        $ustmt->close();
    }

    // Update ticket (if table present)
    if ($mysqli->query("SHOW TABLES LIKE 'ticket'")->num_rows > 0) {
        $tstmt = $mysqli->prepare("UPDATE ticket SET status='cancelled', cancelled_at=NOW() WHERE booking_id = ? LIMIT 5");
        if ($tstmt) {
            $tstmt->bind_param('s', $booking_id);
            $tstmt->execute();
            $tstmt->close();
        } else dbg("prepare update ticket failed: " . $mysqli->error);
    }

    // Create cancellation_tx if missing and insert a record
    $mysqli->query("CREATE TABLE IF NOT EXISTS cancellation_tx (
        id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id VARCHAR(128),
        passport_no VARCHAR(64),
        flight_id VARCHAR(128),
        reason TEXT,
        surcharge DECIMAL(10,2),
        refund DECIMAL(10,2),
        cancelled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        processed_by VARCHAR(64)
    ) ENGINE=InnoDB");

    $cins = $mysqli->prepare("INSERT INTO cancellation_tx (booking_id, passport_no, flight_id, reason, surcharge, refund, processed_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($cins) {
        $flight_for_log = $booking[$bk_flight_col] ?? null;
        $procBy = $_SESSION['name'] ?? $passport;
        $cins->bind_param('ssssdds', $booking_id, $passport, $flight_for_log, $reason, $surcharge, $refund, $procBy);
        if (!$cins->execute()) {
            dbg("cancellation_tx insert failed: " . $cins->error);
            // continue — not fatal
        }
        $cins->close();
    } else {
        dbg("prepare cancellation_tx insert failed: " . $mysqli->error);
    }

    if (!$mysqli->commit()) {
        dbg("commit failed: " . $mysqli->error);
        $mysqli->rollback();
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Server DB error (commit)']);
        exit;
    }

    // success JSON
    echo json_encode([
        'ok' => true,
        'message' => 'Booking cancelled successfully.',
        'surcharge' => number_format($surcharge, 2, '.', ''),
        'refund' => number_format($refund, 2, '.', ''),
        'cancelled_at' => date('Y-m-d H:i:s')
    ]);
    exit;

} catch (Throwable $e) {
    dbg_exc($e);
    @$mysqli->rollback();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Internal server error']);
    exit;
}
