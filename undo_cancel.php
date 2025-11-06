<?php
// /FLIGHT_FRONTEND/undo_cancel.php
// Schema-aware "undo cancellation" endpoint.
// Restores a cancelled booking if refund not completed (and optionally clears pending refund).
// Defensive: works when bookings.refund_id or refunds table do not exist.

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
safe_start_session();

// tiny logger for debugging (optional)
function ulog($m) {
    @file_put_contents(__DIR__ . '/tools/undo_cancel.log', "[".date('Y-m-d H:i:s')."] ".$m.PHP_EOL, FILE_APPEND);
}

// require login
if (empty($_SESSION['passport_no'])) {
    header('Location: /FLIGHT_FRONTEND/auth/login.php');
    exit;
}

$passport = $_SESSION['passport_no'];
$booking_id = trim((string)($_POST['booking_id'] ?? ''));

// booking_id in your DB is not numeric (e.g. BA251029...), so do NOT require digits
if ($booking_id === '') {
    $_SESSION['flash_error'] = "Invalid booking id.";
    header('Location: /FLIGHT_FRONTEND/my_bookings.php');
    exit;
}

// helper to fetch columns of a table
function get_table_columns(mysqli $m, string $table): array {
    $cols = [];
    $q = $m->query("SHOW COLUMNS FROM `".$m->real_escape_string($table)."`");
    if ($q) {
        while ($row = $q->fetch_assoc()) $cols[] = $row['Field'];
    }
    return $cols;
}

