<?php
// my_bookings.php (schema-aware, defensive) - updated to show airport labels
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
/**
 * We'll attempt to fetch airports for any numeric source/destination values
 * and for any source/destination that look like airport codes.
 * The map will contain keys by id (string) and also by code (uppercase).
 */
$airport_map = []; // e.g. ['3' => 'BOM — Mumbai', 'BOM' => 'BOM — Mumbai']
$airport_table_exists = ($mysqli->query("SHOW TABLES LIKE 'airport'")->num_rows > 0);

if ($airport_table_exists) {
    // discover airport table columns
    $airport_cols = [];
    $ac = $mysqli->query("SHOW COLUMNS FROM airport");
    if ($ac) {
        while ($c = $ac->fetch_assoc()) $airport_cols[] = $c['Field'];
    }

    $col_id   = in_array('airport_id', $airport_cols) ? 'airport_id' : (in_array('id', $airport_cols) ? 'id' : null);
    $col_code = in_array('airport_code', $airport_cols) ? 'airport_code' : (in_array('code', $airport_cols) ? 'code' : null);
    $col_name = in_array('airport_name', $airport_cols) ? 'airport_name' : (in_array('name', $airport_cols) ? 'name' : null);
    $col_city = in_array('city', $airport_cols) ? 'city' : (in_array('city_name', $airport_cols) ? 'city_name' : null);

    // collect potential ids and codes from rows
    $ids = [];
    $codes = [];
    foreach ($rows as $r) {
        // prefer numeric values
        if (isset($r['source']) && is_numeric($r['source'])) $ids[] = (int)$r['source'];
        if (isset($r['destination']) && is_numeric($r['destination'])) $ids[] = (int)$r['destination'];
        if (isset($r['source_id']) && is_numeric($r['source_id'])) $ids[] = (int)$r['source_id'];
        if (isset($r['destination_id']) && is_numeric($r['destination_id'])) $ids[] = (int)$r['destination_id'];

        // if source/destination are strings that look like 2-4 letter codes, collect as code
        if (!empty($r['source']) && is_string($r['source']) && preg_match('/^[A-Za-z]{2,4}$/', trim($r['source']))) {
            $codes[] = strtoupper(trim($r['source']));
        }
        if (!empty($r['destination']) && is_string($r['destination']) && preg_match('/^[A-Za-z]{2,4}$/', trim($r['destination']))) {
            $codes[] = strtoupper(trim($r['destination']));
        }
    }
    $ids = array_values(array_unique(array_filter($ids, function($v){return $v>0;})));
    $codes = array_values(array_unique(array_filter(array_map('trim', $codes))));

    // fetch by ids if any
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

    // fetch by codes (for rows that had codes but not ids)
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
// end airport mapping

// 5) If refunds table exists and we collected refund ids, fetch statuses
$refund_map = [];
$refunds_exist = false;
$chkR = $mysqli->query("SHOW TABLES LIKE 'refunds'");
if ($chkR && $chkR->num_rows > 0) $refunds_exist = true;

if ($refunds_exist && !empty($refund_ids)) {
    // unique ids
    $unique = array_values(array_unique($refund_ids));
    // We'll try to find refund id column and status column in refunds table
    $refund_cols = [];
    $rDef = $mysqli->query("SHOW COLUMNS FROM refunds");
    if ($rDef) {
        while ($c = $rDef->fetch_assoc()) $refund_cols[] = $c['Field'];
    }
    $refund_id_col = pick_col(['refund_id','id','refund_ref','refund_id_pk'], $refund_cols, $refund_cols[0] ?? null);
    $refund_status_col = pick_col(['status','state','refund_status'], $refund_cols, $refund_cols[1] ?? 'status');

    // build safe IN() prepared query
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
          ?>
            <tr>
              <td><?= htmlspecialchars($booking_id); ?></td>
              <td><?= htmlspecialchars($flight_code); ?></td>
              <td>
                <?= htmlspecialchars($source_label) ?> &nbsp;→&nbsp; <?= htmlspecialchars($dest_label) ?>
              </td>
              <td><?= $departure_time ? htmlspecialchars(date('M d, Y H:i', strtotime($departure_time))) : '—'; ?></td>
              <td><?= $booking_date ? htmlspecialchars(date('M d, Y H:i', strtotime($booking_date))) : '—'; ?></td>
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

                    <form action="/FLIGHT_FRONTEND/cancel_booking.php" method="POST" style="display:inline;">
                      <input type="hidden" name="booking_id" value="<?= htmlspecialchars($booking_id); ?>">
                      <button type="submit" class="btn btn-sm btn-danger"
                        onclick="return confirm('Are you sure you want to cancel this booking?')">Cancel Ticket</button>
                    </form>
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

<?php
require_once __DIR__ . '/includes/footer.php';
?>
