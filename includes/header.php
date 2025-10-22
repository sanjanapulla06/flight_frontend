<?php
// includes/header.php
// Safe session start + Page title + Active nav highlighting + user info in navbar

if (session_status() === PHP_SESSION_NONE) session_start();

// BASE path (adjust if your project folder differs)
$BASE = '/FLIGHT_FRONTEND';

// allow pages to override the title by setting $PAGE_TITLE before including this file
if (!isset($PAGE_TITLE)) {
    $PAGE_TITLE = 'FlightBook';
}

// get current script name to mark active nav item
$current = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));

// user info from session (safe defaults)
$logged_in = !empty($_SESSION['passport_no']);
$user_name = htmlspecialchars($_SESSION['name'] ?? $_SESSION['user_name'] ?? 'Passenger', ENT_QUOTES);
$user_phone = htmlspecialchars($_SESSION['phone'] ?? '', ENT_QUOTES);

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title><?php echo htmlspecialchars($PAGE_TITLE); ?> — FlightBook</title>

  <link href="<?php echo $BASE; ?>/assets/css/styles.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    /* navbar safety for dropdowns */
    nav.navbar, .navbar .container { overflow: visible !important; z-index: 2000; }
    .dropdown-menu { z-index: 3000 !important; }
    .nav-link.active { font-weight: 600; }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
  <div class="container">
    <a class="navbar-brand fw-bold" href="<?php echo $BASE; ?>/index.php">✈️ FlightBook</a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
            aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item">
          <a class="nav-link <?php echo ($current === 'search.php' || $current === 'index.php') ? 'active' : ''; ?>"
             href="<?php echo $BASE; ?>/search.php">Search</a>
        </li>
      </ul>

      <div class="ms-auto d-flex align-items-center gap-2">
        <?php if ($logged_in): ?>
          <span class="text-light small me-2">
            👋 Hi, <?php echo $user_name; ?><?php echo $user_phone ? " | 📞 {$user_phone}" : ''; ?>
          </span>

          <a class="btn btn-sm btn-light" href="<?php echo $BASE; ?>/my_bookings.php">My Bookings</a>

         <div class="d-flex align-items-center gap-2">
          <a class="btn btn-sm btn-outline-light" href="<?php echo $BASE; ?>/auth/profile.php">Profile</a>
          <a class="btn btn-sm btn-danger" href="<?php echo $BASE; ?>/auth/logout.php">Logout</a>
        </div>

        <?php else: ?>
          <a class="btn btn-sm btn-light" href="<?php echo $BASE; ?>/auth/login.php">Login</a>
          <a class="btn btn-sm btn-outline-light" href="<?php echo $BASE; ?>/auth/register.php">Register</a>
        <?php endif; ?>

        <?php if (!empty($_SESSION['is_admin'])): ?>
          <a class="btn btn-sm btn-warning ms-2" href="<?php echo $BASE; ?>/admin/todays_flights.php">Admin</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>

<!-- Bootstrap JS bundle required for dropdowns and toggles -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- flash message area (pages can use $_SESSION['flash_message'] / flash_error) -->
<div class="container mt-3">
  <?php if (!empty($_SESSION['flash_message'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
  <?php endif; ?>
</div>

<div class="container my-4">
<?php
// note: pages should close the container and include footer.php when done
?>
