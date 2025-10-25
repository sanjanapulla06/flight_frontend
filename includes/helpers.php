<?php
// includes/helpers.php
require_once __DIR__ . '/db.php'; // ensure DB available where used

function safe_start_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Fetch a 3-letter airline code to use in ticket generation.
 * Prefers ICAO (3-letter). Falls back to first 3 chars of airline_name.
 */
function get_airline_three_code($mysqli, $airline_id) {
    $stmt = $mysqli->prepare("SELECT COALESCE(NULLIF(icao_code, ''), airline_name) AS code FROM airline WHERE airline_id = ?");
    $stmt->bind_param('i', $airline_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$res || empty($res['code'])) {
        // fallback
        return 'XXX';
    }
    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $res['code']));
    return substr($code, 0, 3);
}

/**
 * Generate ticket number: ABC-yyMMdd-HexRand
 * Example: UAE-231025-A1B2C3
 */
function generate_ticket_no_from_airline($mysqli, $airline_id) {
    $three = get_airline_three_code($mysqli, $airline_id);
    $rand = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $date = date('ymd'); // yymmdd (matches your generate function)
    return sprintf("%s-%s-%s", $three, $date, $rand);
}

/**
 * Short PNR generator keeping 3-letter airline prefix:
 */
function generate_short_pnr($mysqli, $airline_id) {
    $three = get_airline_three_code($mysqli, $airline_id);
    $rand = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    return strtoupper(substr($three, 0, 3) . $rand);
}
