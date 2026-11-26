<?php
// header.php - Universal Header Logic
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// 1. Get the current filename
$current_file = basename($_SERVER['PHP_SELF']);

// 2. Security Check
if (!isset($_SESSION['loggedin']) && $current_file != 'login.php' && $current_file != 'index.php') {
  header("Location: login.php");
  exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $pageTitle ?? 'SlotSmart Admin'; ?></title>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">

  <link rel="stylesheet" href="assets/css/style.css">

  <style>
    /* Prevent content from hiding behind fixed header */
    body {
      padding-top: 70px;
    }

    /* Admin Style Navbar */
    .navbar-custom {
      background-color: #212529 !important;
      /* Dark Theme */
      border-bottom: 3px solid #0d6efd;
      /* Blue Accent Line */
    }
  </style>
</head>

<body class="<?php echo $bodyClass ?? ''; ?>">

  <?php if ($current_file == 'login.php'): ?>

    <nav class="navbar navbar-dark bg-dark shadow-sm fixed-top">
      <div class="container-fluid px-4">

        <a class="navbar-brand fw-bold" href="admin-forms.php">
          <i class="fa-solid fa-layer-group me-2 text-primary"></i>SlotSmart
        </a>
        <a href="index.php" class="btn btn-outline-light btn-sm d-flex align-items-center gap-2">
          <i class="bi bi-arrow-left-circle-fill"></i> <span>Back to Home</span>
        </a>
      </div>
    </nav>

  <?php else: ?>

    <nav class="navbar navbar-dark bg-dark shadow-sm fixed-top">
      <div class="container-fluid px-4">

        <a class="navbar-brand fw-bold" href="admin-forms.php">
          <i class="fa-solid fa-layer-group me-2 text-primary"></i>SlotSmart
        </a>

        <div class="d-flex align-items-center gap-2">

          <?php if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true): ?>

            <?php if ($current_file != 'admin-forms.php'): ?>
              <a href="admin-forms.php" class="btn btn-outline-light btn-sm d-flex align-items-center gap-2">
                <i class="bi bi-arrow-left-circle-fill"></i>
                <span>Back to Forms</span>
              </a>
            <?php endif; ?>

          <?php endif; ?>

        </div>
      </div>
    </nav>

  <?php endif; ?>