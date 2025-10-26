<?php
// /FLIGHT_FRONTEND/book_flight.php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/header.php';

// Require login
if (empty($_SESSION['passport_no'])) {
    $return = urlencode($_SERVER['REQUEST_URI']);
    header("Location: /FLIGHT_FRONTEND/auth/login.php?return={$return}");
    exit;
}

$flight_id = trim($_GET['flight_id'] ?? '');
if ($flight_id === '') {
    $_SESSION['flash_error'] = 'No flight selected for booking.';
    header('Location: /FLIGHT_FRONTEND/search.php');
    exit;
}

// Detect flight table
$flight_table = 'flight';
if ($mysqli->query("SHOW TABLES LIKE 'flights'")->num_rows > 0) {
    $flight_table = 'flights';
}

// Fetch flight details
$sql = "
SELECT f.flight_id, 
       COALESCE(f.base_price, f.price, 0) AS price,
       src.airport_code AS src_code, src.airport_name AS src_name,
       dst.airport_code AS dst_code, dst.airport_name AS dst_name,
       a.airline_name,
       f.departure_time, f.arrival_time
FROM {$flight_table} f
LEFT JOIN airport src ON f.source_id = src.airport_id
LEFT JOIN airport dst ON f.destination_id = dst.airport_id
LEFT JOIN airline a ON f.airline_id = a.airline_id
WHERE f.flight_id = ? LIMIT 1";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('s', $flight_id);
$stmt->execute();
$flight = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$flight) {
    $_SESSION['flash_error'] = 'Flight not found.';
    header('Location: /FLIGHT_FRONTEND/search.php');
    exit;
}

// Booker details
$passport_no = $_SESSION['passport_no'];
$stmt2 = $mysqli->prepare("SELECT name, email, phone FROM passenger WHERE passport_no = ? LIMIT 1");
$stmt2->bind_param('s', $passport_no);
$stmt2->execute();
$booker = $stmt2->get_result()->fetch_assoc();
$stmt2->close();
?>

<div class="container my-4">
  <h2 class="mb-3">Book Flight — <small class="text-primary"><?php echo htmlspecialchars($flight['flight_id']); ?></small></h2>

  <div class="card mb-3">
    <div class="card-body">
      <h5><?php echo htmlspecialchars($flight['src_code'] . ' → ' . $flight['dst_code']); ?> 
        <small class="text-muted">| <?php echo htmlspecialchars($flight['airline_name']); ?></small></h5>
      <div class="text-muted small">
        Departure: <?php echo date('M d, Y H:i', strtotime($flight['departure_time'])); ?> |
        Arrival: <?php echo date('M d, Y H:i', strtotime($flight['arrival_time'])); ?> |
        Price (per seat): ₹<?php echo number_format($flight['price'], 2); ?>
      </div>
    </div>
  </div>

  <form id="bookForm" method="post" class="row g-3">
    <input type="hidden" name="flight_id" value="<?php echo htmlspecialchars($flight['flight_id']); ?>">

    <div id="passengerList">
      <div class="passenger-block border rounded p-3 mb-3">
        <h5 class="text-primary">Passenger 1</h5>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Full Name *</label>
            <input name="passengers[0][name]" class="form-control" value="<?php echo htmlspecialchars($booker['name'] ?? ''); ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Gender</label>
            <select name="passengers[0][gender]" class="form-control">
              <option value="">--</option>
              <option value="M">M</option>
              <option value="F">F</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Seat No *</label>
            <input name="passengers[0][seat_no]" class="form-control" placeholder="e.g. 12A" required>
          </div>
        </div>
      </div>
    </div>

    <div>
      <button type="button" class="btn btn-outline-primary" id="addPassengerBtn">➕ Add another passenger</button>
      <button type="button" class="btn btn-outline-danger" id="removePassengerBtn" style="display:none;">➖ Remove last</button>
    </div>

    <div class="col-md-4">
      <label class="form-label">Class</label>
      <select name="class" class="form-control">
        <option value="Economy">Economy</option>
        <option value="Business">Business</option>
      </select>
    </div>

    <div class="col-12">
      <button id="submitBtn" class="btn btn-success" type="submit">Confirm Booking</button>
      <a href="/FLIGHT_FRONTEND/search.php" class="btn btn-secondary">Back</a>
    </div>
  </form>

  <div id="bookResult" class="mt-4"></div>
</div>

<script>
(() => {
  let passengerCount = 1;
  const list = document.getElementById('passengerList');
  const addBtn = document.getElementById('addPassengerBtn');
  const removeBtn = document.getElementById('removePassengerBtn');
  const form = document.getElementById('bookForm');
  const resultDiv = document.getElementById('bookResult');
  const submitBtn = document.getElementById('submitBtn');

  addBtn.addEventListener('click', () => {
    passengerCount++;
    const idx = passengerCount - 1;
    const block = document.createElement('div');
    block.className = 'passenger-block border rounded p-3 mb-3';
    block.innerHTML = `
      <h5 class="text-primary">Passenger ${passengerCount}</h5>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Full Name *</label>
          <input name="passengers[${idx}][name]" class="form-control" required></div>
        <div class="col-md-3"><label class="form-label">Gender</label>
          <select name="passengers[${idx}][gender]" class="form-control">
            <option value="">--</option><option value="M">M</option><option value="F">F</option>
          </select></div>
        <div class="col-md-3"><label class="form-label">Seat No *</label>
          <input name="passengers[${idx}][seat_no]" class="form-control" required></div>
      </div>`;
    list.appendChild(block);
    removeBtn.style.display = 'inline-block';
  });

  removeBtn.addEventListener('click', () => {
    if (passengerCount <= 1) return;
    list.lastElementChild.remove();
    passengerCount--;
    if (passengerCount === 1) removeBtn.style.display = 'none';
  });

  form.addEventListener('submit', async e => {
    e.preventDefault();
    resultDiv.innerHTML = '';
    submitBtn.disabled = true;
    submitBtn.textContent = 'Booking...';

    const fd = new FormData(form);
    try {
      const res = await fetch('/FLIGHT_FRONTEND/api/book_ticket.php', { method:'POST', body:fd, credentials:'include' });
      const raw = await res.text();
      let data;
      try { data = JSON.parse(raw); } catch { data = null; }
      if (!data || !data.ok) {
        resultDiv.innerHTML = `<div class="alert alert-danger"><pre>${raw}</pre></div>`;
      } else {
        const tickets = data.tickets.map(t => `<li>${t}</li>`).join('');
        resultDiv.innerHTML = `<div class="alert alert-success">
          ✅ Booking successful!<br>
          Total passengers: ${data.num_passengers}<br>
          Total amount: ₹${data.total_amount}<br>
          Tickets:<ul>${tickets}</ul>
        </div>`;
      }
    } catch (err) {
      resultDiv.innerHTML = `<div class="alert alert-danger">${err}</div>`;
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Confirm Booking';
    }
  });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
