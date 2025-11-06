<?php
// /FLIGHT_FRONTEND/api/cancel_ticket.php
// Robust cancel endpoint — dynamically discovers ticket table & columns, reads price from ticket -> bookings -> flights.
// Returns JSON. Logs diagnostics to tools/cancel_ticket_debug.log

ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
safe_start_session();

// debug writer (append)
function dbg($m) {
    $log = __DIR__ . '/../tools/cancel_ticket_debug.log';
    @file_put_contents($log, "[".date('Y-m-d H:i:s')."] " . $m . PHP_EOL, FILE_APPEND);
}
function dbg_exc($e) {
    dbg("EXCEPTION: " . $e->getMessage());
    dbg($e->getTraceAsString());
}

// helpers
function table_exists(mysqli $m, $name) {
    $res = $m->query("SHOW TABLES LIKE '" . $m->real_escape_string($name) . "'");
    return ($res && $res->num_rows > 0);
}
function get_cols(mysqli $m, $table) {
    $cols = [];
    $res = $m->query("SHOW COLUMNS FROM `".$m->real_escape_string($table)."`");
    if ($res) while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
    return $cols;
}
function pick_first_ci(array $cols, array $cands) {
    foreach ($cands as $cand) {
        foreach ($cols as $c) {
            if (strcasecmp($c, $cand) === 0) return $c;
        }
    }
    return null;
}
function pick_table_by_heuristic(mysqli $m, array $candidates) {
    // try common names first
    foreach ($candidates as $c) if (table_exists($m, $c)) return $c;
    // scan all tables for ones containing 'tick' or having a booking-like column
    $res = $m->query("SHOW TABLES");
    if (!$res) return null;
    while ($r = $res->fetch_array(MYSQLI_NUM)) {
        $t = $r[0];
        if (stripos($t, 'tick') !== false) return $t;
        $cols = get_cols($m, $t);
        if (pick_first_ci($cols, ['booking_id','booking','bk_id','reservation_id','reservation']) !== null) return $t;
    }
    return null;
}

// sanity
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    dbg("Missing \$mysqli in includes/db.php");
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server misconfiguration (DB)']);
    exit;
}

