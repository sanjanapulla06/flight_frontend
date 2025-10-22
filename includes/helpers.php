<?php
// includes/helpers.php

function safe_start_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function generate_ticket_no($airline_three) {
    // e.g. 111-231025-A1B2C3
    $rand = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $date = date('ymd');
    return sprintf("%s-%s-%s", strtoupper(substr($airline_three,0,3)), $date, $rand);
}

function generate_short_pnr($airline_three) {
    $rand = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    return strtoupper(substr($airline_three,0,3) . $rand);
}
