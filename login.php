<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Already logged in → go to dashboard
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    $expectedUser = $_ENV['ADMIN_USERNAME'] ?? 'admin';
    $expectedPass = $_ENV['ADMIN_PASSWORD'] ?? 'admin';

    if ($user === $expectedUser && $pass === $expectedPass) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user']      = $user;
        flash('success', 'Καλωσήλθατε, ' . $user . '!');
        header('Location: index.php');
        exit;
    } else {
        $error = 'Λάθος όνομα χρήστη ή κωδικός.';
    }
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Σύνδεση — Διαχείριση Πρωταθλήματος</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width:420px;margin-top:100px;">
    <div class="card shadow">
        <div class="card-body p-4">
            <h4 class="card-title text-center mb-4">⚽ Σύνδεση Διαχειριστή</h4>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label">Όνομα χρήστη</label>
                    <input type="text" name="username" class="form-control" required autofocus
                           value="<?= e($_POST['username'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Κωδικός</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Σύνδεση</button>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
