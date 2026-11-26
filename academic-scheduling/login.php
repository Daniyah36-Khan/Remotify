<?php
// login.php - FINAL DESIGN MATCHING SCREENSHOT
session_start();
require 'db.php';

// This includes the Navbar (Logo + Back to Home button)
include 'header.php';

// --- 1. SESSION CHECK ---
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
  if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header('Location: admin-forms.php');
    exit;
  } else {
    // Reset session if it's not an admin
    session_unset();
    session_destroy();
    session_start();
  }
}

$login_error = '';

// --- 2. LOGIN LOGIC ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $email = trim($_POST['email']);
  $password = trim($_POST['password']);

  // Check DB for the user
  $stmt = $pdo->prepare("SELECT user_id, email, password, is_demo FROM users WHERE email = ?");
  $stmt->execute([$email]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  // Verify Password
  if ($user && password_verify($password, $user['password'])) {

    $_SESSION['loggedin'] = true;
    $_SESSION['user_id']  = $user['user_id'];
    $_SESSION['email']    = $user['email'];
    $_SESSION['role']     = 'admin';
    $_SESSION['is_demo']  = (int)($user['is_demo'] ?? 0);

    header('Location: admin-forms.php');
    exit;

  } else {
    $login_error = 'Invalid email or password.';
  }
}
?>

<div class="container login-container">
  <div class="row justify-content-center">
    <div class="card col-12 col-md-8 col-lg-6 col-xl-5">
      <div class="card-body">

        <div class="mb-4">
          <h3 class="fw-bold text-dark">
            <i class="fa-solid fa-user-shield me-2"></i>Admin Login
          </h3>
        </div>

        <?php if ($login_error): ?>
          <div class="alert alert-danger d-flex align-items-center" role="alert">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>
            <div><?php echo $login_error; ?></div>
          </div>
        <?php endif; ?>

        <form method="POST" action="login.php">

          <div class="mb-3">
            <label class="form-label text-muted small fw-bold text-uppercase">Email Address</label>
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0 text-muted">
                <i class="fa-regular fa-envelope"></i>
              </span>
              <input type="email" name="email" class="form-control border-start-0 ps-0 bg-light"
                placeholder="Enter your email" required style="background-color: #f8f9fa;">
            </div>
          </div>

          <div class="mb-4">
            <label class="form-label text-muted small fw-bold text-uppercase">Password</label>
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0 text-muted">
                <i class="fa-solid fa-lock"></i>
              </span>
              <input type="password" name="password" class="form-control border-start-0 ps-0 bg-light" required
                style="background-color: #f8f9fa;">
            </div>
          </div>

          <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary btn-lg fw-bold">
              Secure Login <i class="fa-solid fa-arrow-right ms-2"></i>
            </button>
          </div>

        </form>

        <div class="text-center mt-4">
          <a href="logout.php" class="text-decoration-none text-secondary small">
            <i class="fa-solid fa-rotate-left me-1"></i> Force Logout (Reset Session)
          </a>
        </div>

      </div>
    </div>
  </div>
</div>
</body>

</html>