<?php
// FLIGHT_FRONTEND/index.php
require_once __DIR__ . '/includes/header.php';
?>

<div class="row gx-4">
  <!-- LEFT FILTER COLUMN -->
  <aside class="col-lg-3 d-none d-lg-block">
    <div class="card mb-4">
      <div class="card-body">
        <h6 class="mb-3 fw-bold">Applied Filters</h6>
        <div class="mb-3">
          <span class="badge bg-light text-dark">NON STOP</span>
        </div>

        <h6 class="mt-3">Popular Filters</h6>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="f_nonstop" checked>
          <label class="form-check-label" for="f_nonstop">Non Stop</label>
        </div>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="f_indigo">
          <label class="form-check-label" for="f_indigo">IndiGo</label>
        </div>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="f_airindia">
          <label class="form-check-label" for="f_airindia">Air India</label>
        </div>

        <hr>
        <h6>Arrival Airports</h6>
        <ul class="list-unstyled small">
          <li><input type="checkbox"> Hindon Airport (32km)</li>
          <li><input type="checkbox"> Indira Gandhi International Airport</li>
        </ul>
        <hr>
        <h6>Fare Type</h6>
        <div class="btn-group w-100" role="group">
          <button class="btn btn-outline-secondary">Regular</button>
          <button class="btn btn-outline-secondary">Student</button>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-body small">
        <strong>Deals</strong>
        <p class="mb-0">Flat 10% off on Business class. Code: MMT10</p>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="col-lg-9">
    <!-- Hero search bar -->
    <div class="card mb-4">
      <div class="card-body">
        <form id="heroSearch" class="row g-2 align-items-center">
          <div class="col-md-3">
            <select class="form-select" name="trip_type" id="trip_type">
              <option>One way</option>
              <option>Round Trip</option>
            </select>
          </div>
          <div class="col-md-3">
            <input name="source" id="source" class="form-control form-control-lg" placeholder="From (city / airport)">
          </div>
          <div class="col-md-3">
            <input name="destination" id="destination" class="form-control form-control-lg" placeholder="To (city / airport)">
          </div>
          <div class="col-md-2">
            <input name="depart" id="depart" type="date" class="form-control form-control-lg">
          </div>
          <div class="col-md-1 d-grid">
            <button class="btn btn-primary btn-lg">Search</button>
          </div>
        </form>

        <!-- Promo carousel -->
        <div id="promoCarousel" class="carousel slide mt-3" data-bs-ride="carousel">
          <div class="carousel-inner">
            <div class="carousel-item active">
              <div class="p-3 bg-light rounded">FLAT 10% OFF — Business class</div>
            </div>
            <div class="carousel-item">
              <div class="p-3 bg-light rounded">Enjoy 3% myCash</div>
            </div>
            <div class="carousel-item">
              <div class="p-3 bg-light rounded">Special fares available now</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Date strip -->
    <div class="mb-3 d-flex align-items-center gap-2 flex-wrap">
      <?php
      for ($i = 0; $i < 8; $i++) {
          $d = date('D, M j', strtotime("+$i days"));
          $cls = $i === 0 ? 'active-date' : 'date-chip';
          echo "<div class='$cls'>$d<br><small>₹" . (5000 + $i * 250) . "</small></div>";
      }
      ?>
    </div>

    <!-- Flight cards -->
    <div id="flightResults">
      <?php
      $cards = [
          ['id' => 'F1', 'src' => 'Chhatrapati Shivaji Airport', 'dst' => 'Kempegowda Airport', 'd' => '14:30', 'a' => '15:00', 'air' => 'IndiGo', 'price' => 5065],
          ['id' => 'F5', 'src' => 'Kempegowda Airport', 'dst' => 'Indira Gandhi Airport', 'd' => '11:00', 'a' => '13:30', 'air' => 'AirOne', 'price' => 5400],
          ['id' => 'F7', 'src' => 'Chhatrapati Shivaji Airport', 'dst' => 'Madras Airport', 'd' => '09:15', 'a' => '11:20', 'air' => 'AirLineX', 'price' => 4700],
      ];
      foreach ($cards as $c) {
          echo "<div class='card mb-3'>
                  <div class='card-body d-flex justify-content-between align-items-center'>
                    <div>
                      <h5 class='mb-1'>{$c['id']} — {$c['src']} → {$c['dst']}</h5>
                      <div class='text-muted'>Departure: {$c['d']} &nbsp; Arrival: {$c['a']} &nbsp; Airline: {$c['air']} &nbsp; <strong>₹{$c['price']}</strong></div>
                    </div>
                    <div><a class='btn btn-success' href='/FLIGHT_FRONTEND/seats.php?flight_id={$c['id']}'>Book</a></div>
                  </div>
                </div>";
      }
      ?>
    </div>
  </main>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
