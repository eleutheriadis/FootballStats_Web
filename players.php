<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();

$db     = getDb();
$action = $_GET['action'] ?? 'list';
$pid    = $_GET['id']     ?? null;

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $pid) {
    if (!csrfValid()) { flash('danger','Μη έγκυρο token.'); header('Location: players.php'); exit; }
    try {
        $db->collection('players')->document($pid)->delete();
        flash('success', 'Ο παίκτης διαγράφηκε.');
    } catch (\Throwable $e) {
        flash('danger', 'Σφάλμα διαγραφής.');
    }
    header('Location: players.php'); exit;
}

// ── ADD / EDIT POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) { flash('danger','Μη έγκυρο token.'); header('Location: players.php'); exit; }

    $name      = trim($_POST['name']       ?? '');
    $position  = trim($_POST['position']   ?? '');
    $teamId    = trim($_POST['team_id']    ?? '');
    $teamName  = trim($_POST['team_name']  ?? '');
    $jersey    = (int)($_POST['jersey']    ?? 0);
    $isStarter = isset($_POST['is_starter']);
    $editId    = trim($_POST['edit_id']    ?? '');

    $errors = [];
    if ($name === '' || mb_strlen($name) > 100)           $errors[] = 'Το όνομα πρέπει να έχει 1–100 χαρακτήρες.';
    if (!in_array($position, ['GK','DEF','MID','FWD'], true)) $errors[] = 'Μη έγκυρη θέση.';
    if ($teamId === '')                                    $errors[] = 'Πρέπει να επιλέξετε ομάδα.';
    if ($jersey < 1 || $jersey > 99)                      $errors[] = 'Ο αριθμός φανέλας πρέπει να είναι 1–99.';

    $photoPath = null;
    if (!empty($_FILES['photo']['name'])) {
        $up = uploadImage($_FILES['photo'], 'players');
        if (isset($up['error'])) $errors[] = $up['error'];
        else $photoPath = $up['path'];
    }

    if (empty($errors)) {
        try {
            $data = [
                'name'      => $name,
                'position'  => $position,
                'teamId'    => $teamId,
                'teamName'  => $teamName,
                'jersey'    => $jersey,
                'isStarter' => $isStarter,
            ];
            if ($photoPath) $data['photoUrl'] = $photoPath;

            if ($editId) {
                $db->collection('players')->document($editId)->set($data, ['merge' => true]);
                flash('success', 'Ο παίκτης ενημερώθηκε.');
            } else {
                $data['createdAt'] = new \Google\Cloud\Core\Timestamp(new \DateTime());
                $db->collection('players')->add($data);
                flash('success', 'Ο παίκτης προστέθηκε.');
            }
        } catch (\Throwable $e) {
            flash('danger', 'Σφάλμα αποθήκευσης: ' . $e->getMessage());
        }
    } else {
        foreach ($errors as $err) flash('danger', $err);
    }
    header('Location: players.php'); exit;
}

// ── LOAD DATA ─────────────────────────────────────────────────────────────────
$teams = [];
try {
    $snap = $db->collection('teams')->orderBy('name')->documents();
    foreach ($snap as $doc) {
        if ($doc->exists()) $teams[] = ['id' => $doc->id()] + $doc->data();
    }
} catch (\Throwable $e) { flash('danger', 'Σφάλμα φόρτωσης ομάδων.'); }

$players = [];
try {
    $snap = $db->collection('players')->orderBy('teamName')->documents();
    foreach ($snap as $doc) {
        if ($doc->exists()) $players[] = ['id' => $doc->id()] + $doc->data();
    }
} catch (\Throwable $e) { flash('danger', 'Σφάλμα φόρτωσης παικτών.'); }

// Filter by team
$filterTeam = $_GET['team'] ?? '';
if ($filterTeam) {
    $players = array_filter($players, fn($p) => ($p['teamId'] ?? '') === $filterTeam);
}

