<?php
// my_bookings.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
safe_start_session();

require_once __DIR__ . '/includes/header.php';

// must be logged in
if (empty($_SESSION['passport_no'])) {
    header('Location: /FLIGHT_FRONTEND/auth/login.php?return=' . urlencode('/FLIGHT_FRONTEND/my_bookings.php'));
    exit;
}

$passport_no = $_SESSION['passport_no'];

// ✅ Fetch bookings with possible refund info
$sql = "SELECT b.booking_id, b.booking_date, b.status, b.refund_id, b.cancelled_at,
               f.flight_code, f.source, f.destination, f.departure_time, f.arrival_time, 
               COALESCE(f.base_price, f.price, 0) AS price,
               r.status AS refund_status
        FROM bookings b
        JOIN flights f ON b.flight_id = f.flight_id
        LEFT JOIN refunds r ON b.refund_id = r.refund_id
        WHERE b.passport_no = ?
        ORDER BY b.booking_date DESC";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param('s', $passport_no);
$stmt->execute();
$res = $stmt->get_result();
?>

<div class="row justify-content-center">
  <div class="col-md-10">
    <h3 class="mb-3">✈️ My Bookings</h3>

    <?php if (!empty($_SESSION['flash_message'])): ?>
      <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
    <?php endif; ?>

    <?php if ($res->num_rows === 0): ?>
      <div class="alert alert-info">You haven’t booked any flights yet.</div>
    <?php else: ?>
      <table class="table table-striped align-middle">
        <thead class="table-primary">
          <tr>
            <th>Booking ID</th>
            <th>Flight</th>
            <th>Route</th>
            <th>Departure</th>
            <th>Booked On</th>
            <th>Status / Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($row = $res->fetch_assoc()): ?>
            <tr>
              <td><?= htmlspecialchars($row['booking_id']); ?></td>
              <td><?= htmlspecialchars($row['flight_code']); ?></td>
              <td><?= htmlspecialchars($row['source']) . ' → ' . htmlspecialchars($row['destination']); ?></td>
              <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($row['departure_time']))); ?></td>
              <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($row['booking_date']))); ?></td>
              <td>
                <?php if ($row['status'] === 'cancelled'): ?>
                  <span class="badge bg-danger">Cancelled</span>

                  <?php if (!empty($row['refund_id'])): ?>
                    <div class="small text-muted">
                      Refund ID: <?= htmlspecialchars($row['refund_id']); ?>  
                      (<?= htmlspecialchars(ucfirst($row['refund_status'] ?? 'pending')); ?>)
                    </div>
                  <?php endif; ?>

                  <?php if ($row['refund_status'] === 'completed'): ?>
                    <div class="text-muted small mt-1">
                      ❌ Refund completed — cannot undo.
                    </div>
                  <?php else: ?>
                    <form action="/FLIGHT_FRONTEND/undo_cancel.php" method="POST" style="display:inline;">
                      <input type="hidden" name="booking_id" value="<?= htmlspecialchars($row['booking_id']); ?>">
                      <button type="submit" class="btn btn-sm btn-outline-success mt-1"
                        onclick="return confirm('Reactivate this cancelled booking?')">Undo Cancel</button>
                    </form>
                  <?php endif; ?>

                <?php else: ?>
                  <span class="badge bg-success"><?= htmlspecialchars(ucfirst($row['status'])); ?></span>
                  <div class="mt-1 d-flex flex-wrap align-items-center gap-1">
                    <a class="btn btn-sm btn-primary" 
                       href="/FLIGHT_FRONTEND/e_ticket.php?booking_id=<?= urlencode($row['booking_id']) ?>">View E-ticket</a>

                    <form action="/FLIGHT_FRONTEND/cancel_booking.php" method="POST" style="display:inline;">
                      <input type="hidden" name="booking_id" value="<?= htmlspecialchars($row['booking_id']); ?>">
                      <button type="submit" class="btn btn-sm btn-danger"
                        onclick="return confirm('Are you sure you want to cancel this booking?')">
                        Cancel Ticket
                      </button>
                    </form>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    <?php endif; ?>

  </div>
</div>

<?php
$stmt->close();
require_once __DIR__ . '/includes/footer.php';
?>
