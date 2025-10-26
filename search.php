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

  // gather form data
  const fd = new FormData(form);

  try {
    // 1) request search results HTML from server endpoint (existing)
    const res = await fetch('/FLIGHT_FRONTEND/api/search_flights.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });

    // replace results area with returned HTML
    const html = await res.text();
    resultEl.innerHTML = html;

    // 2) save server-side history (asynchronously; don't block UX)
    try {
      // clone the form data because we used fd above
      const fd2 = new FormData();
      fd2.append('source', (form.source.value || '').trim());
      fd2.append('destination', (form.destination.value || '').trim());
      fd2.append('flight_date', (form.flight_date.value || '').trim());
      // optional flag
      if (form.india_only && form.india_only.checked) fd2.append('india_only', '1');

      // fire-and-forget; server will use session to associate passport
      fetch('/FLIGHT_FRONTEND/api/save_search.php', {
        method: 'POST',
        body: fd2,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).catch(err => console.warn('save_search failed', err));
    } catch (err) {
      console.warn('save history failed', err);
    }

    // 3) also store in localStorage (fallback and immediate UI)
    try {
      const src = form.source.value || '';
      const dst = form.destination.value || '';
      const depart = form.flight_date.value || '';
      if (src && dst) {
        const RECENT_KEY = 'fb_recent_searches_v1';
        const arr = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
        const item = {
          source: src.trim(),
          destination: dst.trim(),
          depart: depart ? depart : '',
          created_at: new Date().toISOString()
        };
        // remove exact duplicates (same source,destination,depart)
        const filtered = arr.filter(x => !(x.source === item.source && x.destination === item.destination && x.depart === item.depart));
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
