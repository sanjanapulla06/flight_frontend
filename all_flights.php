<?php
// all_flights.php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$passport_no = $_SESSION['passport_no'] ?? null;

// simple helpers used below
function col_exists($mysqli, $table, $col) {
    $t = $mysqli->real_escape_string($table);
    $c = $mysqli->real_escape_string($col);
    $res = $mysqli->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return ($res && $res->num_rows > 0);
}
function pick_col(array $candidates, array $available, $fallback = null) {
    foreach ($candidates as $col) if (in_array($col, $available)) return $col;
    return $fallback;
}

// detect flights table name
$flight_table = 'flight';
$chk = $mysqli->query("SHOW TABLES LIKE 'flight'");
if (!$chk || $chk->num_rows === 0) {
    $chk2 = $mysqli->query("SHOW TABLES LIKE 'flights'");
    if ($chk2 && $chk2->num_rows > 0) $flight_table = 'flights';
}

// get flight table columns
$flight_cols = [];
$colRes = $mysqli->query("SHOW COLUMNS FROM {$flight_table}");
if ($colRes) while ($r = $colRes->fetch_assoc()) $flight_cols[] = $r['Field'];

// choose common columns (fallbacks)
$col_fid = pick_col(['flight_id','id','flight_no','flight_code'], $flight_cols, 'flight_id');
$col_src_id = pick_col(['source_id','source','src','src_code'], $flight_cols, 'source_id');
$col_dst_id = pick_col(['destination_id','destination','dst','dst_code'], $flight_cols, 'destination_id');
$col_depart_dt = pick_col(['departure_time','d_time','dep_time','departure'], $flight_cols, 'departure_time');
$col_arrive_dt = pick_col(['arrival_time','a_time','arr_time','arrival'], $flight_cols, 'arrival_time');
$col_price = pick_col(['price','base_price','fare','amount'], $flight_cols, 'price');
$col_airline = pick_col(['airline_id','airline','airline_name'], $flight_cols, 'airline_id');
$col_status = pick_col(['status','flight_status'], $flight_cols, 'status');
$col_gate = pick_col(['gate','departure_gate','boarding_gate'], $flight_cols, null);
$col_terminal = pick_col(['terminal','term'], $flight_cols, null);
$col_belt = pick_col(['baggage_belt','belt_no','belt'], $flight_cols, null);

// build airport map for codes/names (if airport table exists)
$airport_map = [];
if ($mysqli->query("SHOW TABLES LIKE 'airport'")->num_rows > 0) {
    // find columns
    $apCols = [];
    $ar = $mysqli->query("SHOW COLUMNS FROM airport");
    if ($ar) while ($rr = $ar->fetch_assoc()) $apCols[] = $rr['Field'];
    $ap_id = in_array('airport_id', $apCols) ? 'airport_id' : (in_array('id',$apCols)?'id':null);
    $ap_code = in_array('airport_code', $apCols) ? 'airport_code' : (in_array('code',$apCols)?'code':null);
    $ap_name = in_array('airport_name', $apCols) ? 'airport_name' : (in_array('name',$apCols)?'name':null);
    $ap_city = in_array('city', $apCols) ? 'city' : (in_array('city_name',$apCols)?'city_name':null);

    if ($ap_id) {
        $sqlA = "SELECT " . ($ap_id ? "`{$ap_id}`":"airport_id") .
                ($ap_code ? ", `{$ap_code}`" : "") .
                ($ap_name ? ", `{$ap_name}`" : "") .
                ($ap_city ? ", `{$ap_city}`" : "") .
                " FROM airport LIMIT 1000";
        $resA = $mysqli->query($sqlA);
        if ($resA) {
            while ($r = $resA->fetch_assoc()) {
                $idv = (string)($r[$ap_id] ?? '');
                $code = $ap_code ? ($r[$ap_code] ?? '') : '';
                $labelName = $ap_city ? ($r[$ap_city] ?? '') : ($ap_name ? ($r[$ap_name] ?? '') : '');
                $label = $code && $labelName ? trim("{$code} — {$labelName}") : ($labelName ?: ($code ?: "Airport {$idv}"));
                if ($idv) $airport_map[$idv] = $label;
                if ($code) $airport_map[strtoupper($code)] = $label;
            }
        }
    }
}

