<?php
// /FLIGHT_FRONTEND/api/reschedule_booking.php
// Robust, schema-aware reschedule insert + optional auto-apply (updates bookings and tickets)
// Accepts POST: booking_id (required), new_date (YYYY-MM-DD required),
// optional new_flight_id, new_seat, reason, auto_process (1 to mark completed/apply)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
safe_start_session();

function dbg($m) {
    error_log("[".date('Y-m-d H:i:s')."] " . $m . PHP_EOL, 3, __DIR__ . '/../tools/reschedule_debug.log');
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
        exit;
    }

    $booking_id   = trim((string)($_POST['booking_id'] ?? ''));
    $new_date     = trim((string)($_POST['new_date'] ?? ''));
    $new_flight   = trim((string)($_POST['new_flight_id'] ?? ''));
    $new_seat     = trim((string)($_POST['new_seat'] ?? ''));
    $reason       = trim((string)($_POST['reason'] ?? ''));
    $auto_process = trim((string)($_POST['auto_process'] ?? '0')); // '1' to apply immediately
    $requested_by = $_SESSION['passport_no'] ?? trim((string)($_POST['requested_by'] ?? ''));

    if ($booking_id === '' || $new_date === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'booking_id and new_date are required']);
        exit;
    }
    if (empty($requested_by)) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'Not authenticated']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
        echo json_encode(['ok'=>false,'error'=>'new_date must be YYYY-MM-DD']);
        exit;
    }

    // fetch booking
    $stmt = $mysqli->prepare("SELECT booking_id, flight_id, seat_no, passport_no FROM bookings WHERE booking_id = ? LIMIT 1");
    if (!$stmt) {
        dbg("prepare booking select failed: " . $mysqli->error);
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

    // authorization
    if (!empty($_SESSION['passport_no']) && $brow['passport_no'] !== $_SESSION['passport_no']) {
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Not authorized for this booking']);
            exit;
        }
    }

    $old_flight = $brow['flight_id'] ?? null;
    $old_seat = $brow['seat_no'] ?? null;

    // ensure reschedule_tx exists
    $r = $mysqli->query("SHOW TABLES LIKE 'reschedule_tx'");
    if (!$r || $r->num_rows === 0) {
        dbg("reschedule_tx missing");
        echo json_encode(['ok'=>false,'error'=>'Server misconfiguration: reschedule_tx table missing']);
        exit;
    }

    // read reschedule_tx columns
    $cols = [];
    $cres = $mysqli->query("SHOW COLUMNS FROM reschedule_tx");
    if ($cres) while ($c = $cres->fetch_assoc()) $cols[] = $c['Field'];
    else {
        dbg("SHOW COLUMNS reschedule_tx failed: " . $mysqli->error);
        echo json_encode(['ok'=>false,'error'=>'DB error fetching reschedule schema']);
        exit;
    }

    // prepare want map
    $want = [
        'booking_id'     => $booking_id,
        'old_flight_id'  => $old_flight,
        'new_flight_id'  => $new_flight !== '' ? $new_flight : null,
        'old_seat'       => $old_seat,
        'new_seat'       => $new_seat !== '' ? $new_seat : null,
        'requested_by'   => $requested_by,
        'status'         => ($auto_process === '1') ? 'completed' : 'pending',
        'reason'         => $reason !== '' ? $reason : null,
        'requested_date' => $new_date
    ];

    $insert_cols = [];
    $placeholders = [];
    $params = [];
    $types = '';

    foreach ($want as $col => $val) {
        if (in_array($col, $cols)) {
            $insert_cols[] = "`$col`";
            $placeholders[] = '?';
            $params[] = is_null($val) ? '' : $val;
            $types .= 's';
        }
    }

    if (empty($insert_cols)) {
        dbg("No matching insert columns found in reschedule_tx");
        echo json_encode(['ok'=>false,'error'=>'Server misconfiguration: reschedule_tx has no matching columns']);
        exit;
    }

    // add created_at and processed_at placeholders depending on schema
    $use_created_at = in_array('created_at', $cols);
    $use_processed_at = in_array('processed_at', $cols) && $auto_process === '1';

    if ($use_created_at) { $insert_cols[] = "`created_at`"; $placeholders[] = 'NOW()'; }
    if ($use_processed_at) { $insert_cols[] = "`processed_at`"; $placeholders[] = 'NOW()'; }

    $sql_cols = implode(', ', $insert_cols);
    $sql_vals = implode(', ', $placeholders);
    $sql = "INSERT INTO `reschedule_tx` ({$sql_cols}) VALUES ({$sql_vals})";
    $ins = $mysqli->prepare($sql);
    if (!$ins) {
        dbg("prepare insert reschedule_tx failed: " . $mysqli->error . " SQL: $sql");
        echo json_encode(['ok'=>false,'error'=>'DB prepare failed']);
        exit;
    }

    // bind params (only '?' placeholders)
    if (!empty($params)) {
        $bind = [];
        $bind[] = $types;
        for ($i = 0; $i < count($params); $i++) {
            $var = 'p' . $i;
            $$var = $params[$i];
            $bind[] = &$$var;
        }
        call_user_func_array([$ins, 'bind_param'], $bind);
    }

    if (! $ins->execute()) {
        dbg("execute insert reschedule_tx failed: " . $ins->error . " SQL: $sql");
        echo json_encode(['ok'=>false,'error'=>'DB insert failed: ' . $ins->error]);
        $ins->close();
        exit;
    }

    $insert_id = $mysqli->insert_id;
    $ins->close();

    $applied_flight_id = null;

    // If auto_process, try to find a matching flight (if new_flight not provided) and update bookings & ticket
    if ($auto_process === '1') {

        // detect flights table and its columns
        $flight_table = $mysqli->query("SHOW TABLES LIKE 'flight'")->num_rows > 0 ? 'flight' :
                        ($mysqli->query("SHOW TABLES LIKE 'flights'")->num_rows > 0 ? 'flights' : 'flight');
        $fcols = [];
        $fres = $mysqli->query("SHOW COLUMNS FROM `{$flight_table}`");
        if ($fres) while ($c = $fres->fetch_assoc()) $fcols[] = $c['Field'];

        // helper pick column
        $pick = function($cands, $fallback=null) use ($fcols){
            foreach ($cands as $c) if (in_array($c, $fcols)) return $c;
            return $fallback;
        };

        $f_id_col = $pick(['flight_id','id','flight_code','flight_no'], 'flight_id');
        $f_dep_col = $pick(['departure_time','d_time','dep_time','departure'], null);
        $f_src_col = $pick(['source_id','src','source','src_code','source_code'], null);
        $f_dst_col = $pick(['destination_id','dst','destination','dst_code','destination_code'], null);

        // If new_flight not given, try to find by matching route of old_flight
        if ($new_flight === '' || $new_flight === null) {
            // fetch old flight route values
            if ($old_flight) {
                $ff = $mysqli->prepare("SELECT * FROM `{$flight_table}` WHERE `{$f_id_col}` = ? LIMIT 1");
                if ($ff) {
                    $ff->bind_param('s', $old_flight);
                    $ff->execute();
                    $frow = $ff->get_result()->fetch_assoc();
                    $ff->close();
                } else $frow = null;

                if ($frow) {
                    $src_val = $frow[$f_src_col] ?? null;
                    $dst_val = $frow[$f_dst_col] ?? null;

                    if ($src_val && $dst_val && $f_dep_col) {
                        // search for any flight on new_date with same src->dst
                        $where = [];
                        $params = [];
                        $types = '';

                        $where[] = "DATE(`{$f_dep_col}`) = ?";
                        $types .= 's'; $params[] = $new_date;

                        // allow either id or code match depending on stored type (numeric or string)
                        if (ctype_digit((string)$src_val)) { $where[] = "`{$f_src_col}` = ?"; $types .= 'i'; $params[] = (int)$src_val; }
                        else { $where[] = "`{$f_src_col}` = ?"; $types .= 's'; $params[] = $src_val; }

                        if (ctype_digit((string)$dst_val)) { $where[] = "`{$f_dst_col}` = ?"; $types .= 'i'; $params[] = (int)$dst_val; }
                        else { $where[] = "`{$f_dst_col}` = ?"; $types .= 's'; $params[] = $dst_val; }

                        $sqlf = "SELECT `{$f_id_col}` AS fid, `{$f_dep_col}` AS dep FROM `{$flight_table}` WHERE " . implode(' AND ', $where) . " ORDER BY `{$f_dep_col}` ASC LIMIT 1";
                        $stmtf = $mysqli->prepare($sqlf);
                        if ($stmtf) {
                            $bind = [$types];
                            for ($i=0;$i<count($params);$i++) {
                                $bind[] = & $params[$i];
                            }
                            call_user_func_array([$stmtf, 'bind_param'], $bind);
                            $stmtf->execute();
                            $ffound = $stmtf->get_result()->fetch_assoc();
                            $stmtf->close();
                            if ($ffound && !empty($ffound['fid'])) {
                                $new_flight = $ffound['fid'];
                            }
                        } // end stmtf
                    } // end if frow
                } // end if frow
            } // end old_flight
        } // end if new_flight empty

        // Now, if we have a new_flight, update bookings (and ticket)
        if ($new_flight !== '') {
            // detect bookings columns
            $bkcols = [];
            $bres = $mysqli->query("SHOW COLUMNS FROM bookings");
            if ($bres) while ($c = $bres->fetch_assoc()) $bkcols[] = $c['Field'];

            $bk_flight_col = in_array('flight_id', $bkcols) ? 'flight_id' : (in_array('flight', $bkcols) ? 'flight' : (in_array('flightid',$bkcols)?'flightid':null));
            $bk_seat_col = in_array('seat_no', $bkcols) ? 'seat_no' : (in_array('seat',$bkcols)?'seat':null);

            $updates = [];
            $uparams = [];
            $utypes = '';

            if ($bk_flight_col) { $updates[] = "`{$bk_flight_col}` = ?"; $utypes .= 's'; $uparams[] = $new_flight; $applied_flight_id = $new_flight; }
            if ($bk_seat_col && $new_seat !== '') { $updates[] = "`{$bk_seat_col}` = ?"; $utypes .= 's'; $uparams[] = $new_seat; }

            if (!empty($updates)) {
                $sqlu = "UPDATE bookings SET " . implode(', ', $updates) . " WHERE booking_id = ? LIMIT 1";
                $stmtu = $mysqli->prepare($sqlu);
                if ($stmtu) {
                    $bind = [];
                    $bind[] = $utypes . 's';
                    for ($i = 0; $i < count($uparams); $i++) {
                        $v = 'up' . $i; $$v = $uparams[$i]; $bind[] = &$$v;
                    }
                    $bind[] = & $booking_id;
                    call_user_func_array([$stmtu, 'bind_param'], $bind);
                    if (! $stmtu->execute()) {
                        dbg("auto update bookings failed: " . $stmtu->error . " SQL: $sqlu");
                    }
                    $stmtu->close();
                } else {
                    dbg("prepare update bookings failed: " . $mysqli->error . " SQL: $sqlu");
                }
            }

            // attempt to update ticket table if it references booking_id OR belongs to this passport + old_flight
            $tcols = [];
            $tres = $mysqli->query("SHOW COLUMNS FROM ticket");
            if ($tres) while ($c = $tres->fetch_assoc()) $tcols[] = $c['Field'];

            if (!empty($tcols)) {
                // if ticket.booking_id exists, use it
                if (in_array('booking_id', $tcols)) {
                    $sqlt = "UPDATE ticket SET flight_id = ?, seat_no = ? WHERE booking_id = ? LIMIT 1";
                    $stmtt = $mysqli->prepare($sqlt);
                    if ($stmtt) {
                        $seat_bind = $new_seat !== '' ? $new_seat : $old_seat;
                        $stmtt->bind_param('sss', $new_flight, $seat_bind, $booking_id);
                        if (! $stmtt->execute()) {
                            dbg("update ticket by booking_id failed: " . $stmtt->error);
                        }
                        $stmtt->close();
                    }
                } else {
                    // fallback: try update ticket rows where passport_no matches and flight_id = old_flight (best-effort)
                    if (in_array('flight_id', $tcols) && in_array('passport_no', $tcols)) {
                        $sqlt2 = "UPDATE ticket SET flight_id = ?, seat_no = ? WHERE passport_no = ? AND flight_id = ? LIMIT 5";
                        $stmtt2 = $mysqli->prepare($sqlt2);
                        if ($stmtt2) {
                            $seat_bind = $new_seat !== '' ? $new_seat : $old_seat;
                            $stmtt2->bind_param('ssss', $new_flight, $seat_bind, $requested_by, $old_flight);
                            if (! $stmtt2->execute()) {
                                dbg("update ticket by passport/flight failed: " . $stmtt2->error);
                            }
                            $stmtt2->close();
                        }
                    }
                }
            }
        } // end if new_flight available

        // If we marked processed_at in tx (use_processed_at) — already inserted with NOW().
        // Also, if we auto-processed but didn't find a flight, log it
        if ($applied_flight_id === null) {
            dbg("Auto-process requested but no matching flight found for booking {$booking_id} on {$new_date}.");
        }
    } // end auto_process

    echo json_encode(['ok'=>true,'insert_id'=>$insert_id,'applied_flight_id'=>$applied_flight_id,'message'=>'Reschedule request recorded.']);
    exit;

} catch (Throwable $e) {
    dbg('Exception reschedule_booking: ' . $e->getMessage());
    echo json_encode(['ok'=>false,'error'=>'Internal server error']);
    exit;
}
