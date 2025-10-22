<?php
// seats.php
// Shows seat map for a flight and prevents duplicate session_start() notices.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php'; // optional, if you use safe_start_session() there
require_once __DIR__ . '/includes/header.php';

// read flight_id from GET (allow numeric only)
$flight_id = isset($_GET['flight_id']) ? intval($_GET['flight_id']) : 0;
if (!$flight_id) {
    // nicer UX than plain text — redirect back to search with flash message
    $_SESSION['flash_error'] = 'No flight selected. Please choose a flight from Search.';
    header('Location: /FLIGHT_FRONTEND/search.php');
    exit;
}

// fetch flight details
$stmt = $mysqli->prepare("
    SELECT flight_id, flight_code, source, destination, departure_time, arrival_time, COALESCE(base_price, price, 0) AS price
    FROM flights
    WHERE flight_id = ? LIMIT 1
");
if (!$stmt) {
    // DB prepare problem — show friendly error
    echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($mysqli->error) . '</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
$stmt->bind_param('i', $flight_id);
$stmt->execute();
$flight = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$flight) {
    $_SESSION['flash_error'] = 'Flight not found.';
    header('Location: /FLIGHT_FRONTEND/search.php');
    exit;
}

// OPTIONAL: fetch existing bookings/seats for the flight (to mark occupied seats)
$occupied = [];
$seatQuery = $mysqli->prepare("SELECT seat_no, class FROM bookings WHERE flight_id = ?");
if ($seatQuery) {
    $seatQuery->bind_param('i', $flight_id);
    $seatQuery->execute();
    $res = $seatQuery->get_result();
    while ($r = $res->fetch_assoc()) {
        // store as e.g. ['12A' => 'Economy']
        $occupied[strtoupper(trim($r['seat_no']))] = $r['class'] ?? 'Economy';
    }
    $seatQuery->close();
}

// render a simple seat map placeholder (you can replace with nicer UI)
?>
<div class="container my-4">
  <h2 class="mb-3">Seats — <small class="text-primary"><?php echo htmlspecialchars($flight['flight_code']); ?></small></h2>

  <div class="card mb-3">
    <div class="card-body">
      <h5><?php echo htmlspecialchars($flight['source']); ?> → <?php echo htmlspecialchars($flight['destination']); ?></h5>
      <div class="text-muted small">
        Departure: <?php echo date('M d, Y H:i', strtotime($flight['departure_time'])); ?> &nbsp;|&nbsp;
        Arrival: <?php echo date('M d, Y H:i', strtotime($flight['arrival_time'])); ?> &nbsp;|&nbsp;
        Price: <strong>₹<?php echo number_format($flight['price'], 2); ?></strong>
      </div>
    </div>
  </div>

  <p class="mb-3">Choose a seat (click a free seat to prefill on the booking page)</p>

  <div id="seatMap" class="mb-4">
    <?php
    // simple grid demo: rows 1..20, cols A..F (adjust to $flight['tot_seat'] if you have seat layout)
    $rows = 20;
    $cols = ['A','B','C','D','E','F'];

    echo '<div class="d-grid gap-2">';
    for ($r = 1; $r <= $rows; $r++) {
        echo '<div class="d-flex gap-2 mb-2">';
        foreach ($cols as $c) {
            $s = $r . $c;
            $uc = strtoupper($s);
            if (isset($occupied[$uc])) {
                // taken
                $class = 'btn btn-sm btn-outline-danger disabled';
                $title = 'Occupied (' . htmlspecialchars($occupied[$uc]) . ')';
                echo "<button class='$class' title='$title' data-seat='$uc'>$uc</button>";
            } else {
                // free
                echo "<button class='btn btn-sm btn-outline-success seat-btn' data-seat='$uc'>$uc</button>";
            }
        }
        echo '</div>';
    }
    echo '</div>';
    ?>
  </div>

  <a id="bookLinkBtn" class="btn btn-primary" href="/FLIGHT_FRONTEND/book_flight.php?flight_id=<?php echo (int)$flight_id; ?>">Book Selected Seat</a>
  <a class="btn btn-secondary ms-2" href="/FLIGHT_FRONTEND/search.php">Back to Search</a>
</div>

<script>
  // client: click seat to save selection and update Book link with seat_no & class (default Economy)
  (function(){
    let selected = null;
    const seatButtons = document.querySelectorAll('.seat-btn');
    const bookLink = document.getElementById('bookLinkBtn');

    seatButtons.forEach(btn => {
      btn.addEventListener('click', function(){
        // deselect previous
        seatButtons.forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        selected = this.getAttribute('data-seat');
        // update book link to include seat_no param
        const url = new URL(bookLink.href, window.location.origin);
        url.searchParams.set('seat_no', selected);
        url.searchParams.set('class', 'Economy'); // default; user can change on booking page
        bookLink.href = url.toString();
      });
    });
  })();
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
