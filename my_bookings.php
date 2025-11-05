<?php
// my_bookings.php (schema-aware, defensive) - updated to show airport labels + reschedule modal + Last changed + Cancel flow
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
safe_start_session();

require_once __DIR__ . '/includes/header.php';

// must be logged in
if (empty($_SESSION['passport_no'])) {
    header('Location: /FLIGHT_FRONTEND/auth/login.php?return=' . urlencode('/FLIGHT_FRONTEND/my_bookings.php'));
    exit;
}
$passport_no = $_SESSION['passport_no'] ?? '';

// --- CONFIG: fixed surcharge (server-side) ---
$FIXED_SURCHARGE_AMT = 250.00; // change here if needed; backend must match

// ---------- helper: pick first existing column ----------
function pick_col(array $candidates, array $available, $fallback = null) {
    foreach ($candidates as $col) {
        if (in_array($col, $available)) return $col;
    }
    return $fallback;
}

// 1) detect flight table name
$flight_table = 'flight';
$chkF = $mysqli->query("SHOW TABLES LIKE 'flight'");
if (!$chkF || $chkF->num_rows === 0) {
    $chkF2 = $mysqli->query("SHOW TABLES LIKE 'flights'");
    if ($chkF2 && $chkF2->num_rows > 0) $flight_table = 'flights';
}

// 2) get bookings columns to know what we can select
$bookings_cols = [];
$bkRes = $mysqli->query("SHOW COLUMNS FROM bookings");
if ($bkRes) {
    while ($c = $bkRes->fetch_assoc()) $bookings_cols[] = $c['Field'];
}

// choose bookings columns safely
$bk_booking_id_col = pick_col(['booking_id', 'id'], $bookings_cols, 'booking_id');
$bk_booking_date_col = pick_col(['booking_date', 'created_at', 'created'], $bookings_cols, 'booking_date');
$bk_status_col = pick_col(['status'], $bookings_cols, 'status');
$bk_refund_col = pick_col(['refund_id', 'refund', 'refund_ref'], $bookings_cols, null);
$bk_cancelled_at_col = pick_col(['cancelled_at', 'cancelled_on', 'cancelled'], $bookings_cols, null);
$bk_flight_id_col = pick_col(['flight_id', 'flightid', 'flight'], $bookings_cols, 'flight_id');

// NEW: detect a bookings 'updated' column (common variants)
$bk_updated_col = pick_col(['updated_at','modified_at','last_changed','last_modified','changed_at'], $bookings_cols, null);

// 3) inspect flight table columns
$flight_cols = [];
$flRes = $mysqli->query("SHOW COLUMNS FROM {$flight_table}");
if ($flRes) {
    while ($c = $flRes->fetch_assoc()) $flight_cols[] = $c['Field'];
}

// pick flight columns
$f_flight_code = pick_col(['flight_code','flight_id'], $flight_cols, 'flight_id');
$f_source = pick_col(['source','src_code','source_id','src','airport_code','airport_name'], $flight_cols, 'source');
$f_destination = pick_col(['destination','dst_code','destination_id','dst','airport_code','airport_name'], $flight_cols, 'destination');
$f_departure = pick_col(['departure_time','d_time','dep_time','departure'], $flight_cols, 'departure_time');
$f_arrival = pick_col(['arrival_time','a_time','arr_time','arrival'], $flight_cols, 'arrival_time');
$f_price = pick_col(['base_price','price','fare'], $flight_cols, 'price');

// 4) Build SELECT list dynamically (only reference columns that exist)
$select_fields = [];
$select_fields[] = "b.`" . $mysqli->real_escape_string($bk_booking_id_col) . "` AS booking_id";
if ($bk_booking_date_col) $select_fields[] = "b.`" . $mysqli->real_escape_string($bk_booking_date_col) . "` AS booking_date";
if ($bk_status_col) $select_fields[] = "b.`" . $mysqli->real_escape_string($bk_status_col) . "` AS status";
if ($bk_refund_col) $select_fields[] = "b.`" . $mysqli->real_escape_string($bk_refund_col) . "` AS refund_id";
if ($bk_cancelled_at_col) $select_fields[] = "b.`" . $mysqli->real_escape_string($bk_cancelled_at_col) . "` AS cancelled_at";
$select_fields[] = "b.`" . $mysqli->real_escape_string($bk_flight_id_col) . "` AS booking_flight_id";

