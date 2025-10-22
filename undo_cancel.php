<?php
// /FLIGHT_FRONTEND/undo_cancel.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
safe_start_session();

if (empty($_SESSION['passport_no'])) {
    header('Location: /FLIGHT_FRONTEND/auth/login.php');
    exit;
}

$passport = $_SESSION['passport_no'];
$booking_id = isset($_POST['booking_id']) ? $_POST['booking_id'] : null;
if (!$booking_id || !ctype_digit((string)$booking_id)) {
    $_SESSION['flash_error'] = "Invalid booking id.";
    header('Location: /FLIGHT_FRONTEND/my_bookings.php');
    exit;
}
$booking_id = intval($booking_id);

// fetch booking and refund status
$stmt = $mysqli->prepare("SELECT b.booking_id, b.status, b.refund_id, r.status AS refund_status
                          FROM bookings b
                          LEFT JOIN refunds r ON b.refund_id = r.refund_id
                          WHERE b.booking_id = ? AND b.passport_no = ? LIMIT 1");
$stmt->bind_param('is', $booking_id, $passport);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    $_SESSION['flash_error'] = "Booking not found.";
    header('Location: /FLIGHT_FRONTEND/my_bookings.php');
    exit;
}

if ($row['status'] !== 'cancelled') {
    $_SESSION['flash_message'] = "Booking is not cancelled.";
    header('Location: /FLIGHT_FRONTEND/my_bookings.php');
    exit;
}

// If refund exists and completed, block undo
if (!empty($row['refund_id']) && $row['refund_status'] === 'completed') {
    // Refund already completed — do NOT set a global flash message.
    // The bookings page displays an inline message for this case, so just redirect.
    header('Location: /FLIGHT_FRONTEND/my_bookings.php');
    exit;
}


// allowed: clear refund (if pending) and restore booking
$ok = false;
$mysqli->begin_transaction();

try {
    // if refund exists and pending, delete or mark cancelled
    if (!empty($row['refund_id'])) {
        $rstmt = $mysqli->prepare("DELETE FROM refunds WHERE refund_id = ? AND status = 'pending'");
        if ($rstmt) {
            $rstmt->bind_param('i', $row['refund_id']);
            $rstmt->execute();
            $rstmt->close();
        }
    }

    // restore booking
    $ustab = $mysqli->prepare("UPDATE bookings SET status = 'booked', cancelled_at = NULL, cancelled_by = NULL, refund_id = NULL WHERE booking_id = ? AND passport_no = ?");
    $ustab->bind_param('is', $booking_id, $passport);
    $ustab->execute();
    $ok = $ustab->affected_rows > 0;
    $ustab->close();

    if ($ok) {
        $mysqli->commit();
        $_SESSION['flash_message'] = "Booking reactivated (undo successful).";
    } else {
        $mysqli->rollback();
        $_SESSION['flash_error'] = "Failed to undo cancellation.";
    }
} catch (Exception $e) {
    $mysqli->rollback();
    $_SESSION['flash_error'] = "Error during undo: " . $e->getMessage();
}

header('Location: /FLIGHT_FRONTEND/my_bookings.php');
exit;
