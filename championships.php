<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();

$db     = getDb();
$action = $_GET['action'] ?? 'list';
$cid    = $_GET['id']     ?? null;

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $cid) {
    if (!csrfValid()) { flash('danger','Μη έγκυρο token.'); header('Location: championships.php'); exit; }
    try {
        $db->collection('championships')->document($cid)->delete();
        flash('success', 'Το πρωτάθλημα διαγράφηκε.');
    } catch (\Throwable $e) { flash('danger', 'Σφάλμα διαγραφής.'); }
    header('Location: championships.php'); exit;
}

// ── POST: Create championship ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) { flash('danger','Μη έγκυρο token.'); header('Location: championships.php'); exit; }

    $name    = trim($_POST['champ_name'] ?? '');
    $season  = trim($_POST['season']     ?? '');
    $teamIds = array_values(array_unique(array_filter($_POST['team_ids'] ?? [])));

    $errors = [];
    if ($name === '' || mb_strlen($name) > 150) $errors[] = 'Το όνομα πρέπει να έχει 1–150 χαρακτήρες.';
    if ($season === '' || mb_strlen($season) > 20) $errors[] = 'Η σεζόν πρέπει να έχει 1–20 χαρακτήρες (π.χ. 2025-26).';
    if (count($teamIds) < 2)          $errors[] = 'Πρέπει να επιλέξετε τουλάχιστον 2 ομάδες.';
    if (count($teamIds) % 2 !== 0)    $errors[] = 'Ο αριθμός ομάδων πρέπει να είναι ζυγός (για ισόπαλη κατανομή αγώνων).';

    if (empty($errors)) {
        try {
            // Build team list from Firestore
            $selectedTeams = [];
            foreach ($teamIds as $tid) {
                $doc = $db->collection('teams')->document($tid)->snapshot();
                if ($doc->exists()) {
                    $selectedTeams[] = ['id' => $doc->id(), 'name' => $doc->data()['name'] ?? ''];
                }
            }
            if (count($selectedTeams) !== count($teamIds)) {
                flash('danger', 'Μερικές ομάδες δεν βρέθηκαν στη βάση.');
                header('Location: championships.php'); exit;
            }

            $doc = $db->collection('championships')->add([
                'name'      => $name,
                'season'    => $season,
                'status'    => 'PENDING',
                'teams'     => $selectedTeams,   // embedded for quick access
                'teamCount' => count($selectedTeams),
                'createdAt' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            ]);
            flash('success', 'Το πρωτάθλημα δημιουργήθηκε. Μπορείτε τώρα να κάνετε κλήρωση.');
        } catch (\Throwable $e) {
            flash('danger', 'Σφάλμα δημιουργίας: ' . $e->getMessage());
        }
    } else {
        foreach ($errors as $err) flash('danger', $err);
    }
    header('Location: championships.php'); exit;
}

// ── LOAD DATA ─────────────────────────────────────────────────────────────────
$teams = [];
try {
    $snap = $db->collection('teams')->orderBy('name')->documents();
    foreach ($snap as $doc) {
        if ($doc->exists()) $teams[] = ['id' => $doc->id()] + $doc->data();
    }
} catch (\Throwable $e) { flash('danger','Σφάλμα φόρτωσης ομάδων.'); }

$championships = [];
try {
    $snap = $db->collection('championships')->orderBy('createdAt', 'DESCENDING')->documents();
    foreach ($snap as $doc) {
        if ($doc->exists()) $championships[] = ['id' => $doc->id()] + $doc->data();
    }
} catch (\Throwable $e) { flash('danger','Σφάλμα φόρτωσης πρωταθλημάτων.'); }

include __DIR__ . '/includes/header.php';
?>

<h2 class="mb-4">🏆 Πρωταθλήματα</h2>

