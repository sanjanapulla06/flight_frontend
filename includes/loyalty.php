<?php
// includes/loyalty.php
function credit_loyalty_points($mysqli, $passport_no, $amount, $source_key = null) {
    // amount = numeric currency (e.g. ticket price)
    $points = (int) floor(floatval($amount) / 100);
    if ($points <= 0) return true;

    $sql = "INSERT INTO loyalty (passport_no, points, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE points = points + VALUES(points), updated_at = NOW()";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        error_log('credit_loyalty_points prepare failed: ' . $mysqli->error);
        return false;
    }
    $stmt->bind_param('si', $passport_no, $points);
    $ok = $stmt->execute();
    $stmt->close();

    // tier update
    $u = $mysqli->prepare("
      UPDATE loyalty
      SET tier = CASE
        WHEN points >= 5000 THEN 'Platinum'
        WHEN points >= 2000 THEN 'Gold'
        WHEN points >= 750 THEN 'Silver'
        ELSE 'Bronze'
      END
      WHERE passport_no = ?
    ");
    if ($u) { $u->bind_param('s', $passport_no); $u->execute(); $u->close(); }

    // optional: insert audit row
    $tx = $mysqli->prepare("INSERT INTO loyalty_tx (passport_no, points_change, reason, source_key) VALUES (?, ?, 'ticket_credit', ?)");
    if ($tx) { $tx->bind_param('sis', $passport_no, $points, $source_key); $tx->execute(); $tx->close(); }

    return $ok;
}