try {
    // make mysqli throw exceptions so catch block can handle
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
        exit;
    }

    $passport_no = $_SESSION['passport_no'] ?? '';
    $is_admin = !empty($_SESSION['is_admin']);
    if ($passport_no === '') {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'Not authenticated']);
        exit;
    }

    $booking_id = trim($_POST['booking_id'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    if ($booking_id === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Missing booking_id']);
        exit;
    }

    dbg("cancel_ticket called booking_id={$booking_id} by passport={$passport_no}");

    // --- fetch booking (bookings table schema you posted shows booking_id, flight_id, passport_no, status, etc) ---
    $bst = $mysqli->prepare("SELECT booking_id, flight_id, passport_no, status FROM bookings WHERE booking_id = ? LIMIT 1");
    $bst->bind_param('s', $booking_id);
    $bst->execute();
    $brow = $bst->get_result()->fetch_assoc();
    $bst->close();

    if (!$brow) {
        dbg("Booking not found: {$booking_id}");
        echo json_encode(['ok'=>false,'error'=>'Booking not found']);
        exit;
    }

    // ownership check
    if ($brow['passport_no'] !== $passport_no && !$is_admin) {
        dbg("Unauthorized cancel attempt booking={$booking_id} passport={$passport_no}");
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
        exit;
    }

    if (in_array(strtolower($brow['status']), ['cancelled','canceled','refunded'])) {
        echo json_encode(['ok'=>false,'error'=>'Booking already cancelled/refunded']);
        exit;
    }

    // --- discover ticket table + columns (first pass) ----------
    $ticket_table = pick_table_by_heuristic($mysqli, ['ticket','tickets','passenger_ticket','booking_ticket','booking_tickets']);
    $ticket_booking_col = null;
    $ticket_price_col = null;
    $price = 0.0;

    if ($ticket_table) {
        $tcols = get_cols($mysqli, $ticket_table);
        dbg("Ticket table detected: {$ticket_table} (cols: " . implode(',', $tcols) . ")");
        $ticket_booking_col = pick_first_ci($tcols, ['booking_id','booking','bk_id','reservation_id','reservation','bookingref','booking_ref']);
        $ticket_price_col = pick_first_ci($tcols, ['price','fare','amount','cost','paid_amount','ticket_price']);

        if ($ticket_booking_col && $ticket_price_col) {
            $ps = $mysqli->prepare("SELECT `{$ticket_price_col}` AS p FROM `{$ticket_table}` WHERE `{$ticket_booking_col}` = ? LIMIT 1");
            if ($ps) {
                $ps->bind_param('s', $booking_id);
                $ps->execute();
                $prow = $ps->get_result()->fetch_assoc();
                $ps->close();
                if ($prow && isset($prow['p'])) $price = floatval($prow['p']);
            }
        } else {
            dbg("Ticket table present but missing booking-FK or price column (booking_col={$ticket_booking_col}, price_col={$ticket_price_col})");
        }
    } else {
        dbg("No ticket table discovered.");
    }

    // -------- NEW: try bookings table for a price column (you said price lives here) --------
    if ($price <= 0.0) {
        $bcols = get_cols($mysqli, 'bookings');
        dbg("Bookings cols: " . implode(',', $bcols));
        $bk_price_col = pick_first_ci($bcols, ['price','fare','amount','cost','paid_amount','booking_price','amount_paid']);
        if ($bk_price_col) {
            $bps = $mysqli->prepare("SELECT `{$bk_price_col}` AS p FROM `bookings` WHERE booking_id = ? LIMIT 1");
            if ($bps) {
                $bps->bind_param('s', $booking_id);
                $bps->execute();
                $bprow = $bps->get_result()->fetch_assoc();
                $bps->close();
                if ($bprow && isset($bprow['p'])) {
                    $price = floatval($bprow['p']);
                    dbg("Price read from bookings.{$bk_price_col} = {$price} for booking {$booking_id}");
                }
            }
        } else {
            dbg("No price-like column found in bookings table.");
        }
    }

    // --- fallback: flight table price if we didn't get price ---
    if ($price <= 0.0) {
        $flight_val = $brow['flight_id'] ?? null;
        if ($flight_val) {
            $flight_table = pick_table_by_heuristic($mysqli, ['flight','flights','flight_master','flights_master']);
            if ($flight_table) {
                $fcols = get_cols($mysqli, $flight_table);
                $f_price = pick_first_ci($fcols, ['price','base_price','fare','amount','cost']);
                $f_id = pick_first_ci($fcols, ['flight_id','id','flight_no','flightnum']);
                if ($f_price && $f_id) {
                    $fps = $mysqli->prepare("SELECT `{$f_price}` AS p FROM `{$flight_table}` WHERE `{$f_id}` = ? LIMIT 1");
                    if ($fps) {
                        $fps->bind_param('s', $flight_val);
                        $fps->execute();
                        $frow = $fps->get_result()->fetch_assoc();
                        $fps->close();
                        if ($frow && isset($frow['p'])) $price = floatval($frow['p']);
                    }
                } else {
                    dbg("Flight table exists but missing price/id columns ({$flight_table})");
                }
            } else dbg("No flight table found for price fallback.");
        }
    }

    dbg("Resolved price for {$booking_id}: " . number_format($price,2));

    // fixed surcharge logic (server side)
    $FIXED_SURCHARGE_AMT = 250.00;
    $surcharge = $FIXED_SURCHARGE_AMT;
    if ($surcharge > $price) $surcharge = $price; // don't make refund negative
    $refund = max(0, round($price - $surcharge, 2));

    dbg("surcharge={$surcharge} refund={$refund}");

    // --- perform DB updates in a transaction: create cancellation record, mark booking/ticket as cancelled (or fallback) ---
    $mysqli->begin_transaction();

    // ensure cancellation_tx table
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

    $procBy = $_SESSION['name'] ?? $passport_no;
    $cins = $mysqli->prepare("INSERT INTO cancellation_tx (booking_id, passport_no, flight_id, reason, surcharge, refund, processed_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $cins->bind_param('ssssdds', $booking_id, $passport_no, $brow['flight_id'], $reason, $surcharge, $refund, $procBy);
    $cins->execute();
    $cancel_id = $mysqli->insert_id;
    $cins->close();

    // mark booking cancelled (bookings table has status + maybe cancelled_at)
    $bookCols = get_cols($mysqli, 'bookings');
    $updates = [];
    if (in_array('status', $bookCols)) $updates[] = "status = 'cancelled'";
    if (in_array('cancelled_at', $bookCols)) $updates[] = "cancelled_at = NOW()";
    if (in_array('cancelled_by', $bookCols)) $updates[] = "cancelled_by = '".$mysqli->real_escape_string($procBy)."'";

    if (!empty($updates)) {
        $sqlu = "UPDATE bookings SET " . implode(', ', $updates) . " WHERE booking_id = ? LIMIT 1";
        $upst = $mysqli->prepare($sqlu);
        $upst->bind_param('s', $booking_id);
        $upst->execute();
        $upst->close();
    } else {
        // fallback: set status if nothing else available (defensive)
        $fq = $mysqli->prepare("UPDATE bookings SET status = 'cancelled' WHERE booking_id = ? LIMIT 1");
        $fq->bind_param('s', $booking_id);
        $fq->execute();
        $fq->close();
    }

    // update ticket rows: prefer ticket_table + ticket_booking_col; fallback to passport+flight
    if (!empty($ticket_table) && !empty($ticket_booking_col)) {
        $tcols = get_cols($mysqli, $ticket_table);
        $tupdates = [];
        if (in_array('status', $tcols)) $tupdates[] = "status = 'cancelled'";
        if (in_array('cancelled_at', $tcols)) $tupdates[] = "cancelled_at = NOW()";

        if (!empty($tupdates)) {
            $sqlt = "UPDATE `{$ticket_table}` SET " . implode(', ', $tupdates) . " WHERE `{$ticket_booking_col}` = ?";
            $stmtt = $mysqli->prepare($sqlt);
            $stmtt->bind_param('s', $booking_id);
            $stmtt->execute();
            dbg("Updated ticket table {$ticket_table} by {$ticket_booking_col}, affected=".$stmtt->affected_rows);
            $stmtt->close();
        } else dbg("Ticket table {$ticket_table} has no status/cancelled_at columns; skipping ticket update.");
    } else {
        // fallback: try passport_no + flight_id columns in any ticket-like table
        if (!empty($ticket_table)) {
            $tcols = get_cols($mysqli, $ticket_table);
            $tupdates = [];
            if (in_array('status', $tcols)) $tupdates[] = "status = 'cancelled'";
            if (in_array('cancelled_at', $tcols)) $tupdates[] = "cancelled_at = NOW()";

            if (!empty($tupdates) && in_array('passport_no', $tcols) && in_array('flight_id', $tcols)) {
                $sqlt = "UPDATE `{$ticket_table}` SET " . implode(', ', $tupdates) . " WHERE passport_no = ? AND flight_id = ?";
                $stmtt = $mysqli->prepare($sqlt);
                $stmtt->bind_param('ss', $passport_no, $brow['flight_id']);
                $stmtt->execute();
                dbg("Updated ticket table {$ticket_table} by passport_no+flight_id, affected=".$stmtt->affected_rows);
                $stmtt->close();
            } else {
                dbg("Cannot update tickets: no booking FK and passport/flight columns absent in ticket table.");
            }
        } else {
            dbg("No ticket table present; skipping ticket updates.");
        }
    }

    // optional: create refunds record if refunds table exists (non-fatal)
    if ($mysqli->query("SHOW TABLES LIKE 'refunds'")->num_rows > 0) {
        $rstmt = $mysqli->prepare("INSERT INTO refunds (booking_id, passport_no, amount, status, processed_at) VALUES (?, ?, ?, 'completed', NOW())");
        if ($rstmt) {
            $rstmt->bind_param('ssd', $booking_id, $passport_no, $refund);
            if (!$rstmt->execute()) dbg("Refund insert failed: " . $rstmt->error);
            else dbg("Refund created for {$booking_id} amt={$refund}");
            $rstmt->close();
        }
    }

    $mysqli->commit();

    $cancelled_at = date('Y-m-d H:i:s');
    echo json_encode([
        'ok'=>true,
        'message'=>'Ticket cancelled successfully',
        'surcharge'=>number_format($surcharge,2,'.',''),
        'refund'=>number_format($refund,2,'.',''),
        'cancelled_at'=>$cancelled_at,
        'cancel_id'=>$cancel_id
    ]);
    exit;

} catch (Throwable $e) {
    dbg_exc($e);
    try {
        if ($mysqli && $mysqli->connect_errno === 0 && $mysqli->in_transaction) $mysqli->rollback();
    } catch (Throwable $ee) {
        dbg("Rollback failed: ".$ee->getMessage());
    }
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Internal server error']);
    exit;
}
