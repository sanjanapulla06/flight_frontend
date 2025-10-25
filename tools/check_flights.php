<?php
require_once __DIR__ . '/../includes/db.php';
echo "<h3>Flight table insight</h3>";
$res = $mysqli->query("SELECT COUNT(*) AS c FROM flight");
$r = $res? $res->fetch_assoc() : null;
echo "<p>Total rows in flight: " . ($r['c'] ?? 'N/A') . "</p>";

$res2 = $mysqli->query("SELECT COUNT(*) AS c FROM flight WHERE flight_date BETWEEN '2025-10-25' AND '2025-11-20'");
$r2 = $res2? $res2->fetch_assoc() : null;
echo "<p>Rows with flight_date 2025-10-25 .. 2025-11-20: " . ($r2['c'] ?? 'N/A') . "</p>";

echo "<h4>Sample rows (first 15)</h4><pre>";
$res3 = $mysqli->query("SELECT flight_id, airline_id, source_id, destination_id, flight_date, departure_time, arrival_time, price FROM flight LIMIT 15");
if ($res3) {
  while ($row = $res3->fetch_assoc()) {
    echo htmlspecialchars(json_encode($row)) . PHP_EOL;
  }
} else {
  echo "Query failed: " . htmlspecialchars($mysqli->error);
}
echo "</pre>";
