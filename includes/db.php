

<?php
// includes/db.php
$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = 'Sansuj123@u'; // <-- set your MySQL password here
$DB_NAME = 'airport';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    http_response_code(500);
    die("DB connect error: " . $mysqli->connect_error);
}
$mysqli->set_charset("utf8mb4");
