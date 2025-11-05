<?php
// /FLIGHT_FRONTEND/api/find_flights.php
// Returns flights for a given date + source->destination (strict same-route)
// Accepts POST (or GET): date (YYYY-MM-DD), source (code or id), destination (code or id), optional booking_id (to infer route)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
safe_start_session();

function dbg($m) {
    error_log("[".date('Y-m-d H:i:s')."] " . $m . PHP_EOL, 3, __DIR__ . '/../tools/find_flights_debug.log');
}

try {
    $in = function($k){ return trim($_POST[$k] ?? $_GET[$k] ?? ''); };
    $date = $in('date');              // yyyy-mm-dd
    $source = $in('source');          // code or id
    $destination = $in('destination'); // code or id
    $booking_id = $in('booking_id');  // optional fallback

    if ($date === '' && empty($booking_id)) {
        echo json_encode(['ok'=>false,'error'=>'Missing date and no booking_id to infer route.']);
        exit;
    }

    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['ok'=>false,'error'=>'date must be YYYY-MM-DD']);
        exit;
    }

    // detect flight table
    $flight_table = $mysqli->query("SHOW TABLES LIKE 'flight'")->num_rows > 0 ? 'flight' :
                    ($mysqli->query("SHOW TABLES LIKE 'flights'")->num_rows > 0 ? 'flights' : 'flight');

    // 1) Try to infer route via booking->flight (preferred)
    if (($source === '' || $destination === '') && $booking_id !== '') {
        $stmt = $mysqli->prepare(
            "SELECT b.flight_id, f.source AS f_source, f.destination AS f_destination, f.source_id AS f_source_id, f.destination_id AS f_destination_id
             FROM bookings b
             LEFT JOIN `{$flight_table}` f ON b.flight_id = f.flight_id
             WHERE b.booking_id = ? LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('s', $booking_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                if ($source === '') {
                    if (!empty($row['f_source'])) $source = $row['f_source'];
                    elseif (!empty($row['f_source_id'])) $source = $row['f_source_id'];
                }
                if ($destination === '') {
                    if (!empty($row['f_destination'])) $destination = $row['f_destination'];
                    elseif (!empty($row['f_destination_id'])) $destination = $row['f_destination_id'];
                }
            }
        } else {
            dbg("booking->flight lookup failed: " . $mysqli->error);
        }
    }

    // 1b) If still missing route, try to read route columns directly from bookings table (fallback)
    if (($source === '' || $destination === '') && $booking_id !== '') {
        $stmtB = $mysqli->prepare("SELECT source, destination, source_id, destination_id, src_code, dst_code FROM bookings WHERE booking_id = ? LIMIT 1");
        if ($stmtB) {
            $stmtB->bind_param('s', $booking_id);
            $stmtB->execute();
            $brow = $stmtB->get_result()->fetch_assoc();
            $stmtB->close();
            if ($brow) {
                if ($source === '') {
                    if (!empty($brow['src_code'])) $source = $brow['src_code'];
                    elseif (!empty($brow['source'])) $source = $brow['source'];
                    elseif (!empty($brow['source_id'])) $source = $brow['source_id'];
                }
                if ($destination === '') {
                    if (!empty($brow['dst_code'])) $destination = $brow['dst_code'];
                    elseif (!empty($brow['destination'])) $destination = $brow['destination'];
                    elseif (!empty($brow['destination_id'])) $destination = $brow['destination_id'];
                }
            }
        } else {
            dbg("bookings fallback prepare failed: " . $mysqli->error);
        }
    }

    // require both source and destination for strict same-route filtering
    if ($source === '' || $destination === '') {
        echo json_encode(['ok'=>false,'error'=>'Source and destination are required (provide them or booking_id to infer).']);
        exit;
    }

    // inspect flight table columns
    $cols = [];
    $cres = $mysqli->query("SHOW COLUMNS FROM `{$flight_table}`");
    if ($cres) while ($r = $cres->fetch_assoc()) $cols[] = $r['Field'];

    $col_src_id   = in_array('source_id', $cols) ? 'source_id' : (in_array('src', $cols) ? 'src' : (in_array('source', $cols) ? 'source' : null));
    $col_dst_id   = in_array('destination_id', $cols) ? 'destination_id' : (in_array('dst', $cols) ? 'dst' : (in_array('destination', $cols) ? 'destination' : null));
    $col_dep_dt   = in_array('departure_time', $cols) ? 'departure_time' : (in_array('d_time', $cols) ? 'd_time' : (in_array('flight_date', $cols) ? 'flight_date' : null));
    $col_arr_dt   = in_array('arrival_time', $cols) ? 'arrival_time' : (in_array('a_time', $cols) ? 'a_time' : null);
    $col_price    = in_array('price', $cols) ? 'price' : (in_array('base_price', $cols) ? 'base_price' : null);
    $col_flightid = in_array('flight_id', $cols) ? 'flight_id' : (in_array('flight_code', $cols) ? 'flight_code' : (in_array('id', $cols) ? 'id' : null));

    $where = [];
    $params = [];
    $types = '';

    if ($col_dep_dt) {
        $where[] = "DATE(`{$col_dep_dt}`) = ?";
        $types .= 's';
        $params[] = $date;
    }

    // source filter (smart match: try numeric->id else text)
    if ($source !== '') {
        if (ctype_digit((string)$source) && $col_src_id && in_array($col_src_id, $cols)) {
            $where[] = "f.`{$col_src_id}` = ?";
            $types .= 'i';
            $params[] = (int)$source;
        } else {
            if ($col_src_id && in_array($col_src_id, $cols)) {
                $where[] = " (f.`{$col_src_id}` = ? OR f.`source` = ?) ";
                $types .= 'ss';
                $params[] = $source;
                $params[] = $source;
            } else {
                $where[] = " f.`source` = ? ";
                $types .= 's';
                $params[] = $source;
            }
        }
    }

    // destination filter
    if ($destination !== '') {
        if (ctype_digit((string)$destination) && $col_dst_id && in_array($col_dst_id, $cols)) {
            $where[] = "f.`{$col_dst_id}` = ?";
            $types .= 'i';
            $params[] = (int)$destination;
        } else {
            if ($col_dst_id && in_array($col_dst_id, $cols)) {
                $where[] = " (f.`{$col_dst_id}` = ? OR f.`destination` = ?) ";
                $types .= 'ss';
                $params[] = $destination;
                $params[] = $destination;
            } else {
                $where[] = " f.`destination` = ? ";
                $types .= 's';
                $params[] = $destination;
            }
        }
    }

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sel = [];
    $sel[] = "f.`" . $mysqli->real_escape_string($col_flightid) . "` AS flight_id";
    if ($col_dep_dt) $sel[] = "f.`{$col_dep_dt}` AS dep_time";
    if ($col_arr_dt) $sel[] = "f.`{$col_arr_dt}` AS arr_time";
    if ($col_price) $sel[] = "COALESCE(f.`{$col_price}`,0) AS price";
    $sel[] = "COALESCE(al.airline_name, '') AS airline";

    $sql = "SELECT " . implode(", ", $sel) .
           " FROM `{$flight_table}` f LEFT JOIN airline al ON f.airline_id = al.airline_id " .
           " {$where_sql} ORDER BY " . ($col_dep_dt ?? $col_flightid) . " ASC LIMIT 200";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        dbg("prepare failed for find flights: " . $mysqli->error . " SQL: $sql");
        echo json_encode(['ok'=>false,'error'=>'DB prepare error']);
        exit;
    }

    if (!empty($params)) {
        $bind = [];
        $bind[] = $types;
        for ($i = 0; $i < count($params); $i++) {
            $bindVar = 'p' . $i;
            $$bindVar = $params[$i];
            $bind[] = &$$bindVar;
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    if (!$stmt->execute()) {
        dbg("execute failed find flights: " . $stmt->error . " SQL: $sql");
        echo json_encode(['ok'=>false,'error'=>'DB execute failed']);
        exit;
    }

    $res = $stmt->get_result();
    $flights = [];
    while ($f = $res->fetch_assoc()) {
        $flights[] = [
            'flight_id' => $f['flight_id'] ?? '',
            'dep_time'  => $f['dep_time'] ?? '',
            'arr_time'  => $f['arr_time'] ?? '',
            'price'     => $f['price'] ?? 0,
            'airline'   => $f['airline'] ?? ''
        ];
    }
    $stmt->close();

    echo json_encode(['ok'=>true,'flights'=>$flights]);
    exit;

} catch (Throwable $e) {
    dbg("Exception find_flights: " . $e->getMessage());
    echo json_encode(['ok'=>false,'error'=>'Internal server error']);
    exit;
}
