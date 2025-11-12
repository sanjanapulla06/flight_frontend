<?php
// /FLIGHT_FRONTEND/api/cancel_ticket.php
// Cancels a booking + ticket, logs a cancellation row, shows surcharge/refund in JSON.
// Schema-aware (won’t reference columns that don’t exist). No refunds table usage.

ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
safe_start_session();

// --- tiny logger ---
function dbg($m){
  @file_put_contents(__DIR__ . '/../tools/cancel_ticket_debug.log',
    '['.date('Y-m-d H:i:s')."] $m\n", FILE_APPEND);
}
function dbg_exc(Throwable $e){ dbg('EXCEPTION: '.$e->getMessage()."\n".$e->getTraceAsString()); }

// --- helpers ---
function table_exists(mysqli $db, string $name): bool {
  $q = $db->query("SHOW TABLES LIKE '".$db->real_escape_string($name)."'");
  return $q && $q->num_rows > 0;
}
function get_cols(mysqli $db, string $table): array {
  $cols = [];
  $res = $db->query("SHOW COLUMNS FROM `".$db->real_escape_string($table)."`");
  if ($res) while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
  return $cols;
}
function pick_first_ci(array $haystack, array $candidates): ?string {
  foreach ($candidates as $cand) {
    foreach ($haystack as $h) if (strcasecmp($h,$cand)===0) return $h;
  }
  return null;
}

