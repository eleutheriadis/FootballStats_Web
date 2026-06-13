<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Σύστημα Διαχείρισης Πρωταθλήματος</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand" href="index.php">⚽ Ποδοσφαιρικό Πρωτάθλημα</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="teams.php">Ομάδες</a></li>
                <li class="nav-item"><a class="nav-link" href="players.php">Παίκτες</a></li>
                <li class="nav-item"><a class="nav-link" href="championships.php">Πρωταθλήματα</a></li>
                <li class="nav-item"><a class="nav-link" href="draw.php">Κλήρωση</a></li>
            </ul>
            <?php if (isLoggedIn()): ?>
            <ul class="navbar-nav">
                <li class="nav-item">
                    <span class="navbar-text text-white-50 me-3">
                        <?= e($_SESSION["admin_user"] ?? "admin") ?>
                    </span>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="logout.php">Αποσύνδεση</a>
                </li>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>
<div class="container mt-4">
<?= flashRender() ?>
