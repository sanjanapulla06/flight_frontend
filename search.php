
<?php
require_once __DIR__ . '/includes/header.php';
?>
<div class="container my-4">
  <h2>Search Flights</h2>
  <form id="searchForm" class="row g-3 mb-4">
    <div class="col-md-5"><input type="text" name="source" class="form-control" placeholder="From e.g. Mumbai or BOM" required></div>
    <div class="col-md-5"><input type="text" name="destination" class="form-control" placeholder="To e.g. Bangalore or BLR" required></div>
    <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Search</button></div>
  </form>

  <div id="result"></div>
</div>

<script>
document.getElementById('searchForm').addEventListener('submit', async function (e) {
  e.preventDefault();
  document.getElementById('result').innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status"></div> Searching...</div>';
  const fd = new FormData(e.target);
  try {
    const res = await fetch('/FLIGHT_FRONTEND/api/search_flights.php', { method: 'POST', body: fd });
    if (!res.ok) {
      const txt = await res.text();
      document.getElementById('result').innerHTML = `<div class="alert alert-danger">Server error: ${res.status} <pre>${txt}</pre></div>`;
      return;
    }
    const html = await res.text();
    document.getElementById('result').innerHTML = html;
  } catch (err) {
    console.error(err);
    document.getElementById('result').innerHTML = '<div class="alert alert-danger">Network error. Check console.</div>';
  }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
