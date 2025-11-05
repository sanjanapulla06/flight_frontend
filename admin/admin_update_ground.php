<?php
// admin_update_ground.php
// Admin-only API to upsert gate/terminal/baggage_belt for a flight.
// POST params: flight_id, terminal, gate, baggage_belt
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
if (function_exists('safe_start_session')) safe_start_session();
else if (session_status()===PHP_SESSION_NONE) session_start();

function resp($ok, $data = []) {
    echo json_encode(array_merge(['ok' => $ok], $data));
    exit;
}

// Basic admin auth check — adjust according to how your auth stores role
$admin_ok = !empty($_SESSION['admin_role']) && in_array($_SESSION['admin_role'], ['admin','superadmin']);
if (!$admin_ok) {
    http_response_code(403);
    resp(false, ['error'=>'forbidden']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    resp(false, ['error'=>'method']);
}

$flight_id = trim((string)($_POST['flight_id'] ?? ''));
$terminal  = trim((string)($_POST['terminal'] ?? ''));
$gate      = trim((string)($_POST['gate'] ?? ''));
$belt      = trim((string)($_POST['baggage_belt'] ?? ''));

if ($flight_id === '') {
    http_response_code(400);
    resp(false, ['error'=>'missing flight_id']);
}

// ensure flight exists (defensive)
// detect flight table
$flight_table = $mysqli->query("SHOW TABLES LIKE 'flight'")->num_rows > 0 ? 'flight' :
                ($mysqli->query("SHOW TABLES LIKE 'flights'")->num_rows > 0 ? 'flights' : 'flight');

$stmt = $mysqli->prepare("SELECT 1 FROM `{$flight_table}` WHERE flight_id = ? LIMIT 1");
if (!$stmt) resp(false, ['error'=>'db_prepare_failed', 'db'=>$mysqli->error]);
$stmt->bind_param('s', $flight_id);
$stmt->execute();
$exists = $stmt->get_result()->fetch_row();
$stmt->close();
if (!$exists) resp(false, ['error'=>'flight_not_found']);

// ensure flight_ground_info exists (create if missing)
$gcheck = $mysqli->query("SHOW TABLES LIKE 'flight_ground_info'");
if (!$gcheck || $gcheck->num_rows === 0) {
    $create_sql = "
      CREATE TABLE IF NOT EXISTS flight_ground_info (
        info_id INT AUTO_INCREMENT PRIMARY KEY,
        flight_id VARCHAR(64) NOT NULL UNIQUE,
        terminal VARCHAR(32),
        gate VARCHAR(32),
        baggage_belt VARCHAR(32),
        checkin_counter VARCHAR(64),
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(flight_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    if (!$mysqli->query($create_sql)) {
        resp(false, ['error'=>'failed_create_table','db'=>$mysqli->error]);
    }
}

// Try update first
$upd = $mysqli->prepare("UPDATE flight_ground_info SET terminal = ?, gate = ?, baggage_belt = ?, updated_at = NOW() WHERE flight_id = ? LIMIT 1");
if ($upd) {
    $upd->bind_param('ssss', $terminal, $gate, $belt, $flight_id);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();
} else {
    $affected = 0;
}

// if update didn't affect row -> insert
if ($affected === 0) {
    $ins = $mysqli->prepare("INSERT INTO flight_ground_info (flight_id, terminal, gate, baggage_belt, updated_at) VALUES (?, ?, ?, ?, NOW())");
    if (!$ins) resp(false, ['error'=>'db_prepare_insert','db'=>$mysqli->error]);
    $ins->bind_param('ssss', $flight_id, $terminal, $gate, $belt);
    if (!$ins->execute()) {
        resp(false, ['error'=>'db_insert_failed','db'=>$ins->error]);
    }
    $ins->close();
}

resp(true, ['msg'=>'ground info updated']);
