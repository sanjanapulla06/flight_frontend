<?php
// book_flight.php
if (session_status() === PHP_SESSION_NONE) session_start();

// require login (preserve return)
if (empty($_SESSION['passport_no'])) {
    $return = urlencode($_SERVER['REQUEST_URI']);
    header("Location: /FLIGHT_FRONTEND/auth/login.php?return={$return}");
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/header.php';

// get flight_id from GET (string!)
$flight_id = isset($_GET['flight_id']) ? trim((string)$_GET['flight_id']) : '';
if ($flight_id === '') {
    $_SESSION['flash_error'] = 'No flight selected for booking.';
    header('Location: /FLIGHT_FRONTEND/search.php');
    exit;
}

// detect which flights table exists: prefer "flight", fallback to "flights"
$flight_table = 'flight';
$chk = $mysqli->query("SHOW TABLES LIKE 'flight'");
if (!$chk || $chk->num_rows === 0) {
    $chk2 = $mysqli->query("SHOW TABLES LIKE 'flights'");
    if ($chk2 && $chk2->num_rows > 0) $flight_table = 'flights';
}

// fetch flight details from `flight` (or `flights`) table and airport names
$sql = "
    SELECT f.flight_id, f.departure_time, f.arrival_time, COALESCE(f.base_price, f.price, 0) AS price,
           src.airport_code AS src_code, src.airport_name AS src_name,
           dst.airport_code AS dst_code, dst.airport_name AS dst_name,
           a.airline_name
    FROM {$flight_table} f
    LEFT JOIN airport src ON f.source_id = src.airport_id
    LEFT JOIN airport dst ON f.destination_id = dst.airport_id
    LEFT JOIN airline a ON f.airline_id = a.airline_id
    WHERE f.flight_id = ? LIMIT 1
";
$stmt = $mysqli->prepare($sql);
if ($stmt) {
    $stmt->bind_param('s', $flight_id);
    $stmt->execute();
    $flight = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    $_SESSION['flash_error'] = 'DB error: ' . $mysqli->error;
    header('Location: /FLIGHT_FRONTEND/search.php');
    exit;
}

if (!$flight) {
    $_SESSION['flash_error'] = 'Flight not found.';
    header('Location: /FLIGHT_FRONTEND/search.php');
    exit;
}

// fetch passenger info for autofill
$passport_no = $_SESSION['passport_no'];
$passenger = [];
$stmt2 = $mysqli->prepare("SELECT passport_no, name, email, phone, address, gender, dob FROM passenger WHERE passport_no = ? LIMIT 1");
if ($stmt2) {
    $stmt2->bind_param('s', $passport_no);
    $stmt2->execute();
    $passenger = $stmt2->get_result()->fetch_assoc() ?: [];
    $stmt2->close();
}
?>

<div class="container my-4">
  <h2 class="mb-3">Book Flight — <small class="text-primary"><?php echo htmlspecialchars($flight['flight_id']); ?></small></h2>

  <div class="card mb-3">
    <div class="card-body">
      <h5 class="mb-1"><?php echo htmlspecialchars($flight['src_code'] . ' → ' . $flight['dst_code']); ?> <small class="text-muted">| <?php echo htmlspecialchars($flight['airline_name'] ?? ''); ?></small></h5>
      <div class="text-muted small">
        Departure: <?php echo date('M d, Y H:i', strtotime($flight['departure_time'])); ?> &nbsp;|&nbsp;
        Arrival: <?php echo date('M d, Y H:i', strtotime($flight['arrival_time'])); ?> &nbsp;|&nbsp;
        Price: <strong>₹<?php echo number_format($flight['price'], 2); ?></strong>
      </div>
    </div>
  </div>

  <form id="bookForm" class="row g-3" autocomplete="off" method="post">
    <input type="hidden" name="flight_id" value="<?php echo htmlspecialchars($flight['flight_id'], ENT_QUOTES); ?>" />

    <div class="col-md-6">
      <label class="form-label">Passport No *</label>
      <input name="passport_no" class="form-control" value="<?php echo htmlspecialchars($passenger['passport_no'] ?? $passport_no, ENT_QUOTES); ?>" readonly />
    </div>

    <div class="col-md-6">
      <label class="form-label">Full Name *</label>
      <input name="name" class="form-control" value="<?php echo htmlspecialchars($passenger['name'] ?? '', ENT_QUOTES); ?>" required />
    </div>

    <div class="col-md-6">
      <label class="form-label">Email</label>
      <input name="email" type="email" class="form-control" value="<?php echo htmlspecialchars($passenger['email'] ?? '', ENT_QUOTES); ?>" />
    </div>

    <div class="col-md-6">
      <label class="form-label">Phone</label>
      <input name="phone" class="form-control" value="<?php echo htmlspecialchars($passenger['phone'] ?? '', ENT_QUOTES); ?>" />
    </div>

    <div class="col-md-6">
      <label class="form-label">Address</label>
      <input name="address" class="form-control" value="<?php echo htmlspecialchars($passenger['address'] ?? '', ENT_QUOTES); ?>" />
    </div>

    <div class="col-md-3">
      <label class="form-label">Gender</label>
      <select name="gender" class="form-control">
        <option value="">--</option>
        <option value="M" <?php echo (($passenger['gender'] ?? '') === 'M') ? 'selected' : ''; ?>>M</option>
        <option value="F" <?php echo (($passenger['gender'] ?? '') === 'F') ? 'selected' : ''; ?>>F</option>
      </select>
    </div>

    <div class="col-md-3">
      <label class="form-label">DOB</label>
      <input name="dob" type="date" class="form-control" value="<?php echo htmlspecialchars($passenger['dob'] ?? '', ENT_QUOTES); ?>" />
    </div>

    <div class="col-md-3">
      <label class="form-label">Seat No *</label>
      <input name="seat_no" class="form-control" placeholder="e.g. 12A" required />
    </div>

    <div class="col-md-3">
      <label class="form-label">Class</label>
      <select name="class" class="form-control">
        <option value="Economy">Economy</option>
        <option value="Business">Business</option>
      </select>
    </div>

    <div class="col-12">
      <button id="submitBtn" class="btn btn-success" type="submit">Confirm Booking</button>
      <a class="btn btn-secondary" href="/FLIGHT_FRONTEND/search.php">Back</a>
    </div>
  </form>

  <div id="bookResult" class="mt-3"></div>
</div>

<script>
(function(){
  const form = document.getElementById('bookForm');
  const resultDiv = document.getElementById('bookResult');
  const submitBtn = document.getElementById('submitBtn');

  // small helper to escape HTML when showing server text
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]; });
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    resultDiv.innerHTML = '';
    submitBtn.disabled = true;
    submitBtn.textContent = 'Booking...';

    const seat = (form.seat_no.value || '').trim();
    if (!/^[A-Za-z0-9\-\s]+$/.test(seat)) {
      resultDiv.innerHTML = '<div class="alert alert-danger">Invalid seat format.</div>';
      submitBtn.disabled = false;
      submitBtn.textContent = 'Confirm Booking';
      return;
    }

    const fd = new FormData(form);

    try {
      const resp = await fetch('/FLIGHT_FRONTEND/api/book_ticket.php', {
        method: 'POST',
        credentials: 'include',
        body: fd,
        cache: 'no-cache'
      });

      // always show raw server body for debugging clarity
      const raw = await resp.text();
      let data = null;
      try { data = JSON.parse(raw); } catch(e) { data = null; }

      console.log('book_ticket.php status', resp.status, resp.statusText);
      console.log('book_ticket raw response:', raw);

      if (!resp.ok) {
        const msg = (data && (data.error || data.message)) || raw || ('Server error: ' + resp.status);
        resultDiv.innerHTML = `<div class="alert alert-danger"><pre style="white-space:pre-wrap">${escapeHtml(msg)}</pre></div>`;
        submitBtn.disabled = false;
        submitBtn.textContent = 'Confirm Booking';
        return;
      }

      if (data && data.ok) {
        resultDiv.innerHTML = `<div class="alert alert-success">
            Booking successful! <br>
            Ticket: <strong>${escapeHtml(data.ticket_no)}</strong><br>
            Seat: <strong>${escapeHtml(data.seat_no || '')}</strong><br>
            Class: <strong>${escapeHtml(data.class || '')}</strong><br>
            Price: <strong>₹${Number(data.price).toLocaleString()}</strong>
          </div>`;
      } else {
        const msg = (data && (data.error || data.message)) || raw || 'Booking failed';
        resultDiv.innerHTML = `<div class="alert alert-danger"><pre style="white-space:pre-wrap">${escapeHtml(msg)}</pre></div>`;
      }
    } catch (err) {
      console.error('Fetch error:', err);
      resultDiv.innerHTML = `<div class="alert alert-danger">Request failed. Check console for details. ${escapeHtml(String(err))}</div>`;
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Confirm Booking';
    }
  });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
