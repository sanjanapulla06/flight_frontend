<?php
// FLIGHT_FRONTEND/index.php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$passport_no = $_SESSION['passport_no'] ?? null;

// ✅ Fetch recent searches for logged-in user
$recent = [];
if ($passport_no) {
    $stmt = $mysqli->prepare("
        SELECT source, destination, depart, created_at
        FROM recent_searches
        WHERE passport_no = ?
        ORDER BY created_at DESC LIMIT 6
    ");
    if ($stmt) {
        $stmt->bind_param('s', $passport_no);
        $stmt->execute();
        $res = $stmt->get_result();
        $recent = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}
?>

<!-- close container from header -->
</div>

<link rel="stylesheet" href="/FLIGHT_FRONTEND/assets/css/home.css">

<!-- 🌍 FULLSCREEN HERO -->
<div class="hero-section fullpage d-flex align-items-center justify-content-between text-center text-white">
  <div class="hero-left flex-shrink-0">
    <img src="/FLIGHT_FRONTEND/assets/img/plane.png" alt="Plane" class="hero-plane img-fluid" />
  </div>

  <div class="hero-right flex-grow-1">
    <h1 class="hero-title mb-3">It’s time to travel!</h1>
    <p class="hero-subtitle">We don’t just book flights. We connect stories. 💙</p>

    <!-- ✈️ Recent Searches -->
    <?php if (!empty($recent)): ?>
      <div class="recent-searches mt-4">
        <h6 class="small text-white-50 mb-2">Recent searches</h6>
        <div class="list-group list-group-horizontal overflow-auto" id="recentServerList">
          <?php foreach ($recent as $r):
            $labelDate = $r['depart'] ? 'on '.date('M d', strtotime($r['depart'])) : '';
            $text = htmlspecialchars($r['source'].' → '.$r['destination'].' '.$labelDate);

            // ADDED: formatted created_at for "when they searched"
            $when_searched = !empty($r['created_at']) ? date('M d, Y H:i', strtotime($r['created_at'])) : '';

            $url = "/FLIGHT_FRONTEND/search.php?source=".urlencode($r['source'])."&destination=".urlencode($r['destination'])."&depart=".urlencode($r['depart']);
          ?>
            <a href="<?= $url ?>" class="list-group-item list-group-item-action me-2" title="<?= $when_searched ?>">
              <div class="d-flex flex-column">
                <div><?= $text ?></div>
                <?php if ($when_searched): // ADDED: show small searched time ?>
                  <small class="text-white-50"><?= htmlspecialchars($when_searched) ?></small>
                <?php endif; ?>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="recent-searches mt-4">
        <h6 class="small text-white-50 mb-2">Recent searches</h6>
        <div id="recentLocalList" class="list-group list-group-horizontal overflow-auto text-center">
          <div class="text-white-50 small">No recent searches yet — start exploring!</div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
// ✅ Fetch recently booked flights for the logged-in user
$booked = [];
if ($passport_no) {
    $stmt2 = $mysqli->prepare("
        SELECT b.booking_id, f.source, f.destination, f.d_time, f.a_time, f.flight_date, f.price
        FROM bookings b
        JOIN flight f ON f.flight_id = b.flight_id
        WHERE b.passport_no = ?
        ORDER BY b.booking_date DESC
        LIMIT 3
    ");
    if ($stmt2) {
        $stmt2->bind_param('s', $passport_no);
        $stmt2->execute();
        $booked = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt2->close();
    }
}
?>

<?php if (!empty($booked)): ?>
  <div class="container mt-5">
    <h4 class="text-white mb-3">🛫 Recently Booked Flights</h4>
    <div class="list-group list-group-vertical">
      <?php foreach ($booked as $b): ?>
        <div class="list-group-item bg-light bg-opacity-10 text-white border-0 mb-2 rounded-3">
          ✈️ <?= htmlspecialchars($b['source']) ?> → <?= htmlspecialchars($b['destination']) ?><br>
          <small class="text-white-50">
            <?= date('M d, H:i', strtotime($b['flight_date'].' '.$b['d_time'])) ?>
            — <?= date('H:i', strtotime($b['a_time'])) ?>
            • ₹<?= number_format($b['price'], 0) ?>
          </small>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<script>
const RECENT_KEY = 'fb_recent_searches_v1';

// fallback for non-logged users
function renderLocalRecent() {
  const container = document.getElementById('recentLocalList');
  if (!container) return;
  const arr = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
  if (!arr.length) {
    container.innerHTML = '<div class="text-white-50 small">No recent searches yet — start exploring!</div>';
    return;
  }
  container.innerHTML = '';
  arr.forEach(item => {
    // ADDED: prefer item.searched_at or item.created_at if available
    const searchedAt = item.searched_at || item.created_at || null;
    const when = searchedAt ? (' — ' + new Date(searchedAt).toLocaleString()) : '';
    const label = `${item.source} → ${item.destination}${item.depart ? ' on ' + item.depart : ''}${when}`;
    const a = document.createElement('a');
    a.href = `/FLIGHT_FRONTEND/search.php?source=${encodeURIComponent(item.source)}&destination=${encodeURIComponent(item.destination)}&depart=${encodeURIComponent(item.depart || '')}`;
    a.className = 'list-group-item list-group-item-action me-2';
    a.textContent = label;
    container.appendChild(a);
  });
}
document.addEventListener('DOMContentLoaded', renderLocalRecent);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