try {
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit;
  }

  $passport = $_SESSION['passport_no'] ?? '';
  $is_admin = !empty($_SESSION['is_admin']);
  if ($passport === '') {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Not authenticated']); exit;
  }

  $booking_id = trim($_POST['booking_id'] ?? '');
  $reason     = trim($_POST['reason'] ?? '');
  if ($booking_id === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Missing booking_id']); exit;
  }

  dbg("cancel_ticket called booking_id={$booking_id} by passport={$passport}");

  // --- load booking ---
  $bst = $mysqli->prepare("SELECT booking_id, flight_id, passport_no, status FROM bookings WHERE booking_id=? LIMIT 1");
  $bst->bind_param('s',$booking_id);
  $bst->execute();
  $booking = $bst->get_result()->fetch_assoc();
  $bst->close();

  if (!$booking) { echo json_encode(['ok'=>false,'error'=>'Booking not found']); exit; }
  if ($booking['passport_no'] !== $passport && !$is_admin) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
  }
  if (in_array(strtolower($booking['status']), ['cancelled','canceled'])) {
    echo json_encode(['ok'=>false,'error'=>'Already cancelled']); exit;
  }

  // --- resolve price ---
  $price = 0.0;
  // prefer ticket.price by booking_id if those cols exist
  if (table_exists($mysqli,'ticket')) {
    $tcols = get_cols($mysqli,'ticket');
    dbg("Ticket table detected: ticket (cols: ".implode(',',$tcols).")");
    $t_booking = pick_first_ci($tcols, ['booking_id','booking','reservation_id']);
    $t_price   = pick_first_ci($tcols, ['price','fare','amount','cost','ticket_price']);
    if ($t_booking && $t_price) {
      $ps = $mysqli->prepare("SELECT `$t_price` AS p FROM `ticket` WHERE `$t_booking`=? LIMIT 1");
      $ps->bind_param('s',$booking_id);
      $ps->execute(); $prow = $ps->get_result()->fetch_assoc(); $ps->close();
      if ($prow && isset($prow['p'])) $price = (float)$prow['p'];
    } else {
      dbg("Ticket table present but missing booking FK or price column");
    }
  }

  // fallback to flight.price if still zero
  if ($price <= 0 && table_exists($mysqli,'flight')) {
    $fcols = get_cols($mysqli,'flight');
    $f_price = pick_first_ci($fcols, ['price','base_price','fare']);
    $f_id    = pick_first_ci($fcols, ['flight_id','id']);
    if ($f_price && $f_id) {
      $fs = $mysqli->prepare("SELECT `$f_price` AS p FROM `flight` WHERE `$f_id`=? LIMIT 1");
      $fs->bind_param('s',$booking['flight_id']); $fs->execute();
      $fr = $fs->get_result()->fetch_assoc(); $fs->close();
      if ($fr && isset($fr['p'])) $price = (float)$fr['p'];
    }
  }

  dbg("Resolved price for {$booking_id}: ".number_format($price,2));

  // --- surcharge/refund (display only) ---
  $surcharge = 250.00;
  if ($surcharge > $price) $surcharge = $price;
  $refund = max(0, round($price - $surcharge, 2));
  dbg("surcharge={$surcharge} refund={$refund}");

  // --- transaction ---
  $mysqli->begin_transaction();

  // log cancellation (safe table)
  $mysqli->query("CREATE TABLE IF NOT EXISTS cancellation_tx(
      id INT AUTO_INCREMENT PRIMARY KEY,
      booking_id VARCHAR(128),
      passport_no VARCHAR(64),
      flight_id VARCHAR(128),
      reason TEXT,
      surcharge DECIMAL(10,2),
      refund DECIMAL(10,2),
      cancelled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      processed_by VARCHAR(64)
  ) ENGINE=InnoDB");

  $procBy = $_SESSION['name'] ?? $passport;
  $c = $mysqli->prepare("INSERT INTO cancellation_tx
        (booking_id, passport_no, flight_id, reason, surcharge, refund, processed_by)
        VALUES (?,?,?,?,?,?,?)");
  $c->bind_param('ssssdds', $booking_id, $passport, $booking['flight_id'], $reason, $surcharge, $refund, $procBy);
  $c->execute(); $c->close();

  // --- bookings update (schema-aware) ---
  $bcols = get_cols($mysqli,'bookings');
  $sets = [];
  if (in_array('status',$bcols))       $sets[] = "status='cancelled'";
  if (in_array('cancelled_at',$bcols)) $sets[] = "cancelled_at=NOW()";
  if (in_array('cancelled_by',$bcols)) $sets[] = "cancelled_by='".$mysqli->real_escape_string($procBy)."'";
  if (empty($sets)) $sets[] = "status='cancelled'"; // minimal fallback

  $sqlu = "UPDATE bookings SET ".implode(', ',$sets)." WHERE booking_id=? LIMIT 1";
  $up = $mysqli->prepare($sqlu);
  $up->bind_param('s',$booking_id);
  $up->execute(); $up->close();

  // --- ticket update (best-effort)
  if (table_exists($mysqli,'ticket')) {
    $tcols = get_cols($mysqli,'ticket');
    $tsets = [];
    if (in_array('status',$tcols))       $tsets[] = "status='cancelled'";
    if (in_array('cancelled_at',$tcols)) $tsets[] = "cancelled_at=NOW()";

    if (!empty($tsets)) {
      $byBooking = pick_first_ci($tcols, ['booking_id','booking','reservation_id']);
      if ($byBooking) {
        $qt = $mysqli->prepare("UPDATE `ticket` SET ".implode(', ',$tsets)." WHERE `$byBooking`=?");
        $qt->bind_param('s',$booking_id);
        $qt->execute(); $qt->close();
      } elseif (in_array('passport_no',$tcols) && in_array('flight_id',$tcols)) {
        $qt = $mysqli->prepare("UPDATE `ticket` SET ".implode(', ',$tsets)." WHERE passport_no=? AND flight_id=?");
        $qt->bind_param('ss',$passport,$booking['flight_id']);
        $qt->execute(); $qt->close();
      } else {
        dbg("Ticket table present but no matching keys to update (skipped).");
      }
    } else {
      dbg("Ticket table has no status/cancelled_at cols (skipped).");
    }
  }

  $mysqli->commit();

  echo json_encode([
    'ok'          => true,
    'message'     => 'Ticket cancelled successfully',
    'surcharge'   => number_format($surcharge,2,'.',''),
    'refund'      => number_format($refund,2,'.',''),
    'cancelled_at'=> date('Y-m-d H:i:s')
  ]);
  exit;

} catch (Throwable $e) {
  dbg_exc($e);
  try { if ($mysqli && $mysqli->in_transaction()) $mysqli->rollback(); } catch(Throwable $ee){}
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Internal server error']); exit;
}
