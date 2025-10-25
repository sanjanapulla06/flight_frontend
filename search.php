<?php
require_once __DIR__ . '/includes/header.php';
?>
<div class="container my-4">
  <h2>Search Flights</h2>

  <form id="searchForm" class="row g-3 mb-4">
    <div class="col-md-4">
      <input type="text" name="source" class="form-control" placeholder="From e.g. Mumbai or BOM" required>
    </div>

    <div class="col-md-4">
      <input type="text" name="destination" class="form-control" placeholder="To e.g. Bangalore or BLR" required>
    </div>

    <div class="col-md-2">
      <input type="date" name="flight_date" class="form-control" placeholder="Departure date">
    </div>

    <div class="col-md-2 d-flex align-items-center">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" value="1" id="indiaOnly" name="india_only">
        <label class="form-check-label small" for="indiaOnly">India only</label>
      </div>
    </div>

    <div class="col-12">
      <button type="submit" class="btn btn-primary">Search</button>
    </div>
  </form>

  <div id="result"></div>
</div>

<script>
document.getElementById('searchForm').addEventListener('submit', async function (e) {
  e.preventDefault();
  const form = e.target;
  const resultEl = document.getElementById('result');
  resultEl.innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status"></div> Searching...</div>';

  const fd = new FormData(form);
  try {
    const res = await fetch('/FLIGHT_FRONTEND/api/search_flights.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    const html = await res.text();
    resultEl.innerHTML = html;

    // store local recent
    try {
      const src = fd.get('source') || '';
      const dst = fd.get('destination') || '';
      const depart = fd.get('flight_date') || '';
      if (src && dst) {
        const RECENT_KEY = 'fb_recent_searches_v1';
        const arr = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
        const item = { source: src.trim(), destination: dst.trim(), depart: depart ? depart : '', created_at: new Date().toISOString() };
        const filtered = arr.filter(x => !(x.source===item.source && x.destination===item.destination && x.depart===item.depart));
        filtered.unshift(item);
        localStorage.setItem(RECENT_KEY, JSON.stringify(filtered.slice(0, 12)));
      }
    } catch (err) { console.warn(err); }

  } catch (err) {
    resultEl.innerHTML = '<div class="alert alert-danger">Network error. Check console.</div>';
    console.error(err);
  }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