// ADD: include booking_updated if present
if ($bk_updated_col) $select_fields[] = "b.`" . $mysqli->real_escape_string($bk_updated_col) . "` AS booking_updated";

// flight fields (prefix with f.)
if (in_array($f_flight_code, $flight_cols)) $select_fields[] = "f.`" . $mysqli->real_escape_string($f_flight_code) . "` AS flight_code";
if (in_array($f_source, $flight_cols)) $select_fields[] = "f.`" . $mysqli->real_escape_string($f_source) . "` AS source";
if (in_array($f_destination, $flight_cols)) $select_fields[] = "f.`" . $mysqli->real_escape_string($f_destination) . "` AS destination";
if (in_array($f_departure, $flight_cols)) $select_fields[] = "f.`" . $mysqli->real_escape_string($f_departure) . "` AS departure_time";
if (in_array($f_arrival, $flight_cols)) $select_fields[] = "f.`" . $mysqli->real_escape_string($f_arrival) . "` AS arrival_time";
if (in_array($f_price, $flight_cols)) $select_fields[] = "COALESCE(f.`" . $mysqli->real_escape_string($f_price) . "`,0) AS price";

// join + where
$select_sql = implode(",\n       ", $select_fields);
$sql = "
    SELECT
       {$select_sql}
    FROM bookings b
    JOIN {$flight_table} f ON b.`" . $mysqli->real_escape_string($bk_flight_id_col) . "` = f.`" . $mysqli->real_escape_string('flight_id') . "`
    WHERE b.`passport_no` = ?
    ORDER BY b.`" . $mysqli->real_escape_string($bk_booking_date_col) . "` DESC
";

// prepare & execute
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    echo '<div class="container my-4"><div class="alert alert-danger">Failed to load bookings: ' . htmlspecialchars($mysqli->error) . '</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
$stmt->bind_param('s', $passport_no);
$stmt->execute();
$res = $stmt->get_result();

// collect rows and refund ids (if any)
$rows = [];
$refund_ids = [];
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
    if ($bk_refund_col && !empty($r['refund_id'])) $refund_ids[] = $r['refund_id'];
}
$stmt->close();

/* --- airport mapping: build lookup for numeric ids or codes --- */
$airport_map = []; // e.g. ['3' => 'BOM — Mumbai', 'BOM' => 'BOM — Mumbai']
$airport_table_exists = ($mysqli->query("SHOW TABLES LIKE 'airport'")->num_rows > 0);

