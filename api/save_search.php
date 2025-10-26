<?php
// /FLIGHT_FRONTEND/api/save_search.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
safe_start_session();

// Return JSON
header('Content-Type: application/json; charset=utf-8');

$passport_no = $_SESSION['passport_no'] ?? null;
if (!$passport_no) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$source = isset($_POST['source']) ? trim($_POST['source']) : '';
$destination = isset($_POST['destination']) ? trim($_POST['destination']) : '';
$flight_date = isset($_POST['flight_date']) && $_POST['flight_date'] !== '' ? trim($_POST['flight_date']) : null;

if ($source === '' || $destination === '') {
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

// ensure table exists? assume it does; otherwise user runs SQL provided below
$stmt = $mysqli->prepare("INSERT INTO search_history (passport_no, source, destination, flight_date, created_at) VALUES (?, ?, ?, ?, NOW())");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'prepare failed', 'sql_error' => $mysqli->error]);
    exit;
}
$stmt->bind_param('ssss', $passport_no, $source, $destination, $flight_date);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'execute failed', 'sql_error' => $mysqli->error]);
    exit;
}

echo json_encode(['ok' => true]);
