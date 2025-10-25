<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$passport_no = $_SESSION['passport_no'] ?? null;

// ✅ Fetch recent searches (from search_history)
$recent = [];
if ($passport_no) {
    $stmt = $mysqli->prepare("
        SELECT source, destination,
               COALESCE(flight_date, '') AS depart,
               created_at
        FROM search_history
        WHERE passport_no = ?
        ORDER BY created_at DESC
        LIMIT 6
    ");
    if ($stmt) {
        $stmt->bind_param('s', $passport_no);
        $stmt->execute();
        $res = $stmt->get_result();
        $recent = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// ✅ Fetch recently booked flights
$booked = [];
if ($passport_no) {
    $stmt2 = $mysqli->prepare("
        SELECT b.booking_id, f.flight_id, src.airport_code AS src_code, dst.airport_code AS dst_code,
               f.departure_time, f.arrival_time, COALESCE(f.price, f.base_price, 0) AS price
        FROM bookings b
        JOIN flight f ON f.flight_id = b.flight_id
        LEFT JOIN airport src ON f.source_id = src.airport_id
        LEFT JOIN airport dst ON f.destination_id = dst.airport_id
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

<!-- close container from header -->
</div>

<link rel="stylesheet" href="/FLIGHT_FRONTEND/assets/css/home.css">

<!-- 🌍 BLUE FULLSCREEN HERO -->
<div class="hero-section fullpage d-flex flex-column align-items-center justify-content-center text-white">

  <div class="d-flex w-100 justify-content-between align-items-center flex-wrap" style="gap:30px;">
    <!-- ✈️ Plane Image -->
    <div class="hero-left flex-shrink-0">
      <img src="/FLIGHT_FRONTEND/assets/img/plane.png" alt="Plane" class="hero-plane img-fluid" />
    </div>

    <!-- 💬 Text + Recent Searches -->
    <div class="hero-right flex-grow-1">
      <h1 class="hero-title mb-3">It’s time to travel!</h1>
      <p class="hero-subtitle">We don’t just book flights. We connect stories. 💙</p>

      <!-- Recent Searches -->
      <?php if (!empty($recent)): ?>
        <div class="recent-searches mt-4">
          <h6 class="small text-white-50 mb-2">Recent searches</h6>
          <div class="list-group list-group-horizontal overflow-auto" id="recentServerList">
            <?php foreach ($recent as $r):
              $labelDate = $r['depart'] ? 'on '.date('M d', strtotime($r['depart'])) : '';
              $text = htmlspecialchars($r['source'].' → '.$r['destination'].' '.$labelDate);
              $when_searched = !empty($r['created_at']) ? date('M d, Y H:i', strtotime($r['created_at'])) : '';
              $url = "/FLIGHT_FRONTEND/search.php?source=".urlencode($r['source'])."&destination=".urlencode($r['destination'])."&flight_date=".urlencode($r['depart']);
            ?>
              <a href="<?= $url ?>" class="list-group-item list-group-item-action me-2" title="<?= $when_searched ?>">
                <div class="d-flex flex-column">
                  <div><?= $text ?></div>
                  <?php if ($when_searched): ?>
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

  <!-- 🛫 Recently Booked Flights (now inside the blue section) -->
  <?php if (!empty($booked)): ?>
    <div class="booked-flights mt-5 w-100 px-5">
      <h4 class="text-white mb-3">🛫 Recently Booked Flights</h4>
      <div class="list-group">
        <?php foreach ($booked as $b): ?>
          <div class="list-group-item bg-transparent text-white border rounded-3 mb-2">
            ✈️ <?= htmlspecialchars($b['src_code'] ?: '—') ?> → <?= htmlspecialchars($b['dst_code'] ?: '—') ?><br>
            <small class="text-white-50">
              <?php
                $dstr = $b['departure_time'] ?? null;
                $astr = $b['arrival_time'] ?? null;
                if ($dstr) {
                  echo date('M d, H:i', strtotime($dstr));
                  echo ' — ';
                  echo ($astr ? date('H:i', strtotime($astr)) : '—');
                } else {
                  echo '—';
                }
              ?>
              • ₹<?= number_format($b['price'], 0) ?>
            </small>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
const RECENT_KEY = 'fb_recent_searches_v1';

// fallback for non-logged users: render localStorage recent searches
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
    const label = `${item.source} → ${item.destination}${item.depart ? ' on ' + item.depart : ''}`;
    const a = document.createElement('a');
    a.href = `/FLIGHT_FRONTEND/search.php?source=${encodeURIComponent(item.source)}&destination=${encodeURIComponent(item.destination)}&flight_date=${encodeURIComponent(item.depart || '')}`;
    a.className = 'list-group-item list-group-item-action me-2';
    a.textContent = label;
    container.appendChild(a);
  });
}
document.addEventListener('DOMContentLoaded', renderLocalRecent);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

/*
<?php
// index.php — updated hero + recent searches + booked flights (full file)
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$passport_no = $_SESSION['passport_no'] ?? null;

/* --------------------------
   1) Recent searches (unique per passenger)
   -------------------------- */
$recent = [];
if ($passport_no) {
    // group by source,destination,depart to dedupe and take most recent created_at
    $sql = "
        SELECT source, destination, COALESCE(flight_date, '') AS depart, MAX(created_at) AS created_at
        FROM search_history
        WHERE passport_no = ?
        GROUP BY source, destination, depart
        ORDER BY MAX(created_at) DESC
        LIMIT 8
    ";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $passport_no);
        $stmt->execute();
        $recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        // silence; we'll fall back to empty list
    }
}

/* --------------------------
   2) Recently booked flights
   -------------------------- */
$booked = [];
if ($passport_no) {
    // detect flight table name
    $flight_table = 'flight';
    $chk = $mysqli->query("SHOW TABLES LIKE 'flight'");
    if (!$chk || $chk->num_rows === 0) {
        $chk2 = $mysqli->query("SHOW TABLES LIKE 'flights'");
        if ($chk2 && $chk2->num_rows > 0) $flight_table = 'flights';
    }

    // choose reasonable column names (defensive)
    $cols = [];
    $colRes = $mysqli->query("SHOW COLUMNS FROM {$flight_table}");
    if ($colRes) {
        while ($c = $colRes->fetch_assoc()) $cols[] = $c['Field'];
    }

    function pick_col_local(array $candidates, array $available, $fallback = null) {
        foreach ($candidates as $c) if (in_array($c, $available)) return $c;
        return $fallback;
    }

    $col_id     = pick_col_local(['flight_id','id','fid'], $cols, 'flight_id');
    $col_src    = pick_col_local(['source_id','source','src'], $cols, 'source_id');
    $col_dst    = pick_col_local(['destination_id','destination','dst'], $cols, 'destination_id');
    $col_depart = pick_col_local(['departure_time','dep_time','d_time','departure'], $cols, 'departure_time');
    $col_arrive = pick_col_local(['arrival_time','arr_time','a_time','arrival'], $cols, 'arrival_time');
    $col_price  = pick_col_local(['price','base_price','fare','amount'], $cols, 'price');
    $col_code   = pick_col_local(['flight_code','flight_no','flight_number','code'], $cols, $col_id);

    // prepare statement using the detected columns; join airport table to get codes/names when available
    // note: use backticks around dynamic column names to avoid reserved word issues
    $sql = "
        SELECT
          b.booking_id,
          f.`" . $mysqli->real_escape_string($col_code) . "` AS flight_code,
          src.airport_code AS src_code,
          IFNULL(src.airport_name, NULL) AS src_name,
          dst.airport_code AS dst_code,
          IFNULL(dst.airport_name, NULL) AS dst_name,
          f.`" . $mysqli->real_escape_string($col_depart) . "` AS departure_time,
          f.`" . $mysqli->real_escape_string($col_arrive) . "` AS arrival_time,
          COALESCE(f.`" . $mysqli->real_escape_string($col_price) . "`, 0) AS price
        FROM bookings b
        JOIN `{$flight_table}` f ON f.`" . $mysqli->real_escape_string($col_id) . "` = b.flight_id
        LEFT JOIN airport src ON f.`" . $mysqli->real_escape_string($col_src) . "` = src.airport_id
        LEFT JOIN airport dst ON f.`" . $mysqli->real_escape_string($col_dst) . "` = dst.airport_id
        WHERE b.passport_no = ?
        ORDER BY b.booking_date DESC
        LIMIT 3
    ";
    $stmt2 = $mysqli->prepare($sql);
    if ($stmt2) {
        $stmt2->bind_param('s', $passport_no);
        $stmt2->execute();
        $booked = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt2->close();
    } else {
        // fallback: try a simpler query if the above failed
        $stmt2b = $mysqli->prepare("
            SELECT b.booking_id, b.flight_id AS flight_code, NULL AS src_code, NULL AS src_name, NULL AS dst_code, NULL AS dst_name,
                   NULL AS departure_time, NULL AS arrival_time, 0 AS price
            FROM bookings b
            WHERE b.passport_no = ?
            ORDER BY b.booking_date DESC
            LIMIT 3
        ");
        if ($stmt2b) {
            $stmt2b->bind_param('s', $passport_no);
            $stmt2b->execute();
            $booked = $stmt2b->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt2b->close();
        }
    }
}

// close container from header if header opened one
?>
<link rel="stylesheet" href="/FLIGHT_FRONTEND/assets/css/home.css">

<style>
/* --- hero styles (self-contained overrides) --- */
.hero-wrap {
  background: linear-gradient(90deg,#031a36 0%, #063a83 100%);
  color: #fff;
  padding: 4.5vh 6vw;
}
.hero-inner {
  max-width: 1200px;
  margin: 0 auto;
  display:flex;
  gap:2.25rem;
  align-items:center;
}

/* left image */
.hero-left { flex: 0 0 56%; display:flex; justify-content:center; align-items:center; }
.plane-frame {
  background:#f1f5f9;
  padding:28px;
  border-radius:6px;
  box-shadow:0 10px 40px rgba(0,0,0,0.25);
  width:100%;
  max-width:820px;
}
.hero-plane { width:100%; height:auto; animation: floaty 6s ease-in-out infinite; }

/* right column */
.hero-right { flex: 0 0 44%; display:flex; flex-direction:column; gap:8px; }
.hero-title { font-size:3rem; margin:0 0 6px; color:#9fe8ff; font-weight:800; line-height:1.02; }
.hero-subtitle { margin:0 0 10px; color:#d9f2ff; font-size:1.05rem; }

/* recent searches */
.recent-section { margin-top:6px; }
.recent-heading { color: rgba(255,255,255,0.85); margin-bottom:8px; font-weight:600; font-size:0.95rem; }
.recent-pills { display:flex; gap:10px; flex-wrap:wrap; }
.recent-pill {
  display:inline-flex;
  align-items:center;
  padding:8px 12px;
  border-radius:8px;
  background: rgba(255,255,255,0.06);
  border:1px solid rgba(255,255,255,0.08);
  color:#dff6ff;
  text-decoration:none;
  font-weight:600;
  font-size:0.95rem;
}
.no-recent { color: rgba(255,255,255,0.65); }

/* booked styles */
.booked-section { margin-top:12px; }
.booked-heading { font-size:0.95rem; color: rgba(255,255,255,0.9); font-weight:700; margin-bottom:8px; }
.booked-list { display:flex; flex-direction:column; gap:10px; }
.booked-card {
  background: rgba(255,255,255,0.04);
  border-radius:10px;
  padding:10px 12px;
  border:1px solid rgba(255,255,255,0.06);
}
.booked-route { font-weight:700; color:#fff; margin-bottom:4px; }
.booked-meta { color: rgba(255,255,255,0.75); font-size:0.92rem; }

@keyframes floaty {
  0% { transform: translateY(0) rotate(-1deg); }
  50% { transform: translateY(-10px) rotate(1deg); }
  100% { transform: translateY(0) rotate(-1deg); }
}

/* responsive */
@media (max-width: 880px) {
  .hero-inner { flex-direction: column; gap: 1.25rem; }
  .hero-left, .hero-right { flex: 0 0 100%; width:100%; padding:0; }
  .hero-title { font-size:2.2rem; text-align:center; }
  .hero-subtitle { text-align:center; }
  .recent-pills { justify-content:center; }
  .plane-frame { max-width:520px; margin:0 auto; }
}
</style>

<div class="hero-wrap">
  <div class="hero-inner">

    <div class="hero-left">
      <div class="plane-frame">
        <img src="/FLIGHT_FRONTEND/assets/img/plane.png" alt="Plane" class="hero-plane" />
      </div>
    </div>

    <div class="hero-right">
      <h1 class="hero-title">It’s time to travel!</h1>
      <p class="hero-subtitle">We don’t just book flights. <strong>We connect stories.</strong> 💙</p>

      <div class="recent-section">
        <div class="recent-heading">Recent searches</div>

        <?php if (!empty($recent)): ?>
          <div class="recent-pills" id="recentServerList">
            <?php foreach ($recent as $r):
              // format label: SOURCE → DEST ( • on MMM dd ) if depart exists
              $labelDate = $r['depart'] ? ' • on '.date('M d', strtotime($r['depart'])) : '';
              $text = htmlspecialchars(trim($r['source'].' → '.$r['destination'].$labelDate));
              $url = "/FLIGHT_FRONTEND/search.php?source=".urlencode($r['source'])."&destination=".urlencode($r['destination'])."&flight_date=".urlencode($r['depart']);
            ?>
              <a href="<?= $url ?>" class="recent-pill" title="<?= htmlspecialchars($r['created_at'] ?? '') ?>">
                <?= $text ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="no-recent">No recent searches yet — start exploring!</div>
        <?php endif; ?>
      </div>

      <?php if (!empty($booked)): ?>
        <div class="booked-section">
          <div class="booked-heading">🛫 Recently booked</div>
          <div class="booked-list">
            <?php foreach ($booked as $b):
              // prefer airport name if present, otherwise code
              $src_label = $b['src_name'] ? ($b['src_code'] ? "{$b['src_code']} — {$b['src_name']}" : $b['src_name']) : ($b['src_code'] ?: '—');
              $dst_label = $b['dst_name'] ? ($b['dst_code'] ? "{$b['dst_code']} — {$b['dst_name']}" : $b['dst_name']) : ($b['dst_code'] ?: '—');
            ?>
              <div class="booked-card">
                <div class="booked-route"><?= htmlspecialchars($src_label) ?> → <?= htmlspecialchars($dst_label) ?></div>
                <div class="booked-meta">
                  <?php
                    $dstr = $b['departure_time'] ?? null;
                    if ($dstr) {
                        echo date('M d, H:i', strtotime($dstr)) . ' • ';
                    }
                    echo '₹' . number_format(floatval($b['price'] ?? 0), 0);
                  ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
*/