if ($airport_table_exists) {
    $airport_cols = [];
    $ac = $mysqli->query("SHOW COLUMNS FROM airport");
    if ($ac) {
        while ($c = $ac->fetch_assoc()) $airport_cols[] = $c['Field'];
    }

    $col_id   = in_array('airport_id', $airport_cols) ? 'airport_id' : (in_array('id', $airport_cols) ? 'id' : null);
    $col_code = in_array('airport_code', $airport_cols) ? 'airport_code' : (in_array('code', $airport_cols) ? 'code' : null);
    $col_name = in_array('airport_name', $airport_cols) ? 'airport_name' : (in_array('name', $airport_cols) ? 'name' : null);
    $col_city = in_array('city', $airport_cols) ? 'city' : (in_array('city_name', $airport_cols) ? 'city_name' : null);

    $ids = [];
    $codes = [];
    foreach ($rows as $r) {
        if (isset($r['source']) && is_numeric($r['source'])) $ids[] = (int)$r['source'];
        if (isset($r['destination']) && is_numeric($r['destination'])) $ids[] = (int)$r['destination'];
        if (isset($r['source_id']) && is_numeric($r['source_id'])) $ids[] = (int)$r['source_id'];
        if (isset($r['destination_id']) && is_numeric($r['destination_id'])) $ids[] = (int)$r['destination_id'];

        if (!empty($r['source']) && is_string($r['source']) && preg_match('/^[A-Za-z]{2,6}$/', trim($r['source']))) {
            $codes[] = strtoupper(trim($r['source']));
        }
        if (!empty($r['destination']) && is_string($r['destination']) && preg_match('/^[A-Za-z]{2,6}$/', trim($r['destination']))) {
            $codes[] = strtoupper(trim($r['destination']));
        }
    }
    $ids = array_values(array_unique(array_filter($ids, function($v){return $v>0;})));
    $codes = array_values(array_unique(array_filter(array_map('trim', $codes))));

    if (!empty($ids) && $col_id !== null) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sqlA = "SELECT " . ($col_id ? "`{$col_id}`" : "airport_id")
             . ($col_code ? ", `{$col_code}`" : "")
             . ($col_name ? ", `{$col_name}`" : "")
             . ($col_city ? ", `{$col_city}`" : "")
             . " FROM airport WHERE `{$col_id}` IN ($placeholders)";
        $stmtA = $mysqli->prepare($sqlA);
        if ($stmtA) {
            $types = str_repeat('i', count($ids));
            $bind = [];
            $bind[] = $types;
            foreach ($ids as $i => $val) {
                $var = "aid_$i";
                $$var = $val;
                $bind[] = &$$var;
            }
            call_user_func_array([$stmtA, 'bind_param'], $bind);
            $stmtA->execute();
            $resA = $stmtA->get_result();
            while ($rowA = $resA->fetch_assoc()) {
                $idval = (string)$rowA[$col_id];
                $code = $col_code && !empty($rowA[$col_code]) ? $rowA[$col_code] : '';
                $name = $col_city && !empty($rowA[$col_city]) ? $rowA[$col_city] : ($col_name && !empty($rowA[$col_name]) ? $rowA[$col_name] : '');
                if ($code && $name) {
                    $label = trim($code . ' (' . $name . ')');
                } elseif ($name) {
                    $label = $name;
                } elseif ($code) {
                    $label = strtoupper($code);
                } else {
                    $label = "Airport {$idval}";
                }
                $airport_map[$idval] = $label;
                if ($code) $airport_map[strtoupper($code)] = $label;
            }
            $stmtA->close();
        }
    }

    if (!empty($codes) && $col_code !== null) {
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $sqlC = "SELECT " . ($col_id ? "`{$col_id}`" : "airport_id")
             . ", `{$col_code}`"
             . ($col_name ? ", `{$col_name}`" : "")
             . ($col_city ? ", `{$col_city}`" : "")
             . " FROM airport WHERE `{$col_code}` IN ($placeholders)";
        $stmtC = $mysqli->prepare($sqlC);
        if ($stmtC) {
            $types = str_repeat('s', count($codes));
            $bind = [];
            $bind[] = $types;
            foreach ($codes as $i => $val) {
                $var = "acode_$i";
                $$var = $val;
                $bind[] = &$$var;
            }
            call_user_func_array([$stmtC, 'bind_param'], $bind);
            $stmtC->execute();
            $resC = $stmtC->get_result();
            while ($rowC = $resC->fetch_assoc()) {
                $idval = isset($rowC[$col_id]) ? (string)$rowC[$col_id] : null;
                $code = isset($rowC[$col_code]) ? $rowC[$col_code] : '';
                $name = $col_city && !empty($rowC[$col_city]) ? $rowC[$col_city] : ($col_name && !empty($rowC[$col_name]) ? $rowC[$col_name] : '');
                if ($code && $name) {
                    $label = trim($code . ' (' . $name . ')');
                } elseif ($name) {
                    $label = $name;
                } elseif ($code) {
                    $label = strtoupper($code);
                } else {
                    $label = $code ?: "Airport {$idval}";
                }
                if ($idval) $airport_map[$idval] = $label;
                if ($code) $airport_map[strtoupper($code)] = $label;
            }
            $stmtC->close();
        }
    }
}