// request inputs (filters & paging)
$filter_src = trim((string)($_GET['source'] ?? ''));
$filter_dst = trim((string)($_GET['destination'] ?? ''));
$filter_date = trim((string)($_GET['date'] ?? '')); // yyyy-mm-dd
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;
$sort = in_array($_GET['sort'] ?? '', ['dep_asc','dep_desc','price_asc','price_desc']) ? $_GET['sort'] : 'dep_asc';

// build WHERE and bindings dynamically
$where_clauses = [];
$bind_types = '';
$bind_vals = [];

if ($filter_src !== '') {
    // support numeric id or code
    if (ctype_digit($filter_src)) {
        $where_clauses[] = " (f.`{$col_src_id}` = ?) ";
        $bind_types .= 'i';
        $bind_vals[] = (int)$filter_src;
    } else {
        // compare string either to airport code or flight table source column
        if (in_array('source', $flight_cols) || in_array('src', $flight_cols)) {
            $where_clauses[] = " (f.`{$col_src_id}` = ? OR f.`source` LIKE ?) ";
            $bind_types .= 'ss';
            $bind_vals[] = $filter_src;
            $bind_vals[] = '%'.$filter_src.'%';
        } else {
            $where_clauses[] = " (f.`{$col_src_id}` = ?) ";
            $bind_types .= 's';
            $bind_vals[] = $filter_src;
        }
    }
}
if ($filter_dst !== '') {
    if (ctype_digit($filter_dst)) {
        $where_clauses[] = " (f.`{$col_dst_id}` = ?) ";
        $bind_types .= 'i';
        $bind_vals[] = (int)$filter_dst;
    } else {
        if (in_array('destination', $flight_cols) || in_array('dst', $flight_cols)) {
            $where_clauses[] = " (f.`{$col_dst_id}` = ? OR f.`destination` LIKE ?) ";
            $bind_types .= 'ss';
            $bind_vals[] = $filter_dst;
            $bind_vals[] = '%'.$filter_dst.'%';
        } else {
            $where_clauses[] = " (f.`{$col_dst_id}` = ?) ";
            $bind_types .= 's';
            $bind_vals[] = $filter_dst;
        }
    }
}
if ($filter_date !== '') {
    // if flight has flight_date column use that, else compare departure_time date-part
    if (col_exists($mysqli, $flight_table, 'flight_date')) {
        $where_clauses[] = " (f.flight_date = ?) ";
        $bind_types .= 's';
        $bind_vals[] = $filter_date;
    } else {
        $where_clauses[] = " (DATE(f.`{$col_depart_dt}`) = ?) ";
        $bind_types .= 's';
        $bind_vals[] = $filter_date;
    }
}

$where_sql = count($where_clauses) ? ' WHERE ' . implode(' AND ', $where_clauses) : '';

// sorting
$order_sql = "ORDER BY ";
switch ($sort) {
    case 'dep_desc': $order_sql .= " f.`{$col_depart_dt}` DESC "; break;
    case 'price_asc': $order_sql .= " COALESCE(f.`{$col_price}`,0) ASC "; break;
    case 'price_desc': $order_sql .= " COALESCE(f.`{$col_price}`,0) DESC "; break;
    default: $order_sql .= " f.`{$col_depart_dt}` ASC ";
}

