<?php
// db_test.php - quick check
require_once __DIR__ . '/includes/db.php';

echo "<h3>DB test</h3>";
echo "Host: " . htmlspecialchars($DB_HOST) . "<br>";
echo "User: " . htmlspecialchars($DB_USER) . "<br>";
echo "DB: " . htmlspecialchars($DB_NAME) . "<br>";

if ($mysqli->ping()) {
    echo "<p style='color:green;'>Connected ✅ MySQL is reachable (ping OK).</p>";
} else {
    echo "<p style='color:red;'>Ping failed: " . htmlspecialchars($mysqli->connect_error) . "</p>";
}
?>