// 5) If refunds table exists and we collected refund ids, fetch statuses
$refund_map = [];
$refunds_exist = false;
$chkR = $mysqli->query("SHOW TABLES LIKE 'refunds'");
if ($chkR && $chkR->num_rows > 0) $refunds_exist = true;

if ($refunds_exist && !empty($refund_ids)) {
    $unique = array_values(array_unique($refund_ids));
    $refund_cols = [];
    $rDef = $mysqli->query("SHOW COLUMNS FROM refunds");
    if ($rDef) {
        while ($c = $rDef->fetch_assoc()) $refund_cols[] = $c['Field'];
    }
    $refund_id_col = pick_col(['refund_id','id','refund_ref','refund_id_pk'], $refund_cols, $refund_cols[0] ?? null);
    $refund_status_col = pick_col(['status','state','refund_status'], $refund_cols, $refund_cols[1] ?? 'status');

    $placeholders = implode(',', array_fill(0, count($unique), '?'));
    $sqlR = "SELECT `{$refund_id_col}` AS rid, `{$refund_status_col}` AS rstatus FROM refunds WHERE `{$refund_id_col}` IN ($placeholders)";
    $rstmt = $mysqli->prepare($sqlR);
    if ($rstmt) {
        $types = str_repeat('s', count($unique));
        $bind = [];
        $bind[] = $types;
        for ($i = 0; $i < count($unique); $i++) {
            $var = 'rval' . $i;
            $$var = $unique[$i];
            $bind[] = &$$var;
        }
        call_user_func_array([$rstmt, 'bind_param'], $bind);
        $rstmt->execute();
        $g = $rstmt->get_result();
        while ($rr = $g->fetch_assoc()) {
            $refund_map[$rr['rid']] = $rr['rstatus'];
        }
        $rstmt->close();
    }
}

// --- compute Last changed per booking (schema-aware) ---
$last_changed_map = []; // booking_id => timestamp (string)
$booking_ids = array_values(array_unique(array_map(function($r){ return $r['booking_id'] ?? null; }, $rows)));
$booking_ids = array_filter($booking_ids);

// 1) base: use booking_updated (if selected) else booking_date
foreach ($rows as $r) {
    $bid = $r['booking_id'] ?? null;
    if (!$bid) continue;
    if (!empty($r['booking_updated'])) {
        $last_changed_map[$bid] = $r['booking_updated'];
    } elseif (!empty($r['booking_date'])) {
        $last_changed_map[$bid] = $r['booking_date'];
    } else {
        $last_changed_map[$bid] = null;
    }
}

// 2) if reschedule_tx exists, override with latest processed_at or created_at
$chkRes = $mysqli->query("SHOW TABLES LIKE 'reschedule_tx'");
if ($chkRes && $chkRes->num_rows > 0 && !empty($booking_ids)) {
    $placeholders = implode(',', array_fill(0, count($booking_ids), '?'));
    $sql = "SELECT booking_id, MAX(COALESCE(processed_at, created_at)) AS last_change
            FROM reschedule_tx
            WHERE booking_id IN ($placeholders)
            GROUP BY booking_id";
    $stmtR = $mysqli->prepare($sql);
    if ($stmtR) {
        $types = str_repeat('s', count($booking_ids));
        $bind = [];
        $bind[] = $types;
        for ($i = 0; $i < count($booking_ids); $i++) {
            ${'b' . $i} = $booking_ids[$i];
            $bind[] = &${'b' . $i};
        }
        call_user_func_array([$stmtR, 'bind_param'], $bind);
        $stmtR->execute();
        $resR = $stmtR->get_result();
        while ($rr = $resR->fetch_assoc()) {
            $bid = $rr['booking_id'] ?? null;
            $lc = $rr['last_change'] ?? null;
            if ($bid && $lc) {
                // override if available (we prefer reschedule's timestamp)
                $last_changed_map[$bid] = $lc;
            }
        }
        $stmtR->close();
    }
}

