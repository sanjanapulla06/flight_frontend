<?php
include __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/db.php';

// if flight_date not present in schema, show all flights (or modify schema to add flight_date)
$sql = "SELECT f.flight_id, f.source, f.destination, f.d_time, f.a_time, f.status, a.airline_name
        FROM flight f LEFT JOIN airline a ON f.airline_id = a.airline_id
        ORDER BY f.flight_id";
$res = $mysqli->query($sql);
?>
<h2>Flights (Admin)</h2>
<table class="table table-bordered table-sm">
  <thead><tr><th>Flight</th><th>Airline</th><th>Source</th><th>Destination</th><th>Dep</th><th>Arr</th><th>Status</th></tr></thead>
  <tbody>
    <?php while($r = $res->fetch_assoc()): ?>
    <tr>
      <td><?php echo htmlspecialchars($r['flight_id']); ?></td>
      <td><?php echo htmlspecialchars($r['airline_name']); ?></td>
      <td><?php echo htmlspecialchars($r['source']); ?></td>
      <td><?php echo htmlspecialchars($r['destination']); ?></td>
      <td><?php echo htmlspecialchars($r['d_time']); ?></td>
      <td><?php echo htmlspecialchars($r['a_time']); ?></td>
      <td><?php echo htmlspecialchars($r['status']); ?></td>
    </tr>
    <?php endwhile; ?>
  </tbody>
</table>
<?php include __DIR__.'/../includes/footer.php'; ?>
