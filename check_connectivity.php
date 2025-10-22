<?php
// check_connectivity.php
require_once __DIR__ . '/includes/db.php'; // adjust path if needed

// fetch airports
$res = $mysqli->query("SELECT airport_name FROM airport ORDER BY airport_name");
$airports = [];
while ($r = $res->fetch_assoc()) $airports[] = $r['airport_name'];

if (count($airports) < 2) {
    echo "Need at least two airports to check connectivity.\n";
    exit;
}

// prepare statement to test existence quickly
$checkStmt = $mysqli->prepare("SELECT 1 FROM flight WHERE source = ? AND destination = ? LIMIT 1");
if (!$checkStmt) {
    die("Prepare failed: " . $mysqli->error);
}

$missing = [];
foreach ($airports as $src) {
    foreach ($airports as $dst) {
        if ($src === $dst) continue;
        $checkStmt->bind_param('ss', $src, $dst);
        $checkStmt->execute();
        $checkStmt->store_result();
        if ($checkStmt->num_rows === 0) {
            $missing[] = ['src' => $src, 'dst' => $dst];
        }
        $checkStmt->free_result();
    }
}
$checkStmt->close();

// output
if (php_sapi_name() === 'cli') {
    // CLI output
    if (empty($missing)) {
        echo "All airports have direct flights to every other airport 🎉\n";
    } else {
        echo "Missing direct routes (source -> destination):\n";
        foreach ($missing as $m) {
            echo "- {$m['src']}  →  {$m['dst']}\n";
        }
        echo "\nTotal missing: " . count($missing) . "\n";
    }
} else {
    // Browser HTML output
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Connectivity check</title>";
    echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'/></head><body class='p-4'>";
    echo "<div class='container'>";
    if (empty($missing)) {
        echo "<div class='alert alert-success'>All airports have direct flights to every other airport 🎉</div>";
    } else {
        echo "<h4>Missing direct routes ({count})</h4>";
        echo "<table class='table table-striped'><thead><tr><th>Source</th><th>Destination</th></tr></thead><tbody>";
        foreach ($missing as $m) {
            echo "<tr><td>" . htmlspecialchars($m['src']) . "</td><td>" . htmlspecialchars($m['dst']) . "</td></tr>";
        }
        echo "</tbody></table>";
        echo "<div class='text-muted'>Total missing: " . count($missing) . "</div>";
    }
    echo "</div></body></html>";
}
