<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
safe_start_session();
if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: /FLIGHT_FRONTEND/auth/login.php'); exit; }
require_once __DIR__ . '/../includes/header.php';

echo "<h3>Admin Dashboard</h3>";

// Today's flights
$stmt = $mysqli->prepare("SELECT flight_id, source, destination, d_time FROM flight WHERE flight_date = CURDATE()");
$stmt->execute(); $res = $stmt->get_result();
echo "<h5>Today's Flights</h5><ul>";
while ($r = $res->fetch_assoc()) {
  echo "<li>{$r['flight_id']} : {$r['source']} → {$r['destination']} (Dep {$r['d_time']})</li>";
}
echo "</ul>";

// Bookings per flight
$q = "SELECT f.flight_id, COUNT(t.ticket_no) AS booked, f.tot_seat FROM flight f LEFT JOIN ticket t ON f.flight_id=t.flight_id GROUP BY f.flight_id";
$res2 = $mysqli->query($q);
echo "<h5>Bookings / Flight</h5><table class='table'><tr><th>Flight</th><th>Booked</th><th>Total</th></tr>";
while ($r = $res2->fetch_assoc()) {
  echo "<tr><td>{$r['flight_id']}</td><td>{$r['booked']}</td><td>{$r['tot_seat']}</td></tr>";
}
echo "</table>";

require_once __DIR__ . '/../includes/footer.php';
