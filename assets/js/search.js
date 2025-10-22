// assets/js/search.js
// AJAX-driven search + filters + dynamic cards
document.addEventListener('DOMContentLoaded', () => {
  const heroForm = document.querySelector('#heroSearch');
  const resultsEl = document.getElementById('flightResults');
  const dateStrip = document.querySelectorAll('.date-chip, .active-date');
  const filterForm = document.querySelector('#leftFilters'); // optional container
  const sortButtons = document.querySelectorAll('[data-sort]');
  const flightResultsTop = document.querySelector('#flightResultsTop') || null;

  if (!heroForm || !resultsEl) return;

  // helper: escape
  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"'`=\/]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','`':'&#96;','=':'&#61;','/':'&#47;'}[c]));
  }

  function showLoading() {
    resultsEl.innerHTML = `<div class="card mb-3"><div class="card-body d-flex align-items-center justify-content-center">
      <div class="spinner-border text-primary me-2" role="status"><span class="visually-hidden">Loading...</span></div>
      <strong>Searching flights…</strong></div></div>`;
  }

  // gather params from page (form + filters)
  function collectParams() {
    const fd = new FormData(heroForm);
    // non-stop checkbox named non_stop
    const nonStop = document.querySelector('input[name="non_stop"]')?.checked ? '1' : '';
    // airlines checkboxes name="airlines[]" values are airline names or ids
    const airlinesEls = Array.from(document.querySelectorAll('input[name="airlines[]"]:checked')).map(i=>i.value);
    const sort = document.querySelector('input[name="sort"]:checked')?.value || (document.querySelector('[data-sort].active')?.getAttribute('data-sort') || '');
    // date-chip selected via .active-date element (we set data-date)
    const activeDateEl = document.querySelector('.active-date');
    const flight_date = activeDateEl?.getAttribute('data-date') || fd.get('depart') || '';

    // build object to send
    const body = new FormData();
    body.append('source', fd.get('source') || '');
    body.append('destination', fd.get('destination') || '');
    if (flight_date) body.append('flight_date', flight_date);
    if (nonStop) body.append('non_stop', nonStop);
    if (airlinesEls.length) {
      airlinesEls.forEach(v => body.append('airlines[]', v));
    }
    if (sort) body.append('sort', sort);

    return body;
  }

  async function runSearch(body) {
    showLoading();
    try {
      const res = await fetch('/FLIGHT_FRONTEND/api/search_flights.php', {
        method: 'POST',
        body,
        credentials: 'same-origin'
      });
      if (!res.ok) {
        const txt = await res.text();
        resultsEl.innerHTML = `<div class="alert alert-danger">Server error: ${escapeHtml(res.status + ' ' + res.statusText)}<pre>${escapeHtml(txt)}</pre></div>`;
        return;
      }
      const html = await res.text();
      resultsEl.innerHTML = html || `<div class="alert alert-warning">No flights returned.</div>`;
      attachBookHandlers();
      window.scrollTo({ top: resultsEl.offsetTop - 70, behavior: 'smooth' });
    } catch (err) {
      resultsEl.innerHTML = `<div class="alert alert-danger">Network error: ${escapeHtml(err.message)}</div>`;
    }
  }

  // submit handler
  heroForm.addEventListener('submit', (ev) => {
    ev.preventDefault();
    const body = collectParams();
    runSearch(body);
  });

  // Attach book button handlers (delegation)
  function attachBookHandlers() {
    resultsEl.querySelectorAll('a.book-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        // keep default anchor behavior (go to seats.php) but you can intercept to show modal
        // Example: to open in new tab use:
        // window.location.href = btn.getAttribute('href');
      });
    });
  }

  // date strip click (set active class)
  dateStrip.forEach(el => {
    el.addEventListener('click', () => {
      dateStrip.forEach(x=>x.classList.remove('active-date'));
      el.classList.add('active-date');
      // trigger search automatically
      const body = collectParams();
      runSearch(body);
    });
  });

  // filter change in left panel (checkboxes with name attributes)
  if (filterForm) {
    filterForm.addEventListener('change', (e) => {
      // only run search if source/destination are filled
      const src = heroForm.querySelector('[name=source]')?.value || '';
      const dst = heroForm.querySelector('[name=destination]')?.value || '';
      if (src.trim() && dst.trim()) {
        const body = collectParams();
        runSearch(body);
      }
    });
  }

  // sort chip click (data-sort) toggles active and triggers search
  sortButtons.forEach(btn => {
    btn.addEventListener('click', (e) => {
      sortButtons.forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');
      const body = collectParams();
      runSearch(body);
    });
  });

  // If URL prefilled parameters exist, auto-submit
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.has('source') && urlParams.has('destination')) {
    heroForm.querySelector('[name=source]').value = urlParams.get('source');
    heroForm.querySelector('[name=destination]').value = urlParams.get('destination');
    heroForm.dispatchEvent(new Event('submit', {cancelable: true}));
  }

});
