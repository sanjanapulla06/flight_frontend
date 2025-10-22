<?php
// book_flight.php
// Booking UI page. Submits to /api/book_ticket.php via fetch (POST).

if (session_status() === PHP_SESSION_NONE) session_start();

// require login (preserve return)
if (empty($_SESSION['passport_no'])) {
    $return = urlencode($_SERVER['REQUEST_URI']);
    header("Location: /FLIGHT_FRONTEND/auth/login.php?return={$return}");
    exit;
}

require_once __DIR__ . '/includes/db.php';      // <--- ensure this path exists
require_once __DIR__ . '/includes/helpers.php'; // safe_start_session() if used
//safe_start_session(); // optional if helpers provides it

// include header for nav etc.
require_once __DIR__ . '/includes/header.php';

// get integer flight id from GET
$flight_id = isset($_GET['flight_id']) ? (int)$_GET['flight_id'] : 0;
if (!$flight_id) {
    $_SESSION['flash_error'] = 'No flight selected for booking.';
    header('Location: /FLIGHT_FRONTEND/search.php');
    exit;
}

// fetch flight details
$flight = null;
$stmt = $mysqli->prepare("
    SELECT flight_id, flight_code, source, destination, departure_time, arrival_time, COALESCE(base_price, price, 0) AS price
    FROM flights
    WHERE flight_id = ? LIMIT 1
");
if ($stmt) {
    $stmt->bind_param('i', $flight_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $flight = $res->fetch_assoc();
    $stmt->close();
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
    $res2 = $stmt2->get_result();
    $passenger = $res2->fetch_assoc() ?: [];
    $stmt2->close();
}

// optional: prefill seat/class from GET (if you have seat map redirect)
$pref_seat = htmlspecialchars($_GET['seat_no'] ?? '', ENT_QUOTES);
$pref_class = htmlspecialchars($_GET['class'] ?? '', ENT_QUOTES);
?>

<div class="container my-4">
  <h2 class="mb-3">Book Flight — <small class="text-primary"><?php echo htmlspecialchars($flight['flight_code']); ?></small></h2>

  <div class="card mb-3">
    <div class="card-body">
      <h5 class="mb-1"><?php echo htmlspecialchars($flight['source']); ?> → <?php echo htmlspecialchars($flight['destination']); ?></h5>
      <div class="text-muted small">
        Departure: <?php echo date('M d, Y H:i', strtotime($flight['departure_time'])); ?> &nbsp;|&nbsp;
        Arrival: <?php echo date('M d, Y H:i', strtotime($flight['arrival_time'])); ?> &nbsp;|&nbsp;
        Price: <strong>₹<?php echo number_format($flight['price'], 2); ?></strong>
      </div>
    </div>
  </div>

  <form id="bookForm" class="row g-3" autocomplete="off" method="post">
    <input type="hidden" name="flight_id" value="<?php echo (int)$flight['flight_id']; ?>" />

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
      <label class="form-label">Seat No</label>
      <input name="seat_no" class="form-control" placeholder="e.g. A-3 or 12A" required value="<?php echo $pref_seat; ?>" />
    </div>

    <div class="col-md-3">
      <label class="form-label">Class</label>
      <select name="class" class="form-control">
        <option value="Economy" <?php if($pref_class==='Economy') echo 'selected'; ?>>Economy</option>
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

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    resultDiv.innerHTML = '';
    submitBtn.disabled = true;
    submitBtn.textContent = 'Booking...';

    // simple client-side validation: seat format
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

      if (!resp.ok) {
        const txt = await resp.text().catch(()=>null);
        console.error('book_ticket.php error', resp.status, txt);
        let parsed = null;
        try { parsed = JSON.parse(txt); } catch(e){}
        const msg = parsed?.error || parsed?.message || ('Server error: ' + resp.status);
        resultDiv.innerHTML = `<div class="alert alert-danger">${msg}</div>`;
        submitBtn.disabled = false;
        submitBtn.textContent = 'Confirm Booking';
        return;
      }

      const data = await resp.json();
      if (data.ok) {
        resultDiv.innerHTML = `<div class="alert alert-success">
            Booking successful! <br>
            Ticket: <strong>${data.ticket_no}</strong><br>
            Seat: <strong>${data.seat_no}</strong><br>
            Class: <strong>${data.class}</strong><br>
            Price: <strong>₹${Number(data.price).toLocaleString()}</strong>
          </div>`;
      } else {
        resultDiv.innerHTML = `<div class="alert alert-danger">${data.error || data.message || 'Booking failed'}</div>`;
      }
    } catch (err) {
      console.error('Fetch error:', err);
      resultDiv.innerHTML = `<div class="alert alert-danger">Request failed. Check console for details.</div>`;
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Confirm Booking';
    }
  });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