// build select list (only columns we detected)
$selects = [
    "f.`{$col_fid}` AS flight_id",
    (in_array($col_depart_dt, $flight_cols) ? "f.`{$col_depart_dt}` AS departure_time" : "NULL AS departure_time"),
    (in_array($col_arrive_dt, $flight_cols) ? "f.`{$col_arrive_dt}` AS arrival_time" : "NULL AS arrival_time"),
    (in_array($col_price, $flight_cols) ? "COALESCE(f.`{$col_price}`,0) AS price" : "0 AS price"),
    (in_array($col_status, $flight_cols) ? "f.`{$col_status}` AS status" : "NULL AS status")
];
// try to resolve source/destination to airport.code & name if flight stores ids
$joins = "";
if (in_array($col_src_id, $flight_cols) && $mysqli->query("SHOW TABLES LIKE 'airport'")->num_rows > 0) {
    $joins .= " LEFT JOIN airport src ON f.`{$col_src_id}` = src.airport_id ";
    $selects[] = "COALESCE(src.airport_code, src.airport_name, f.`{$col_src_id}`) AS src_label";
} else {
    // flight stores source as string
    $selects[] = "COALESCE(f.`source`, '—') AS src_label";
}
if (in_array($col_dst_id, $flight_cols) && $mysqli->query("SHOW TABLES LIKE 'airport'")->num_rows > 0) {
    $joins .= " LEFT JOIN airport dst ON f.`{$col_dst_id}` = dst.airport_id ";
    $selects[] = "COALESCE(dst.airport_code, dst.airport_name, f.`{$col_dst_id}`) AS dst_label";
} else {
    $selects[] = "COALESCE(f.`destination`, '—') AS dst_label";
}

// airline join if available
if ($col_airline && in_array($col_airline, $flight_cols) && $mysqli->query("SHOW TABLES LIKE 'airline'")->num_rows > 0) {
    $joins .= " LEFT JOIN airline al ON f.`{$col_airline}` = al.airline_id ";
    $selects[] = "COALESCE(al.airline_name, '') AS airline_name";
} else {
    $selects[] = "NULL AS airline_name";
}

// gate/terminal/belt if present
if ($col_gate && in_array($col_gate, $flight_cols)) $selects[] = "f.`{$col_gate}` AS gate";
else $selects[] = "NULL AS gate";
if ($col_terminal && in_array($col_terminal, $flight_cols)) $selects[] = "f.`{$col_terminal}` AS terminal";
else $selects[] = "NULL AS terminal";
if ($col_belt && in_array($col_belt, $flight_cols)) $selects[] = "f.`{$col_belt}` AS baggage_belt";
else $selects[] = "NULL AS baggage_belt";

$select_sql = implode(",\n    ", $selects);

// count total for pagination
$count_sql = "SELECT COUNT(*) AS cnt FROM {$flight_table} f {$joins} {$where_sql}";
$count_stmt = $mysqli->prepare($count_sql);
if ($count_stmt) {
    if ($bind_types) call_user_func_array([$count_stmt, 'bind_param'], array_merge([$bind_types], array_map(function(&$v){return $v;}, $bind_vals)));
    $count_stmt->execute();
    $cnt_row = $count_stmt->get_result()->fetch_assoc();
    $total = (int)($cnt_row['cnt'] ?? 0);
    $count_stmt->close();
} else {
    $total = 0;
}

// main query with limit
$sql = "SELECT {$select_sql} FROM {$flight_table} f {$joins} {$where_sql} {$order_sql} LIMIT ? OFFSET ?";
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    echo "<div class='container mt-4'><div class='alert alert-danger'>Query prepare failed: " . htmlspecialchars($mysqli->error) . "</div></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// bind params (dynamic)
$bind_final_types = $bind_types . 'ii';
$bind_final_vals = array_merge($bind_vals, [$per_page, $offset]);
$bind_params = [];
$bind_params[] = $bind_final_types;
foreach ($bind_final_vals as $i => $v) {
    // need references for call_user_func_array
    $bind_params[] = & $bind_final_vals[$i];
}
call_user_func_array([$stmt, 'bind_param'], $bind_params);
$stmt->execute();
$res = $stmt->get_result();

$flights = [];
while ($r = $res->fetch_assoc()) $flights[] = $r;
$stmt->close();

