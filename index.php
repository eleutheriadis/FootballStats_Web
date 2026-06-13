<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();

function countCollection(string $col): int {
    try {
        $docs = getDb()->collection($col)->documents();
        $count = 0;
        foreach ($docs as $doc) {
            if ($doc->exists()) $count++;
        }
        return $count;
    } catch (\Throwable $e) {
        return 0;
    }
}

$teamCount   = countCollection('teams');
$playerCount = countCollection('players');
$champCount  = countCollection('championships');
$matchCount  = countCollection('matches');

// Recent matches (last 5, ordered by gameweek)
$recentMatches = [];
try {
    $snap = $db->collection('matches')->orderBy('gameweek')->limit(5)->documents();
    foreach ($snap as $doc) {
        if ($doc->exists()) $recentMatches[] = $doc->data();
    }
} catch (\Throwable $e) {}

include __DIR__ . '/includes/header.php';
?>

<h2 class="mb-4">📊 Πίνακας Ελέγχου</h2>

<div class="row g-4 mb-5">
    <div class="col-md-3">
        <div class="card text-white bg-primary shadow">
            <div class="card-body text-center">
                <div class="display-4 fw-bold"><?= $teamCount ?></div>
                <div class="mt-1">Ομάδες</div>
            </div>
            <div class="card-footer text-center">
                <a href="teams.php" class="text-white small">Διαχείριση →</a>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-success shadow">
            <div class="card-body text-center">
                <div class="display-4 fw-bold"><?= $playerCount ?></div>
                <div class="mt-1">Παίκτες</div>
            </div>
            <div class="card-footer text-center">
                <a href="players.php" class="text-white small">Διαχείριση →</a>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-warning shadow">
            <div class="card-body text-center">
                <div class="display-4 fw-bold"><?= $champCount ?></div>
                <div class="mt-1">Πρωταθλήματα</div>
            </div>
            <div class="card-footer text-center">
                <a href="championships.php" class="text-white small">Διαχείριση →</a>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-danger shadow">
            <div class="card-body text-center">
                <div class="display-4 fw-bold"><?= $matchCount ?></div>
                <div class="mt-1">Αγώνες</div>
            </div>
            <div class="card-footer text-center">
                <a href="draw.php" class="text-white small">Κλήρωση →</a>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($recentMatches)): ?>
<h4 class="mb-3">Πρόσφατοι Αγώνες</h4>
<div class="table-responsive">
    <table class="table table-striped table-hover align-middle">
        <thead class="table-dark">
            <tr>
                <th>Αγωνιστική</th>
                <th>Εντός έδρας</th>
                <th>Σκορ</th>
                <th>Εκτός έδρας</th>
                <th>Κατάσταση</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentMatches as $m): ?>
            <tr>
                <td><?= e((string)($m['gameweek'] ?? '')) ?></td>
                <td class="fw-bold"><?= e($m['homeTeamName'] ?? '') ?></td>
                <td class="text-center fw-bold">
                    <?= e((string)($m['homeScore'] ?? 0)) ?> – <?= e((string)($m['awayScore'] ?? 0)) ?>
                </td>
                <td class="fw-bold"><?= e($m['awayTeamName'] ?? '') ?></td>
                <td>
                    <?php
                    $st    = $m['status'] ?? 'SCHEDULED';
                    $badge = match ($st) {
                        'LIVE'     => 'bg-success',
                        'FINISHED' => 'bg-secondary',
                        default    => 'bg-primary',
                    };
                    ?>
                    <span class="badge <?= $badge ?>"><?= e($st) ?></span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