<!-- Create Form -->
<div class="card shadow-sm mb-5">
    <div class="card-header fw-bold">➕ Δημιουργία Νέου Πρωταθλήματος</div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Όνομα Πρωταθλήματος</label>
                    <input type="text" name="champ_name" class="form-control" maxlength="150"
                           placeholder="π.χ. Super League" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Σεζόν</label>
                    <input type="text" name="season" class="form-control" maxlength="20"
                           placeholder="π.χ. 2025-26" required>
                </div>
            </div>

            <label class="form-label fw-semibold">
                Επιλογή Ομάδων
                <small class="text-muted fw-normal">(ζυγός αριθμός, τουλάχιστον 2)</small>
            </label>
            <div class="border rounded p-3 mb-3" style="max-height:260px;overflow-y:auto;">
                <?php if (empty($teams)): ?>
                    <p class="text-muted mb-0">Δεν υπάρχουν ομάδες — δημιουργήστε πρώτα κάποιες στη σελίδα Ομάδες.</p>
                <?php else: ?>
                    <div class="row">
                    <?php foreach ($teams as $t): ?>
                        <div class="col-sm-6 col-md-4">
                            <div class="form-check">
                                <input class="form-check-input team-check" type="checkbox"
                                       name="team_ids[]" value="<?= e($t['id']) ?>"
                                       id="t-<?= e($t['id']) ?>">
                                <label class="form-check-label" for="t-<?= e($t['id']) ?>">
                                    <?php if (!empty($t['logoUrl'])): ?>
                                        <img src="<?= e($t['logoUrl']) ?>" alt="" class="team-logo me-1">
                                    <?php endif; ?>
                                    <?= e($t['name']) ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <small id="teamCountLabel" class="text-muted mt-2 d-block">
                        Επιλεγμένες: <strong id="teamCountNum">0</strong>
                        <span id="evenWarning" class="text-danger d-none"> — απαιτείται ζυγός αριθμός</span>
                    </small>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-success" <?= empty($teams) ? 'disabled' : '' ?>>
                Δημιουργία Πρωταθλήματος
            </button>
        </form>
    </div>
</div>

<!-- List -->
<h4 class="mb-3">Καταχωρημένα Πρωταθλήματα</h4>
<?php if (empty($championships)): ?>
    <div class="alert alert-info">Δεν υπάρχουν ακόμα πρωταθλήματα.</div>
<?php else: foreach ($championships as $c): ?>
<div class="card mb-3 shadow-sm">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h5 class="mb-1"><?= e($c['name']) ?>
                    <small class="text-muted fw-normal"><?= e($c['season'] ?? '') ?></small>
                </h5>
                <div class="mb-2">
                    <?php
                    $st    = $c['status'] ?? 'PENDING';
                    $badge = match ($st) {
                        'ACTIVE'   => 'bg-success',
                        'FINISHED' => 'bg-secondary',
                        default    => 'bg-warning text-dark',
                    };
                    ?>
                    <span class="badge <?= $badge ?>"><?= e($st) ?></span>
                    <span class="ms-2 text-muted small"><?= (int)($c['teamCount'] ?? 0) ?> ομάδες</span>
                </div>
                <!-- Teams chips -->
                <?php foreach ($c['teams'] ?? [] as $t): ?>
                    <span class="badge bg-light text-dark border me-1"><?= e($t['name']) ?></span>
                <?php endforeach; ?>
            </div>
            <div class="d-flex gap-2">
                <a href="draw.php?id=<?= urlencode($c['id']) ?>" class="btn btn-sm btn-primary">
                    📅 Κλήρωση / Πρόγραμμα
                </a>
                <form method="POST" onsubmit="return confirm('Διαγραφή πρωταθλήματος;')">
                    <?= csrfField() ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger"
                            formaction="championships.php?action=delete&id=<?= urlencode($c['id']) ?>">
                        🗑
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endforeach; endif; ?>

<script>
document.querySelectorAll('.team-check').forEach(cb => cb.addEventListener('change', updateCount));
function updateCount() {
    const n = document.querySelectorAll('.team-check:checked').length;
    document.getElementById('teamCountNum').textContent = n;
    document.getElementById('evenWarning').classList.toggle('d-none', n === 0 || n % 2 === 0);
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
