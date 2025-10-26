<?php
// index.php — Fullscreen hero + per-passenger Recent Searches + Recently booked
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$passport_no = $_SESSION['passport_no'] ?? null;
$user_name   = $_SESSION['name'] ?? null;

/* ---------- helpers ---------- */
function detect_table($mysqli, array $names, $fallback) {
    foreach ($names as $n) {
        $res = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($n) . "'");
        if ($res && $res->num_rows > 0) return $n;
    }
    return $fallback;
}
function get_columns($mysqli, $table) {
    $cols = [];
    $r = $mysqli->query("SHOW COLUMNS FROM `{$table}`");
    if ($r) while ($c = $r->fetch_assoc()) $cols[] = $c['Field'];
    return $cols;
}
function pick_col(array $cands, array $avail, $fallback = null) {
    foreach ($cands as $c) if (in_array($c, $avail)) return $c;
    return $fallback;
}

/* ---------- Recent searches (per passenger) ---------- */
$recent = [];
if ($passport_no) {
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
        $res = $stmt->get_result();
        $recent = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

/* ---------- Recently booked (per passenger) ---------- */
$booked = [];
if ($passport_no) {
    $flight_table = detect_table($mysqli, ['flight','flights'], 'flight');
    $cols = get_columns($mysqli, $flight_table);

    $col_id     = pick_col(['flight_id','id','fid'], $cols, 'flight_id');
    $col_src    = pick_col(['source_id','source','src'], $cols, 'source_id');
    $col_dst    = pick_col(['destination_id','destination','dst'], $cols, 'destination_id');
    $col_depart = pick_col(['departure_time','dep_time','d_time','departure'], $cols, 'departure_time');
    $col_arrive = pick_col(['arrival_time','arr_time','a_time','arrival'], $cols, 'arrival_time');
    $col_price  = pick_col(['price','base_price','fare','amount'], $cols, 'price');
    $col_code   = pick_col(['flight_code','flight_no','flight_number','code'], $cols, $col_id);

    $src_join = in_array($col_src, ['source_id']) ? "LEFT JOIN airport src ON f.`{$col_src}` = src.airport_id" : "";
    $dst_join = in_array($col_dst, ['destination_id']) ? "LEFT JOIN airport dst ON f.`{$col_dst}` = dst.airport_id" : "";

    $select_src = in_array($col_src, ['source_id']) ? "src.airport_code AS src_code, src.airport_name AS src_name" : "f.`{$col_src}` AS src_code, NULL AS src_name";
    $select_dst = in_array($col_dst, ['destination_id']) ? "dst.airport_code AS dst_code, dst.airport_name AS dst_name" : "f.`{$col_dst}` AS dst_code, NULL AS dst_name";

    $sql = "
      SELECT b.booking_id,
             f.`" . $mysqli->real_escape_string($col_code) . "` AS flight_code,
             {$select_src},
             {$select_dst},
             f.`" . $mysqli->real_escape_string($col_depart) . "` AS departure_time,
             f.`" . $mysqli->real_escape_string($col_arrive) . "` AS arrival_time,
             COALESCE(f.`" . $mysqli->real_escape_string($col_price) . "`,0) AS price
      FROM bookings b
      JOIN `{$flight_table}` f ON f.`" . $mysqli->real_escape_string($col_id) . "` = b.flight_id
      {$src_join}
      {$dst_join}
      WHERE b.passport_no = ?
      ORDER BY b.booking_date DESC
      LIMIT 3
    ";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $passport_no);
        $stmt->execute();
        $booked = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

/* ---------- Render ---------- */
?>
<style>
.hero-full {
  min-height: 100vh;
  display:flex;
  align-items:center;
  background: linear-gradient(90deg,#031a36 0%, #063a83 100%);
  color:#fff;
  padding: 5vh 6vw;
}
.hero-inner {
  width:100%;
  max-width:1240px;
  margin:0 auto;
  display:flex;
  gap:2.5rem;
  align-items:center;
}
/* Left image */
.hero-left { flex: 0 0 58%; display:flex; justify-content:center; align-items:center; }
.plane-frame { background:#f1f5f9; padding:36px; border-radius:8px; box-shadow:0 12px 44px rgba(0,0,0,0.28); width:100%; max-width:880px; }
.hero-plane { width:100%; height:auto; animation: floaty 6s ease-in-out infinite; }

/* Right column */
.hero-right { flex: 0 0 42%; display:flex; flex-direction:column; gap:10px; }
.hero-title { font-size:3.4rem; margin:0 0 6px; color:#9fe8ff; font-weight:800; line-height:1; }
.hero-subtitle { margin:0 0 12px; color:#d9f2ff; font-size:1.05rem; }

/* Recent searches */
.recent-section { margin-top:8px; }
.recent-heading { color: rgba(255,255,255,0.9); margin-bottom:10px; font-weight:700; }
.recent-pills { display:flex; gap:12px; flex-wrap:wrap; }
.recent-pill {
  display:inline-flex; align-items:center; padding:10px 14px; border-radius:8px;
  background: rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.08);
  color:#dff6ff; text-decoration:none; font-weight:700; font-size:0.95rem;
}
.no-recent { color: rgba(255,255,255,0.65); }

/* Booked flights */
.booked-section { margin-top:14px; }
.booked-heading { font-size:0.95rem; color: #e6f4ff; font-weight:700; margin-bottom:8px; display:flex; align-items:center; gap:8px; }
.booked-list { display:flex; flex-direction:column; gap:12px; }
.booked-card {
  background: rgba(255,255,255,0.04); border-radius:10px; padding:14px; border:1px solid rgba(255,255,255,0.06);
}
.booked-route { font-weight:800; color:#fff; margin-bottom:6px; }
.booked-meta { color: rgba(255,255,255,0.75); font-size:0.92rem; }

@keyframes floaty { 0%{transform:translateY(0)}50%{transform:translateY(-8px)}100%{transform:translateY(0)} }

@media (max-width: 980px) {
  .hero-inner { flex-direction:column; gap:1.25rem; }
  .hero-left, .hero-right { flex:0 0 100%; width:100%; }
  .hero-title { font-size:2.2rem; text-align:center; }
  .hero-subtitle { text-align:center; }
  .recent-pills { justify-content:center; }
  .plane-frame { max-width:520px; margin:0 auto; padding:22px; }
}
</style>

<div class="hero-full">
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
                // 🧭 Only show route, no dates
                $text = htmlspecialchars(trim($r['source'].' → '.$r['destination']));
                $url = "/FLIGHT_FRONTEND/search.php?source=".urlencode($r['source'])."&destination=".urlencode($r['destination'])."&flight_date=".urlencode($r['depart']);
            ?>
              <a href="<?= $url ?>" class="recent-pill" title="<?= htmlspecialchars($r['created_at'] ?? '') ?>">
                <?= $text ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div id="recentServerList" class="no-recent">No recent searches yet — start exploring!</div>
        <?php endif; ?>
      </div>

      <?php if (!empty($booked)): ?>
        <div class="booked-section">
          <div class="booked-heading">🛫 Recently booked</div>
          <div class="booked-list">
            <?php foreach ($booked as $b):
              $src_label = !empty($b['src_name']) ? ($b['src_code'] ? "{$b['src_code']} — {$b['src_name']}" : $b['src_name']) : ($b['src_code'] ?? '—');
              $dst_label = !empty($b['dst_name']) ? ($b['dst_code'] ? "{$b['dst_code']} — {$b['dst_name']}" : $b['dst_name']) : ($b['dst_code'] ?? '—');
              $dstr = $b['departure_time'] ?? null;
            ?>
              <div class="booked-card">
                <div class="booked-route"><?= htmlspecialchars($src_label) ?> → <?= htmlspecialchars($dst_label) ?></div>
                <div class="booked-meta">
                  <?php
                    if ($dstr) { echo date('M d, H:i', strtotime($dstr)) . ' • '; }
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

<script>
// LocalStorage fallback for non-logged users
document.addEventListener('DOMContentLoaded', function () {
  const srv = document.getElementById('recentServerList');
  if (srv && srv.children && srv.children.length > 0) return;

  const RECENT_KEY = 'fb_recent_searches_v1';
  const arr = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
  if (!arr || !arr.length) return;

  let container = srv;
  if (!container) {
    container = document.createElement('div');
    container.id = 'recentServerList';
    container.className = 'recent-pills';
    const recentSection = document.querySelector('.recent-section');
    if (recentSection) recentSection.appendChild(container);
  }

  arr.slice(-8).reverse().forEach(item => {
    const label = `${item.source} → ${item.destination}`;
    const a = document.createElement('a');
    a.className = 'recent-pill';
    a.href = `/FLIGHT_FRONTEND/search.php?source=${encodeURIComponent(item.source)}&destination=${encodeURIComponent(item.destination)}&flight_date=${encodeURIComponent(item.depart||'')}`;
    a.textContent = label;
    container.appendChild(a);
  });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
