// assets/js/main.js - handles frontend fetches & rendering
document.addEventListener('DOMContentLoaded', () => {
  // SEARCH FORM
  const searchForm = document.getElementById('searchForm');
  if(searchForm){
    searchForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(searchForm);
      const resp = await fetch('/flight_frontend/api/search_flights.php', { method:'POST', body: fd });
      const data = await resp.json();
      const results = document.getElementById('results');
      if(!data || data.length===0){
        results.innerHTML = '<div class="alert alert-warning">No flights found.</div>';
        return;
      }
      let html = '<div class="list-group">';
      for(const f of data){
        html += `<div class="list-group-item">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <b>${escapeHtml(f.flight_id)}</b> — ${escapeHtml(f.source)} → ${escapeHtml(f.destination)} <br>
              ${escapeHtml(f.d_time)} - ${escapeHtml(f.a_time)} | ${escapeHtml(f.airline_name||'')}
            </div>
            <div>
              <a class="btn btn-sm btn-success" href="/flight_frontend/book_flight.php?flight_id=${encodeURIComponent(f.flight_id)}">Book</a>
            </div>
          </div>
        </div>`;
      }
      html += '</div>';
      results.innerHTML = html;
    });
  }

  // BOOK FORM
  const bookForm = document.getElementById('bookForm');
  if(bookForm){
    bookForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(bookForm);
      const resp = await fetch('/flight_frontend/api/book_ticket.php', { method:'POST', body: fd });
      const json = await resp.json();
      const resDiv = document.getElementById('bookResult');
      if(json.error) resDiv.innerHTML = `<div class="alert alert-danger">${escapeHtml(json.error)}</div>`;
      else resDiv.innerHTML = `<div class="alert alert-success">Booking successful. Ticket No: <b>${escapeHtml(json.ticket_no)}</b> — <a href="/flight_frontend/e_ticket.php?ticket_no=${encodeURIComponent(json.ticket_no)}">View E-ticket</a></div>`;
    });
  }

  // E-TICKET form
  const eticketForm = document.getElementById('eticketForm');
  if(eticketForm){
    eticketForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const ticket_no = document.getElementById('ticket_no').value.trim();
      if(!ticket_no) return;
      const resp = await fetch('/flight_frontend/api/view_ticket.php?ticket_no=' + encodeURIComponent(ticket_no));
      const json = await resp.json();
      const view = document.getElementById('eticketView');
      if(!json || Object.keys(json).length===0) view.innerHTML = '<div class="alert alert-warning">Ticket not found.</div>';
      else {
        view.innerHTML = renderTicket(json);
      }
    });

    // if ticket_no in URL, auto load
    const params = new URLSearchParams(window.location.search);
    if(params.get('ticket_no')){
      document.getElementById('ticket_no').value = params.get('ticket_no');
      eticketForm.dispatchEvent(new Event('submit'));
    }
  }
});

// small helpers
function escapeHtml(s){ if(!s && s!==0) return ''; return String(s).replace(/[&<>"']/g, (m)=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]); }

function renderTicket(t){
  return `<div class="card">
    <div class="card-body">
      <h5 class="card-title">E-Ticket: ${escapeHtml(t.ticket_no)}</h5>
      <p><b>Passenger:</b> ${escapeHtml(t.passenger_name || '')} (${escapeHtml(t.passport_no || '')})</p>
      <p><b>Flight:</b> ${escapeHtml(t.flight_id || '')} — ${escapeHtml(t.source || '')} → ${escapeHtml(t.destination || '')}</p>
      <p><b>Departure:</b> ${escapeHtml(t.d_time || '')} &nbsp; <b>Arrival:</b> ${escapeHtml(t.a_time || '')}</p>
      <p><b>Airline:</b> ${escapeHtml(t.airline_name || '')} &nbsp; <b>Seat:</b> ${escapeHtml(t.seat_no || '')} &nbsp; <b>Class:</b> ${escapeHtml(t.class || '')}</p>
      <a class="btn btn-primary" href="javascript:window.print()">Print</a>
    </div>
  </div>`;
}
