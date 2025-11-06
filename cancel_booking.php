<?php
// /FLIGHT_FRONTEND/cancel_booking.php
// Robust cancel endpoint — discovers ticket table & columns dynamically.
// Defensive: avoids hard-coded b.price or booking_id assumptions.
// Returns JSON for AJAX.

ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
safe_start_session();

// debug log helper (append-only)
$DEBUG_LOG = __DIR__ . '/tools/cancel_debug.log';
function dbg($m) {
    global $DEBUG_LOG;
    @file_put_contents($DEBUG_LOG, date('[Y-m-d H:i:s] ') . $m . PHP_EOL, FILE_APPEND);
}
function dbg_exc($e) {
    dbg("EXCEPTION: " . $e->getMessage());
    dbg($e->getTraceAsString());
}

// small helpers
function table_exists(mysqli $m, $pattern) {
    $res = $m->query("SHOW TABLES LIKE '". $m->real_escape_string($pattern) ."'");
    return ($res && $res->num_rows > 0);
}
function get_table_columns(mysqli $m, $table) {
    $cols = [];
    $res = $m->query("SHOW COLUMNS FROM `". $m->real_escape_string($table) ."`");
    if ($res) while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
    return $cols;
}
function find_first_existing_table(mysqli $m, array $candidates) {
    foreach ($candidates as $t) {
        if (table_exists($m, $t)) return $t;
    }
    return null;
}
function find_column_like(array $cols, array $candidates) {
    foreach ($candidates as $cand) {
        foreach ($cols as $c) {
            if (strcasecmp($c, $cand) === 0) return $c;
        }
    }
    return null;
}

// sanity: ensure $mysqli exists
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    dbg("Missing or invalid \$mysqli connection in includes/db.php");
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server misconfiguration (DB connection)']);
    exit;
}