// Pre-fill if editing
$editPlayer = null;
if ($action === 'edit' && $pid) {
    foreach ($players as $p) {
        if ($p['id'] === $pid) { $editPlayer = $p; break; }
    }
    if (!$editPlayer) {
        // might have been filtered out; re-fetch
        try {
            $doc = $db->collection('players')->document($pid)->snapshot();
            if ($doc->exists()) $editPlayer = ['id' => $doc->id()] + $doc->data();
        } catch (\Throwable $e) {}
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-4">
    <h2 class="mb-0 me-3">Διαχείριση Παικτών</h2>
    <span class="badge bg-secondary fs-6"><?= count($players) ?> παίκτες</span>
</div>

<div class="row">
    <!-- Form -->
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-header fw-bold">
                <?= $editPlayer ? '✏️ Επεξεργασία Παίκτη' : '➕ Νέος Παίκτης' ?>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <?php if ($editPlayer): ?>
                        <input type="hidden" name="edit_id" value="<?= e($editPlayer['id']) ?>">
                    <?php endif; ?>

                    <div class="mb-2">
                        <label class="form-label">Ομάδα</label>
                        <select name="team_id" id="teamSelect" class="form-select" required
                                onchange="updateTeamName(this)">
                            <option value="">— επιλέξτε —</option>
                            <?php foreach ($teams as $t): ?>
                                <option value="<?= e($t['id']) ?>"
                                        data-name="<?= e($t['name']) ?>"
                                    <?= ($editPlayer['teamId'] ?? '') === $t['id'] ? 'selected' : '' ?>>
                                    <?= e($t['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="team_name" id="teamNameInput"
                               value="<?= e($editPlayer['teamName'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Όνομα Παίκτη</label>
                        <input type="text" name="name" class="form-control" maxlength="100" required
                               value="<?= e($editPlayer['name'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Θέση</label>
                        <select name="position" class="form-select" required>
                            <?php foreach (['GK'=>'Τερματοφύλακας','DEF'=>'Αμυντικός','MID'=>'Μέσος','FWD'=>'Επιθετικός'] as $code => $label): ?>
                                <option value="<?= $code ?>"
                                    <?= ($editPlayer['position'] ?? '') === $code ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Αριθμός Φανέλας</label>
                        <input type="number" name="jersey" class="form-control" min="1" max="99" required
                               value="<?= e((string)($editPlayer['jersey'] ?? 1)) ?>">
                    </div>
                    <div class="mb-2 form-check">
                        <input type="checkbox" name="is_starter" class="form-check-input" id="isStarter"
                               <?= !empty($editPlayer['isStarter']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="isStarter">Βασικός παίκτης (starter)</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Φωτογραφία</label>
                        <?php if (!empty($editPlayer['photoUrl'])): ?>
                            <div class="mb-1">
                                <img src="<?= e($editPlayer['photoUrl']) ?>" alt="" class="player-photo">
                            </div>
                        <?php endif; ?>
                        <input type="file" name="photo" class="form-control" accept="image/*">
                        <small class="text-muted">JPG/PNG/GIF/WebP, μέγιστο 2 MB.</small>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" <?= empty($teams) ? 'disabled' : '' ?>>
                            <?= $editPlayer ? 'Αποθήκευση' : 'Προσθήκη' ?>
                        </button>
                        <?php if ($editPlayer): ?>
                            <a href="players.php" class="btn btn-secondary">Ακύρωση</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="col-md-8">
        <!-- Filter -->
        <div class="mb-3 d-flex gap-2 align-items-center">
            <label class="form-label mb-0 me-1">Φίλτρο ομάδας:</label>
            <select class="form-select form-select-sm" style="width:auto"
                    onchange="location='players.php?team='+this.value">
                <option value="">Όλες</option>
                <?php foreach ($teams as $t): ?>
                    <option value="<?= e($t['id']) ?>" <?= $filterTeam === $t['id'] ? 'selected' : '' ?>>
                        <?= e($t['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th style="width:56px">Φωτό</th>
                        <th>#</th>
                        <th>Όνομα</th>
                        <th>Θέση</th>
                        <th>Ομάδα</th>
                        <th>Τύπος</th>
                        <th style="width:100px">Ενέργειες</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($players)): ?>
                        <tr><td colspan="7" class="text-center text-muted">Δεν υπάρχουν παίκτες.</td></tr>
                    <?php else: foreach ($players as $p): ?>
                    <tr>
                        <td>
                            <?php if (!empty($p['photoUrl'])): ?>
                                <img src="<?= e($p['photoUrl']) ?>" alt="" class="player-photo">
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string)($p['jersey'] ?? '')) ?></td>
                        <td class="fw-semibold"><?= e($p['name']) ?></td>
                        <td><?= e(positionLabel($p['position'] ?? '')) ?></td>
                        <td><?= e($p['teamName'] ?? '') ?></td>
                        <td>
                            <?php if (!empty($p['isStarter'])): ?>
                                <span class="badge bg-success">Βασικός</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Αναπληρωματικός</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="players.php?action=edit&id=<?= urlencode($p['id']) ?>"
                               class="btn btn-sm btn-outline-secondary">✏️</a>
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Διαγραφή παίκτη;')">
                                <?= csrfField() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        formaction="players.php?action=delete&id=<?= urlencode($p['id']) ?>">
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

<script>
function updateTeamName(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('teamNameInput').value = opt.dataset.name || '';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