// 6) render UI
?>
<div class="row justify-content-center">
  <div class="col-md-10">
    <h3 class="mb-3">✈️ My Bookings</h3>

    <?php if (!empty($_SESSION['flash_message'])): ?>
      <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
      <div class="alert alert-info">You haven’t booked any flights yet.</div>
    <?php else: ?>
      <table class="table table-striped align-middle">
        <thead class="table-primary">
          <tr>
            <th>Booking ID</th>
            <th>Flight</th>
            <th>Route</th>
            <th>Departure</th>
            <th>Booked On</th>
            <th>Last changed</th>
            <th>Status / Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
              $booking_id = $row['booking_id'] ?? '';
              $flight_code = $row['flight_code'] ?? ($row['booking_flight_id'] ?? '');

              // Resolve readable source / destination using airport_map (if possible)
              $source_raw = $row['source'] ?? ($row['source_id'] ?? '');
              $dest_raw   = $row['destination'] ?? ($row['destination_id'] ?? '');

              // if numeric id (or numeric string) try map; else if short code try uppercase map; fallback to raw
              $source_label = $source_raw;
              if (is_numeric($source_raw) && isset($airport_map[(string)$source_raw])) {
                  $source_label = $airport_map[(string)$source_raw];
              } elseif (is_string($source_raw) && strlen(trim($source_raw)) <= 6 && isset($airport_map[strtoupper(trim($source_raw))])) {
                  $source_label = $airport_map[strtoupper(trim($source_raw))];
              }

              $dest_label = $dest_raw;
              if (is_numeric($dest_raw) && isset($airport_map[(string)$dest_raw])) {
                  $dest_label = $airport_map[(string)$dest_raw];
              } elseif (is_string($dest_raw) && strlen(trim($dest_raw)) <= 6 && isset($airport_map[strtoupper(trim($dest_raw))])) {
                  $dest_label = $airport_map[strtoupper(trim($dest_raw))];
              }

              // safe fallback to code if map failed but raw is short code
              if (empty($source_label) && is_string($source_raw) && strlen(trim($source_raw)) <= 6) {
                  $source_label = strtoupper(trim($source_raw));
              }
              if (empty($dest_label) && is_string($dest_raw) && strlen(trim($dest_raw)) <= 6) {
                  $dest_label = strtoupper(trim($dest_raw));
              }

              $departure_time = $row['departure_time'] ?? '';
              $booking_date = $row['booking_date'] ?? '';
              $status = $row['status'] ?? '';
              $refund_id = $row['refund_id'] ?? null;
              $refund_status = $refund_id && isset($refund_map[$refund_id]) ? $refund_map[$refund_id] : null;

              // last changed computed earlier
              $lc = $last_changed_map[$booking_id] ?? null;
              $lc_display = $lc ? htmlspecialchars(date('M d, Y H:i', strtotime($lc))) : '—';
          ?>
            <tr>
              <td><?= htmlspecialchars($booking_id); ?></td>
              <td><?= htmlspecialchars($flight_code); ?></td>
              <td>
                <?= htmlspecialchars($source_label) ?> &nbsp;→&nbsp; <?= htmlspecialchars($dest_label) ?>
              </td>
              <td><?= $departure_time ? htmlspecialchars(date('M d, Y H:i', strtotime($departure_time))) : '—'; ?></td>
              <td><?= $booking_date ? htmlspecialchars(date('M d, Y H:i', strtotime($booking_date))) : '—'; ?></td>
              <td><?= $lc_display; ?></td>
              <td>
                <?php if (strtolower($status) === 'cancelled' || strtolower($status) === 'canceled'): ?>
                  <span class="badge bg-danger">Cancelled</span>

                  <?php if (!empty($refund_id)): ?>
                    <div class="small text-muted">
                      Refund ID: <?= htmlspecialchars($refund_id); ?>  
                      (<?= htmlspecialchars(ucfirst($refund_status ?? 'pending')); ?>)
                    </div>
                  <?php endif; ?>

                  <?php if ($refund_status === 'completed'): ?>
                    <div class="text-muted small mt-1">❌ Refund completed — cannot undo.</div>
                  <?php else: ?>
                    <form action="/FLIGHT_FRONTEND/undo_cancel.php" method="POST" style="display:inline;">
                      <input type="hidden" name="booking_id" value="<?= htmlspecialchars($booking_id); ?>">
                      <button type="submit" class="btn btn-sm btn-outline-success mt-1"
                        onclick="return confirm('Reactivate this cancelled booking?')">Undo Cancel</button>
                    </form>
                  <?php endif; ?>

                <?php else: ?>
                  <span class="badge bg-success"><?= htmlspecialchars(ucfirst($status ?: 'booked')); ?></span>
                  <div class="mt-1 d-flex flex-wrap align-items-center gap-1">
                    <a class="btn btn-sm btn-primary" href="/FLIGHT_FRONTEND/e_ticket.php?booking_id=<?= urlencode($booking_id) ?>">View E-ticket</a>

                    <!-- Reschedule button (opens modal) -->
                    <?php
                    // prefer specific code fields if available
                    $src_code = $row['src_code'] ?? $row['source'] ?? ($row['source_id'] ?? '');
                    $dst_code = $row['dst_code'] ?? $row['destination'] ?? ($row['destination_id'] ?? '');
                    ?>
                    <button class="btn btn-sm btn-outline-warning reschedule-btn"
                        data-booking-id="<?= htmlspecialchars($booking_id) ?>"
                        data-flight-id="<?= htmlspecialchars($flight_code) ?>"
                        data-source="<?= htmlspecialchars($src_code) ?>"
                        data-destination="<?= htmlspecialchars($dst_code) ?>"
                        data-departure="<?= htmlspecialchars($departure_time) ?>">
                      Reschedule
                    </button>

                    <!-- NEW: Cancel button -->
                    <button class="btn btn-sm btn-outline-danger cancel-btn"
                        data-booking-id="<?= htmlspecialchars($booking_id) ?>"
                        data-flight-id="<?= htmlspecialchars($flight_code) ?>"
                        data-source="<?= htmlspecialchars($src_code) ?>"
                        data-destination="<?= htmlspecialchars($dst_code) ?>"
                        data-departure="<?= htmlspecialchars($departure_time) ?>">
                      Cancel
                    </button>

                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

  </div>