try {
    // discover schema features
    $bookCols = get_table_columns($mysqli, 'bookings');
    $has_refund_id_col = in_array('refund_id', $bookCols, true);

    // check if refunds table exists and if so, its pk/name
    $refunds_table_exists = false;
    $refunds_cols = [];
    $res = $mysqli->query("SHOW TABLES LIKE 'refunds'");
    if ($res && $res->num_rows > 0) {
        $refunds_table_exists = true;
        $refunds_cols = get_table_columns($mysqli, 'refunds');
    }

    // Build SELECT depending on whether bookings.refund_id exists and refunds table exists.
    if ($has_refund_id_col && $refunds_table_exists && in_array('refund_id', $refunds_cols, true) && in_array('status', $refunds_cols, true)) {
        // join refunds to fetch refund status
        $sql = "SELECT b.booking_id, b.status, b.refund_id, r.status AS refund_status
                FROM bookings b
                LEFT JOIN refunds r ON b.refund_id = r.refund_id
                WHERE b.booking_id = ? AND b.passport_no = ? LIMIT 1";
    } elseif ($has_refund_id_col) {
        // refund_id exists but refunds table doesn't — still select refund_id (no refund_status)
        $sql = "SELECT b.booking_id, b.status, b.refund_id, NULL AS refund_status
                FROM bookings b
                WHERE b.booking_id = ? AND b.passport_no = ? LIMIT 1";
    } else {
        // no refund_id in bookings — select minimal info
        $sql = "SELECT booking_id, status, NULL AS refund_id, NULL AS refund_status
                FROM bookings
                WHERE booking_id = ? AND passport_no = ? LIMIT 1";
    }

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        ulog("prepare failed: " . $mysqli->error . " SQL: $sql");
        $_SESSION['flash_error'] = "Server error. Try again later.";
        header('Location: /FLIGHT_FRONTEND/my_bookings.php');
        exit;
    }

    // bind as strings (booking_id is string)
    $stmt->bind_param('ss', $booking_id, $passport);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $_SESSION['flash_error'] = "Booking not found.";
        header('Location: /FLIGHT_FRONTEND/my_bookings.php');
        exit;
    }

    // only cancelled bookings allowed
    if (strtolower((string)$row['status']) !== 'cancelled' && strtolower((string)$row['status']) !== 'canceled') {
        $_SESSION['flash_message'] = "Booking is not cancelled.";
        header('Location: /FLIGHT_FRONTEND/my_bookings.php');
        exit;
    }

    // if refunds are present and refund completed, block undo
    $refund_id = $row['refund_id'] ?? null;
    $refund_status = $row['refund_status'] ?? null;
    if (!empty($refund_id) && $refunds_table_exists && strtolower((string)$refund_status) === 'completed') {
        // can't undo — refund already completed
        // keep behavior: no global flash; page shows inline message. But for safety add a small flash
        $_SESSION['flash_error'] = "Cannot undo: refund already completed.";
        header('Location: /FLIGHT_FRONTEND/my_bookings.php');
        exit;
    }

    // Proceed with undo: within a transaction, clear pending refund (if any) and restore booking row
    $mysqli->begin_transaction();

    try {
        // If refund exists and is pending (or refunds table lacks status column), attempt to delete or mark cancelled.
        if (!empty($refund_id) && $refunds_table_exists) {
            // If refunds table has 'status' column, only delete pending ones; else delete by id
            if (in_array('status', $refunds_cols, true)) {
                $rstmt = $mysqli->prepare("DELETE FROM refunds WHERE refund_id = ? AND status = 'pending'");
                if ($rstmt) {
                    $rstmt->bind_param('i', $refund_id);
                    $rstmt->execute();
                    // non-fatal if 0 rows affected (means refund not pending) — we'll block later
                    $rstmt->close();
                } else {
                    ulog("prepare refund delete failed: " . $mysqli->error);
                }
            } else {
                // best-effort: delete by id
                $rstmt = $mysqli->prepare("DELETE FROM refunds WHERE refund_id = ?");
                if ($rstmt) {
                    $rstmt->bind_param('i', $refund_id);
                    $rstmt->execute();
                    $rstmt->close();
                } else {
                    ulog("prepare refund delete (no status) failed: " . $mysqli->error);
                }
            }
        }

        // Build update for bookings. If refund_id column exists, clear it too; else update only status/cancelled_at/cancelled_by
        $updateParts = [];
        $params = [];
        $types = '';

        // set status back to booked (string)
        if (in_array('status', $bookCols, true)) {
            $updateParts[] = "status = ?";
            $types .= 's';
            $params[] = 'booked';
        }
        // clear cancelled_at if exists
        if (in_array('cancelled_at', $bookCols, true)) {
            $updateParts[] = "cancelled_at = NULL";
        }
        // clear cancelled_by if exists
        if (in_array('cancelled_by', $bookCols, true)) {
            $updateParts[] = "cancelled_by = NULL";
        }
        // clear refund_id if exists
        if ($has_refund_id_col) {
            $updateParts[] = "refund_id = NULL";
        }

        if (empty($updateParts)) {
            // nothing to update — fail safe
            $mysqli->rollback();
            $_SESSION['flash_error'] = "Server misconfiguration: bookings table lacks updatable columns.";
            header('Location: /FLIGHT_FRONTEND/my_bookings.php');
            exit;
        }

        $sqlUp = "UPDATE bookings SET " . implode(', ', $updateParts) . " WHERE booking_id = ? AND passport_no = ? LIMIT 1";
        $upStmt = $mysqli->prepare($sqlUp);
        if (!$upStmt) {
            ulog("prepare update bookings failed: " . $mysqli->error . " SQL: $sqlUp");
            $mysqli->rollback();
            $_SESSION['flash_error'] = "Server error updating booking.";
            header('Location: /FLIGHT_FRONTEND/my_bookings.php');
            exit;
        }

        // bind params dynamically: first any SET params, then booking_id and passport (both strings)
        $bind_values = [];
        $bind_types = $types . 'ss'; // types for SET params + booking_id + passport
        foreach ($params as $p) $bind_values[] = $p;
        $bind_values[] = $booking_id;
        $bind_values[] = $passport;

        // use call_user_func_array for bind_param
        $refs = [];
        $refs[] = $bind_types;
        foreach ($bind_values as $key => $val) {
            $refs[] = &$bind_values[$key];
        }
        call_user_func_array([$upStmt, 'bind_param'], $refs);

        $upStmt->execute();
        $affected = $upStmt->affected_rows;
        $upStmt->close();

        if ($affected < 1) {
            // nothing changed — treat as failure
            $mysqli->rollback();
            $_SESSION['flash_error'] = "Failed to undo cancellation (no rows updated).";
            header('Location: /FLIGHT_FRONTEND/my_bookings.php');
            exit;
        }

        $mysqli->commit();
        $_SESSION['flash_message'] = "Booking reactivated (undo successful).";

    } catch (Throwable $e2) {
        $mysqli->rollback();
        ulog("Exception while undoing: " . $e2->getMessage());
        $_SESSION['flash_error'] = "Error during undo: " . $e2->getMessage();
    }

    header('Location: /FLIGHT_FRONTEND/my_bookings.php');
    exit;

} catch (Throwable $e) {
    ulog("Top-level exception: " . $e->getMessage());
    $_SESSION['flash_error'] = "Internal server error.";
    header('Location: /FLIGHT_FRONTEND/my_bookings.php');
    exit;
}