// helper to generate page links
$total_pages = max(1, ceil($total / $per_page));
function page_url($p) {
    $qs = $_GET;
    $qs['page'] = $p;
    return '?'.http_build_query($qs);
}
?>
<div class="container my-4">
  <h3>All Flights</h3>

  <form class="row g-2 mb-3" method="get" action="">
    <div class="col-md-3">
      <input name="source" value="<?= htmlspecialchars($filter_src) ?>" class="form-control" placeholder="Source (id or code or name)">
    </div>
    <div class="col-md-3">
      <input name="destination" value="<?= htmlspecialchars($filter_dst) ?>" class="form-control" placeholder="Destination (id or code or name)">
    </div>
    <div class="col-md-2">
      <input name="date" type="date" value="<?= htmlspecialchars($filter_date) ?>" class="form-control">
    </div>
    <div class="col-md-2">
      <select name="sort" class="form-control">
        <option value="dep_asc" <?= $sort==='dep_asc' ? 'selected':'' ?>>Departure ↑</option>
        <option value="dep_desc" <?= $sort==='dep_desc' ? 'selected':'' ?>>Departure ↓</option>
        <option value="price_asc" <?= $sort==='price_asc' ? 'selected':'' ?>>Price ↑</option>
        <option value="price_desc" <?= $sort==='price_desc' ? 'selected':'' ?>>Price ↓</option>
      </select>
    </div>
    <div class="col-md-2 d-grid">
      <button class="btn btn-primary">Filter</button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>Flight</th>
          <th>Airline</th>
          <th>Route</th>
          <th>Departure</th>
          <th>Arrival</th>
          <th>Gate / Term</th>
          <th>Belt</th>
          <th>Price</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($flights)): ?>
          <tr><td colspan="10" class="text-center">No flights found.</td></tr>
        <?php else: foreach ($flights as $f): 
            $flight_id = $f['flight_id'] ?? '—';
            $airline = $f['airline_name'] ?? '—';
            $src_label = $f['src_label'] ?? '—';
            $dst_label = $f['dst_label'] ?? '—';
            $dep = $f['departure_time'] ? date('M d, Y H:i', strtotime($f['departure_time'])) : '—';
            $arr = $f['arrival_time'] ? date('M d, Y H:i', strtotime($f['arrival_time'])) : '—';
            $price = (isset($f['price']) ? number_format((float)$f['price'],2) : '0.00');
            $status = $f['status'] ?? '—';
            $gate = $f['gate'] ?? '—';
            $term = $f['terminal'] ?? '—';
            $belt = $f['baggage_belt'] ?? '—';
        ?>
        <tr>
          <td><strong><?= htmlspecialchars($flight_id) ?></strong></td>
          <td><?= htmlspecialchars($airline) ?></td>
          <td><?= htmlspecialchars($src_label) ?> → <?= htmlspecialchars($dst_label) ?></td>
          <td><?= htmlspecialchars($dep) ?></td>
          <td><?= htmlspecialchars($arr) ?></td>
          <td><?= htmlspecialchars($gate) ?> / <?= htmlspecialchars($term) ?></td>
          <td><?= htmlspecialchars($belt) ?></td>
          <td>₹<?= htmlspecialchars($price) ?></td>
          <td><?= htmlspecialchars(ucfirst($status)) ?></td>
          <td style="white-space:nowrap">
            <a class="btn btn-sm btn-outline-primary" href="/FLIGHT_FRONTEND/book_flight.php?flight_id=<?= urlencode($flight_id) ?>">Book</a>
            <a class="btn btn-sm btn-outline-secondary" href="/FLIGHT_FRONTEND/e_ticket.php?booking_id=&ticket_no=&flight_id=<?= urlencode($flight_id) ?>">View</a>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- pagination -->
  <nav aria-label="pagination">
    <ul class="pagination">
      <li class="page-item <?= $page<=1 ? 'disabled':'' ?>"><a class="page-link" href="<?= $page<=1 ? '#' : page_url($page-1) ?>">Prev</a></li>
      <?php for ($p = 1; $p <= min($total_pages, 10); $p++): ?>
        <li class="page-item <?= $p==$page ? 'active':'' ?>"><a class="page-link" href="<?= page_url($p) ?>"><?= $p ?></a></li>
      <?php endfor; ?>
      <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $page >= $total_pages ? '#' : page_url($page+1) ?>">Next</a></li>
    </ul>
  </nav>

  <div class="small text-muted mt-2">Showing page <?= $page ?> of <?= $total_pages ?> — <?= $total ?> flights total.</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