</div>

<!-- Reschedule Modal (add near bottom of page) -->
<div class="modal fade" id="rescheduleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="rescheduleForm">
        <div class="modal-header">
          <h5 class="modal-title">Reschedule Booking</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <div id="rescheduleAlert"></div>

          <input type="hidden" name="booking_id" id="rs_booking_id">

          <div class="mb-2">
            <label class="form-label small">Current Flight</label>
            <input type="text" id="rs_current_flight" class="form-control" readonly>
          </div>

          <div class="mb-2">
            <label class="form-label">New Date <span class="text-danger">*</span></label>
            <input type="date" class="form-control" name="new_date" id="rs_new_date" required min="<?= date('Y-m-d') ?>">
            <div class="form-text">Pick the date you want to travel on.</div>
          </div>

          <div class="mb-2">
            <label class="form-label">Available Flights on chosen date (optional)</label>
            <select class="form-select" id="rs_flight_select" name="new_flight_id">
              <option value="">— Choose a preferred flight (optional) —</option>
            </select>
            <div class="form-text">After choosing a date we will fetch flights for your route on that day.</div>
          </div>

          <div class="mb-2">
            <label class="form-label">New Seat (optional)</label>
            <input type="text" name="new_seat" id="rs_new_seat" class="form-control" placeholder="e.g. 13C">
          </div>

          <div class="mb-2">
            <label class="form-label">Reason (optional)</label>
            <textarea name="reason" id="rs_reason" class="form-control" rows="3" placeholder="Changed travel plans"></textarea>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary" id="rs_submit_btn">Submit Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- CANCEL Modal (fixed server-side surcharge; user cannot edit) -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <form id="cancelForm">
        <div class="modal-header">
          <h5 class="modal-title text-danger">Cancel Booking</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <div id="cancelAlert"></div>

          <input type="hidden" name="booking_id" id="cn_booking_id">

          <div class="mb-2">
            <label class="form-label small">Booking</label>
            <input type="text" id="cn_current_flight" class="form-control" readonly>
          </div>

          <!-- fixed surcharge notice (server-enforced) -->
          <div class="mb-2">
            <div class="alert alert-warning mb-0">
              ⚠️ A fixed cancellation surcharge of <strong>₹<?= number_format($FIXED_SURCHARGE_AMT,2) ?></strong> will be applied automatically.
              Refund amount will be computed server-side and displayed after cancellation.
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label">Reason (optional)</label>
            <textarea name="reason" id="cn_reason" class="form-control" rows="3" placeholder="I can't travel"></textarea>
          </div>

          <div class="mb-2 small text-muted">
            Cancelling will mark the booking as cancelled and attempt to update any ticket records. Refund and surcharge values are handled server-side.
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-danger" id="cn_submit_btn">Confirm Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  // bootstrap Modal: ensure bootstrap script is loaded in your footer header
  const resModalEl = document.getElementById('rescheduleModal');
  const cnModalEl = document.getElementById('cancelModal');
  const rsForm = document.getElementById('rescheduleForm');
  const cancelForm = document.getElementById('cancelForm');
  const resAlert = document.getElementById('rescheduleAlert');
  const cnAlert = document.getElementById('cancelAlert');
  let bsResModal = null, bsCnModal = null;
  if (typeof bootstrap !== 'undefined') {
    if (resModalEl) bsResModal = new bootstrap.Modal(resModalEl);
    if (cnModalEl) bsCnModal = new bootstrap.Modal(cnModalEl);
  }

  function showResAlert(msg, type = 'success') { if (resAlert) resAlert.innerHTML = `<div class="alert alert-${type}">${msg}</div>`; }
  function clearResAlert() { if (resAlert) resAlert.innerHTML = ''; }
  function showCnAlert(msg, type = 'success') { if (cnAlert) cnAlert.innerHTML = `<div class="alert alert-${type}">${msg}</div>`; }
  function clearCnAlert() { if (cnAlert) cnAlert.innerHTML = ''; }

  // wire up reschedule buttons
  document.querySelectorAll('.reschedule-btn').forEach(btn => {
    btn.addEventListener('click', function(){
      clearResAlert();
      const bookingId = btn.dataset.bookingId || '';
      const flightId = btn.dataset.flightId || '';
      const departure = btn.dataset.departure || '';

      document.getElementById('rs_booking_id').value = bookingId;
      document.getElementById('rs_current_flight').value = flightId + (departure ? ' — ' + departure : '');
      document.getElementById('rs_new_date').value = ''; // reset
      document.getElementById('rs_flight_select').innerHTML = '<option value="">— Choose a preferred flight (optional) —</option>';
      document.getElementById('rs_new_seat').value = '';
      document.getElementById('rs_reason').value = '';

      // store route info for AJAX fetch (use modal dataset)
      resModalEl.dataset.source = btn.dataset.source || '';
      resModalEl.dataset.dest = btn.dataset.destination || '';

      if (bsResModal) bsResModal.show();
    });
  });

  // when date changes, fetch flights for that route + date (reschedule)
  const dateInput = document.getElementById('rs_new_date');
  if (dateInput) {
    dateInput.addEventListener('change', function(){
      clearResAlert();
      const date = this.value;
      if (!date) return;
      const bookingId = document.getElementById('rs_booking_id').value;
      const source = resModalEl.dataset.source || '';
      const dest = resModalEl.dataset.dest || '';

      const sel = document.getElementById('rs_flight_select');
      sel.innerHTML = '<option>Loading...</option>';

      const fd = new FormData();
      fd.append('date', date);
      fd.append('source', source);
      fd.append('destination', dest);
      fd.append('booking_id', bookingId);

      fetch('/FLIGHT_FRONTEND/api/find_flights.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(j => {
          sel.innerHTML = '<option value="">— Choose a preferred flight (optional) —</option>';
          if (!j.ok) {
            showResAlert(j.error || 'Failed to fetch flights', 'danger');
            return;
          }
          if (!j.flights || j.flights.length === 0) {
            sel.innerHTML = '<option value="">No matching flights found for this date</option>';
            return;
          }
          j.flights.forEach(f => {
            const label = `${f.flight_id || ''} — ${f.dep_time || ''} → ${f.arr_time || ''} — ${f.airline || ''}${f.price ? ' • ₹' + f.price : ''}`;
            const opt = document.createElement('option');
            opt.value = f.flight_id;
            opt.textContent = label;
            sel.appendChild(opt);
          });
        })
        .catch(err => {
          sel.innerHTML = '<option value="">Error loading flights</option>';
          showResAlert('Network error while fetching flights', 'danger');
          console.error(err);
        });
    });
  }

  // submit reschedule request
  if (rsForm) {
    rsForm.addEventListener('submit', function(e){
      e.preventDefault();
      clearResAlert();
      const form = new FormData(rsForm);
      // auto-apply
      form.append('auto_process', '1');

      const btn = document.getElementById('rs_submit_btn');
      btn.disabled = true;
      btn.textContent = 'Submitting...';

      fetch('/FLIGHT_FRONTEND/api/reschedule_booking.php', { method: 'POST', body: form, credentials: 'same-origin' })
        .then(r => r.json())
        .then(j => {
          if (j.ok) {
            showResAlert(j.message || 'Reschedule request submitted (recorded).', 'success');
            setTimeout(() => { location.reload(); }, 900);
          } else {
            showResAlert(j.error || 'Failed to submit request', 'danger');
          }
        })
        .catch(err => {
          showResAlert('Network/server error while submitting', 'danger');
          console.error(err);
        })
        .finally(() => {
          btn.disabled = false;
          btn.textContent = 'Submit Request';
        });
    });
  }

  // wire up cancel buttons
  document.querySelectorAll('.cancel-btn').forEach(btn => {
    btn.addEventListener('click', function(){
      clearCnAlert();
      const bookingId = btn.dataset.bookingId || '';
      const flightId = btn.dataset.flightId || '';
      const departure = btn.dataset.departure || '';

      document.getElementById('cn_booking_id').value = bookingId;
      document.getElementById('cn_current_flight').value = flightId + (departure ? ' — ' + departure : '');
      document.getElementById('cn_reason').value = '';

      // store route info if you need it
      cnModalEl.dataset.source = btn.dataset.source || '';
      cnModalEl.dataset.dest = btn.dataset.destination || '';

      if (bsCnModal) bsCnModal.show();
    });
  });

  // submit cancel request (no client-side surcharge fields sent)
  if (cancelForm) {
    cancelForm.addEventListener('submit', function(e){
      e.preventDefault();
      clearCnAlert();
      const form = new FormData(cancelForm);

      const btn = document.getElementById('cn_submit_btn');
      btn.disabled = true;
      btn.textContent = 'Cancelling...';

      fetch('/FLIGHT_FRONTEND/api/cancel_ticket.php', { method: 'POST', body: form, credentials: 'same-origin' })
        .then(r => r.json())
        .then(j => {
          if (j.ok) {
            showCnAlert(j.message || 'Cancelled.', 'success');
            // show refund & surcharge if backend returned it
            if (j.refund || j.surcharge) {
              const info = `Refund: ₹${j.refund || '0.00'} (Surcharge: ₹${j.surcharge || '0.00'})`;
              showCnAlert((j.message || 'Cancelled.') + '<br><small>' + info + '</small>', 'success');
            }
            // reload to show updated state (booking status, last changed, e-ticket)
            setTimeout(() => { location.reload(); }, 900);
          } else {
            showCnAlert(j.error || 'Failed to cancel', 'danger');
          }
        })
        .catch(err => {
          showCnAlert('Network/server error while cancelling', 'danger');
          console.error(err);
        })
        .finally(() => {
          btn.disabled = false;
          btn.textContent = 'Confirm Cancel';
        });
    });
  }

})();
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
