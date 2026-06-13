<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();

$db     = getDb();
$action = $_GET['action'] ?? 'list';
$teamId = $_GET['id']     ?? null;

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $teamId) {
    if (!csrfValid()) { flash('danger','Μη έγκυρο token.'); header('Location: teams.php'); exit; }
    try {
        $db->collection('teams')->document($teamId)->delete();
        flash('success', 'Η ομάδα διαγράφηκε.');
    } catch (\Throwable $e) {
        flash('danger', 'Σφάλμα διαγραφής: ' . $e->getMessage());
    }
    header('Location: teams.php'); exit;
}

// ── ADD / EDIT POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) { flash('danger','Μη έγκυρο token.'); header('Location: teams.php'); exit; }

    $name    = trim($_POST['name'] ?? '');
    $city    = trim($_POST['city'] ?? '');
    $editId  = trim($_POST['edit_id'] ?? '');

    $errors = [];
    if ($name === '' || mb_strlen($name) > 100) $errors[] = 'Το όνομα πρέπει να έχει 1–100 χαρακτήρες.';
    if ($city === '' || mb_strlen($city) > 100)  $errors[] = 'Η πόλη πρέπει να έχει 1–100 χαρακτήρες.';

    $logoPath = null;
    if (!empty($_FILES['logo']['name'])) {
        $up = uploadImage($_FILES['logo'], 'teams');
        if (isset($up['error'])) $errors[] = $up['error'];
        else $logoPath = $up['path'];
    }

    if (empty($errors)) {
        try {
            $data = ['name' => $name, 'city' => $city];
            if ($logoPath) $data['logoUrl'] = $logoPath;

            if ($editId) {
                $db->collection('teams')->document($editId)->set($data, ['merge' => true]);
                flash('success', 'Η ομάδα ενημερώθηκε.');
            } else {
                $data['createdAt'] = new \Google\Cloud\Core\Timestamp(new \DateTime());
                $db->collection('teams')->add($data);
                flash('success', 'Η ομάδα προστέθηκε.');
            }
        } catch (\Throwable $e) {
            flash('danger', 'Σφάλμα αποθήκευσης: ' . $e->getMessage());
        }
    } else {
        foreach ($errors as $err) flash('danger', $err);
    }
    header('Location: teams.php'); exit;
}

// ── LOAD LIST ─────────────────────────────────────────────────────────────────
$teams = [];
try {
    $snap = $db->collection('teams')->orderBy('name')->documents();
    foreach ($snap as $doc) {
        if ($doc->exists()) {
            $teams[] = ['id' => $doc->id()] + $doc->data();
        }
    }
} catch (\Throwable $e) {
    flash('danger', 'Σφάλμα φόρτωσης ομάδων.');
}

// Pre-fill form if editing
$editTeam = null;
if ($action === 'edit' && $teamId) {
    foreach ($teams as $t) {
        if ($t['id'] === $teamId) { $editTeam = $t; break; }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-4">
    <h2 class="mb-0 me-3">Διαχείριση Ομάδων</h2>
    <span class="badge bg-secondary fs-6"><?= count($teams) ?> ομάδες</span>
</div>

<div class="row">
    <!-- Form -->
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-header fw-bold">
                <?= $editTeam ? '✏️ Επεξεργασία Ομάδας' : '➕ Νέα Ομάδα' ?>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <?php if ($editTeam): ?>
                        <input type="hidden" name="edit_id" value="<?= e($editTeam['id']) ?>">
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label">Όνομα Ομάδας</label>
                        <input type="text" name="name" class="form-control" maxlength="100" required
                               value="<?= e($editTeam['name'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Πόλη</label>
                        <input type="text" name="city" class="form-control" maxlength="100" required
                               value="<?= e($editTeam['city'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Λογότυπο</label>
                        <?php if (!empty($editTeam['logoUrl'])): ?>
                            <div class="mb-1">
                                <img src="<?= e($editTeam['logoUrl']) ?>" alt="" class="team-logo">
                                <small class="text-muted ms-2">Τρέχον λογότυπο</small>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="logo" class="form-control" accept="image/*">
                        <small class="text-muted">JPG/PNG/GIF/WebP, μέγιστο 2 MB.</small>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <?= $editTeam ? 'Αποθήκευση' : 'Προσθήκη' ?>
                        </button>
                        <?php if ($editTeam): ?>
                            <a href="teams.php" class="btn btn-secondary">Ακύρωση</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="col-md-8">
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th style="width:60px">Σήμα</th>
                        <th>Όνομα</th>
                        <th>Πόλη</th>
                        <th style="width:130px">Ενέργειες</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($teams)): ?>
                        <tr><td colspan="4" class="text-center text-muted">Δεν υπάρχουν ακόμα ομάδες.</td></tr>
                    <?php else: foreach ($teams as $t): ?>
                    <tr>
                        <td>
                            <?php if (!empty($t['logoUrl'])): ?>
                                <img src="<?= e($t['logoUrl']) ?>" alt="" class="team-logo">
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="fw-semibold"><?= e($t['name']) ?></td>
                        <td><?= e($t['city'] ?? '') ?></td>
                        <td>
                            <a href="teams.php?action=edit&id=<?= urlencode($t['id']) ?>"
                               class="btn btn-sm btn-outline-secondary">✏️</a>
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Διαγραφή ομάδας;')">
                                <?= csrfField() ?>
                                <input type="hidden" name="_method" value="DELETE">
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        formaction="teams.php?action=delete&id=<?= urlencode($t['id']) ?>">
                                    🗑
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
