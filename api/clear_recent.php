<?php
// api/clear_recent.php
require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

$uid = intval($_SESSION['user_id']);
$stmt = $mysqli->prepare("DELETE FROM search_history WHERE user_id = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB error']);
    error_log("clear_recent prepare failed: " . $mysqli->error);
    exit;
}
$stmt->bind_param('i', $uid);
$stmt->execute();
$stmt->close();

echo json_encode(['status' => 'ok']);