try {
    // Throw exceptions on mysqli errors
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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

    // -------- bookings schema discovery (flexible) ----------
    $bkCols = get_table_columns($mysqli, 'bookings');
    if (empty($bkCols)) {
        dbg("Bookings table missing or empty columns");
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Server misconfiguration (bookings schema)']);
        exit;
    }

    $bk_booking_id_col = in_array('booking_id', $bkCols) ? 'booking_id' : (in_array('id', $bkCols) ? 'id' : null);
    $bk_passport_col   = find_column_like($bkCols, ['passport_no','passport','pass_no']);
    $bk_status_col     = find_column_like($bkCols, ['status','booking_status']);
    $bk_flight_col     = find_column_like($bkCols, ['flight_id','flight','flight_no','flightnum']);

    if (!$bk_booking_id_col || !$bk_passport_col) {
        dbg("Bookings table missing key columns. booking_id_col={$bk_booking_id_col} passport_col={$bk_passport_col}");
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Server misconfiguration (bookings schema)']);
        exit;
    }

    // fetch booking row
    $sql = "SELECT * FROM bookings WHERE `{$bk_booking_id_col}` = ? LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $booking_id);
    $stmt->execute();
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

    // ---------- ticket table discovery ----------
    // list of plausible ticket table names and column names
    $ticket_table_candidates = ['ticket','tickets','passenger_ticket','passengers_tickets','booking_ticket','booking_tickets'];
    $ticket_table = find_first_existing_table($mysqli, $ticket_table_candidates);

    // If none of the common names found, try scanning all tables for one that references bookings (heuristic)
    if (!$ticket_table) {
        $allTablesRes = $mysqli->query("SHOW TABLES");
        if ($allTablesRes) {
            while ($row = $allTablesRes->fetch_array(MYSQLI_NUM)) {
                $t = $row[0];
                $cols = get_table_columns($mysqli, $t);
                // heuristic: table with column that looks like booking FK or 'price' or 'ticket'
                $hasBookingLike = !!find_column_like($cols, ['booking_id','booking','bk_id','booking_ref','booking_ref_id','bookingid']);
                $hasTicketLike = stripos($t, 'tick') !== false;
                if ($hasBookingLike || $hasTicketLike) {
                    $ticket_table = $t;
                    dbg("Heuristic picked ticket table: {$ticket_table}");
                    break;
                }
            }
        }
    }

    $price = 0.0;
    $ticket_booking_col = null;
    $ticket_price_col = null;

    if ($ticket_table) {
        $tCols = get_table_columns($mysqli, $ticket_table);
        dbg("Ticket table found: {$ticket_table} cols: " . implode(',', $tCols));

        // guess booking FK col and price col
        $ticket_booking_col = find_column_like($tCols, ['booking_id','booking','bk_id','booking_ref','booking_ref_id','reservation_id']);
        $ticket_price_col   = find_column_like($tCols, ['price','fare','amount','cost','ticket_price','paid_amount']);

        // if booking FK missing, try common alt in bookings -> maybe tickets store bookings differently
        if (!$ticket_booking_col) {
            dbg("ticket table {$ticket_table} missing booking FK column");
        } else {
            // fetch price if available
            if ($ticket_price_col) {
                $psql = "SELECT `{$ticket_price_col}` AS p FROM `{$ticket_table}` WHERE `{$ticket_booking_col}` = ? LIMIT 1";
                $pstmt = $mysqli->prepare($psql);
                $pstmt->bind_param('s', $booking_id);
                $pstmt->execute();
                $prow = $pstmt->get_result()->fetch_assoc();
                $pstmt->close();
                if ($prow && isset($prow['p'])) $price = floatval($prow['p']);
            } else {
                dbg("ticket table {$ticket_table} missing price-like column");
            }
        }
    } else {
        dbg("No ticket table discovered among candidates");
    }

    // fallback to flight table if price still zero
    if ($price <= 0.0) {
        $flight_val = $booking[$bk_flight_col] ?? null;
        if ($flight_val) {
            $flight_table = find_first_existing_table($mysqli, ['flight','flights','flight_master','flights_master']);
            if ($flight_table) {
                $fcols = get_table_columns($mysqli, $flight_table);
                $f_price_col = find_column_like($fcols, ['price','base_price','fare','amount','cost']);
                $f_id_col    = find_column_like($fcols, ['flight_id','id','flight_no','flightnum']);
                if ($f_price_col && $f_id_col) {
                    $fsql = "SELECT `{$f_price_col}` AS p FROM `{$flight_table}` WHERE `{$f_id_col}` = ? LIMIT 1";
                    $fstmt = $mysqli->prepare($fsql);
                    $fstmt->bind_param('s', $flight_val);
                    $fstmt->execute();
                    $frow = $fstmt->get_result()->fetch_assoc();
                    $fstmt->close();
                    if ($frow && isset($frow['p'])) $price = floatval($frow['p']);
                } else {
                    dbg("flight table found but missing price/id columns ({$flight_table})");
                }
            } else dbg("no flight table found for fallback");
        }
    }

    dbg("price resolved for {$booking_id}: " . number_format($price,2));

    // fixed surcharge
    $FIXED_SURCHARGE = 250.00;
    $surcharge = $FIXED_SURCHARGE;
    $refund = max(0.0, round($price - $surcharge, 2));
    dbg("surcharge={$surcharge}, refund={$refund}");

    // --------- begin transaction ----------
    $mysqli->begin_transaction();

    // 1) delete ticket rows if ticket_table discovered AND it has booking FK column
    if ($ticket_table && $ticket_booking_col) {
        $delSql = "DELETE FROM `{$ticket_table}` WHERE `{$ticket_booking_col}` = ?";
        $dstmt = $mysqli->prepare($delSql);
        $dstmt->bind_param('s', $booking_id);
        $dstmt->execute();
        $deletedTickets = $dstmt->affected_rows;
        $dstmt->close();
        dbg("Deleted {$deletedTickets} rows from {$ticket_table} where {$ticket_booking_col} = {$booking_id}");
    } else {
        dbg("Skipping ticket delete: either no ticket table ({$ticket_table}) or no booking FK ({$ticket_booking_col})");
    }

    // 2) delete booking row
    $delBookingSql = "DELETE FROM bookings WHERE `{$bk_booking_id_col}` = ? AND `{$bk_passport_col}` = ? LIMIT 1";
    $delStmt = $mysqli->prepare($delBookingSql);
    $delStmt->bind_param('ss', $booking_id, $passport);
    $delStmt->execute();
    $deletedBookings = $delStmt->affected_rows;
    $delStmt->close();

    if ($deletedBookings < 1) {
        dbg("Booking delete affected 0 rows for {$booking_id} (passport={$passport}). Rolling back.");
        $mysqli->rollback();
        http_response_code(409);
        echo json_encode(['ok'=>false,'error'=>'Booking could not be deleted (may have changed).']);
        exit;
    }
    dbg("Booking {$booking_id} deleted.");

    // 3) insert audit record into cancellation_tx
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

    $flight_for_log = $booking[$bk_flight_col] ?? null;
    $procBy = $_SESSION['name'] ?? $passport;

    $cins = $mysqli->prepare("INSERT INTO cancellation_tx (booking_id, passport_no, flight_id, reason, surcharge, refund, processed_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $cins->bind_param('ssssdds', $booking_id, $passport, $flight_for_log, $reason, $surcharge, $refund, $procBy);
    $cins->execute();
    $cins->close();

    // commit
    $mysqli->commit();

    echo json_encode([
        'ok' => true,
        'message' => 'Booking deleted and cancellation recorded.',
        'surcharge' => number_format($surcharge, 2, '.', ''),
        'refund' => number_format($refund, 2, '.', ''),
        'cancelled_at' => date('Y-m-d H:i:s')
    ]);
    exit;

} catch (Throwable $e) {
    dbg_exc($e);
    // attempt rollback safely
    try { if ($mysqli && $mysqli->connect_errno === 0 && $mysqli->in_transaction) $mysqli->rollback(); } catch (Throwable $ee) { dbg("Rollback failed: ".$ee->getMessage()); }
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Internal server error']);
    exit;
}
