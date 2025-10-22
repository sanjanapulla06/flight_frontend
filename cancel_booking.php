<?php
// /FLIGHT_FRONTEND/cancel_booking.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
safe_start_session();

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

// Ensure integer-like id
if (!ctype_digit((string)$booking_id)) {
    $_SESSION['flash_error'] = "Invalid booking id format.";
    header('Location: /FLIGHT_FRONTEND/my_bookings.php');
    exit;
}
$booking_id = intval($booking_id);

// --- check ownership and current status
$stmt = $mysqli->prepare("SELECT booking_id, status, COALESCE(f.base_price, f.price, 0) AS price FROM bookings b JOIN flights f ON b.flight_id = f.flight_id WHERE b.booking_id = ? AND b.passport_no = ? LIMIT 1");
$stmt->bind_param('is', $booking_id, $passport);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    $_SESSION['flash_error'] = "Booking not found or you don't have permission.";
    header('Location: /FLIGHT_FRONTEND/my_bookings.php');
    exit;
}

if ($booking['status'] === 'cancelled') {
    $_SESSION['flash_message'] = "This booking is already cancelled.";
    header('Location: /FLIGHT_FRONTEND/my_bookings.php');
    exit;
}

// Business rule: prevent cancelling completed/past flights? (optional — adapt)
// Example: don't cancel if departure is in past — you can add check here.

// perform cancel (soft)
$user_who = $_SESSION['name'] ?? $passport;
$stmt = $mysqli->prepare("UPDATE bookings SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ? WHERE booking_id = ? AND passport_no = ?");
$stmt->bind_param('sis', $user_who, $booking_id, $passport);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    $_SESSION['flash_error'] = "Failed to cancel booking: " . htmlspecialchars($mysqli->error);
    header('Location: /FLIGHT_FRONTEND/my_bookings.php');
    exit;
}

// Simulate a refund: create refunds row and mark completed (this is optional business logic)
$refund_amount = floatval($booking['price']) * 0.9; // example: refund 90% (change as needed)
$refund_stmt = $mysqli->prepare("INSERT INTO refunds (booking_id, passport_no, amount, status, processed_at) VALUES (?, ?, ?, 'completed', NOW())");
if ($refund_stmt) {
    $refund_stmt->bind_param('isd', $booking_id, $passport, $refund_amount);
    $refund_stmt->execute();
    $rid = $refund_stmt->insert_id;
    $refund_stmt->close();

    // link refund to booking
    $upd = $mysqli->prepare("UPDATE bookings SET refund_id = ? WHERE booking_id = ?");
    if ($upd) {
        $upd->bind_param('ii', $rid, $booking_id);
        $upd->execute();
        $upd->close();
    }
}

$_SESSION['flash_message'] = "Booking cancelled. Refund processed (simulated).";
header('Location: /FLIGHT_FRONTEND/my_bookings.php');
exit;
