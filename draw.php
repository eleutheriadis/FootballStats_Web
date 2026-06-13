<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();

$db      = getDb();
$champId = $_GET['id'] ?? null;

// ── Helpers για δημιουργία ενδεκάδων ──────────────────────────────────────────

/**
 * Διαβάζει όλους τους παίκτες από το /players και τους ομαδοποιεί ανά teamId.
 * Επιστρέφει: [teamId => [ ['id'=>..,'name'=>..,'position'=>..,'jersey'=>..], ... ]]
 */
function loadPlayersByTeam(\Google\Cloud\Firestore\FirestoreClient $db): array {
    $byTeam = [];
    foreach ($db->collection('players')->documents() as $pdoc) {
        if (!$pdoc->exists()) continue;
        $p   = $pdoc->data();
        $tid = $p['teamId'] ?? null;
        if (!$tid) continue;
        $byTeam[$tid][] = [
            'id'       => $pdoc->id(),
            'name'     => (string)($p['name'] ?? ''),
            'position' => (string)($p['position'] ?? 'MID'),
            // Στα players.php το πεδίο λέγεται "jersey" — υποστηρίζουμε και τα δύο
            'jersey'   => (int)($p['jersey'] ?? $p['jerseyNumber'] ?? 0),
        ];
    }
    return $byTeam;
}

/**
 * Κατασκευάζει default ενδεκάδα 4-4-2 (1 GK + 4 DEF + 4 MID + 2 FWD) + bench 7.
 * Επιστρέφει array από LineupPlayer maps έτοιμα για Firestore.
 */
function buildDefaultLineup(array $players): array {
    // Ομαδοποίηση ανά θέση
    $byPos = ['GK' => [], 'DEF' => [], 'MID' => [], 'FWD' => []];
    foreach ($players as $p) {
        $pos = $p['position'] ?: 'MID';
        if (!isset($byPos[$pos])) $byPos[$pos] = [];
        $byPos[$pos][] = $p;
    }
    // Ταξινόμηση κάθε ομάδας θέσης κατά αριθμό φανέλας
    foreach ($byPos as &$list) {
        usort($list, function ($a, $b) { return $a['jersey'] <=> $b['jersey']; });
    }
    unset($list);

    // 4-4-2 starters
    $starters  = [];
    $formation = ['GK' => 1, 'DEF' => 4, 'MID' => 4, 'FWD' => 2];
    foreach ($formation as $pos => $count) {
        for ($i = 0; $i < $count && !empty($byPos[$pos]); $i++) {
            $starters[] = array_shift($byPos[$pos]);
        }
    }

    // Συμπλήρωση αν δεν φτάσαμε 11 (π.χ. δεν υπάρχουν αρκετοί DEF)
    $leftover = array_merge($byPos['GK'], $byPos['DEF'], $byPos['MID'], $byPos['FWD']);
    while (count($starters) < 11 && !empty($leftover)) {
        $starters[] = array_shift($leftover);
    }

    // Πάγκος: μέχρι 7 παίκτες
    $bench = array_slice($leftover, 0, 7);

    // Μετατροπή σε Firestore maps με τα ονόματα πεδίων που περιμένει το Android
    $out = [];
    foreach ($starters as $p) $out[] = playerToLineupMap($p, true);
    foreach ($bench    as $p) $out[] = playerToLineupMap($p, false);
    return $out;
}

function playerToLineupMap(array $p, bool $isStarter): array {
    return [
        'playerId'     => $p['id'],
        'playerName'   => $p['name'],
        'position'     => $p['position'],
        'jerseyNumber' => $p['jersey'],
        'isStarter'    => $isStarter,
        'isActive'     => true,
    ];
}

/**
 * Διαγράφει όλα τα lineup documents ενός αγώνα. Καλείται πριν να ξαναγραφούν.
 */
function deleteLineupsForMatch(
    \Google\Cloud\Firestore\FirestoreClient $db,
    string $matchId,
    \Google\Cloud\Firestore\BulkWriter $writer
): void {
    $lineups = $db->collection('matches')->document($matchId)->collection('lineups')->documents();
    foreach ($lineups as $ldoc) {
        if ($ldoc->exists()) $writer->delete($ldoc->reference());
    }
}

// ── No championship selected → show picker ────────────────────────────────────
if (!$champId) {
    $championships = [];
    try {
        $snap = $db->collection('championships')->orderBy('createdAt', 'DESCENDING')->documents();
        foreach ($snap as $doc) {
            if ($doc->exists()) $championships[] = ['id' => $doc->id()] + $doc->data();
        }
    } catch (\Throwable $e) {}

    include __DIR__ . '/includes/header.php';
    ?>
    <h2 class="mb-4">📅 Κλήρωση Πρωταθλήματος</h2>
    <?php if (empty($championships)): ?>
        <div class="alert alert-info">
            Δεν υπάρχουν πρωταθλήματα. <a href="championships.php">Δημιουργήστε ένα πρώτα.</a>
        </div>
    <?php else: ?>
        <p class="text-muted">Επιλέξτε πρωτάθλημα:</p>
        <div class="list-group" style="max-width:600px">
        <?php foreach ($championships as $c): ?>
            <a href="draw.php?id=<?= urlencode($c['id']) ?>"
               class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                <span>
                    <strong><?= e($c['name']) ?></strong>
                    <small class="text-muted ms-2"><?= e($c['season'] ?? '') ?></small>
                </span>
                <span class="badge bg-secondary"><?= (int)($c['teamCount'] ?? 0) ?> ομάδες</span>
            </a>
        <?php endforeach; ?>
        </div>
    <?php endif;
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ── Load championship ─────────────────────────────────────────────────────────
$champ = null;
try {
    $doc = $db->collection('championships')->document($champId)->snapshot();
    if ($doc->exists()) $champ = ['id' => $doc->id()] + $doc->data();
} catch (\Throwable $e) {}

if (!$champ) {
    flash('danger', 'Το πρωτάθλημα δεν βρέθηκε.');
    header('Location: championships.php'); exit;
}

// ── POST: Generate draw ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    if (!csrfValid()) { flash('danger','Μη έγκυρο token.'); header("Location: draw.php?id=$champId"); exit; }

    $teams = $champ['teams'] ?? [];
    $n     = count($teams);

    if ($n < 2 || $n % 2 !== 0) {
        flash('danger', 'Απαιτείται ζυγός αριθμός ομάδων (τουλάχιστον 2).');
        header("Location: draw.php?id=$champId"); exit;
    }

    // Round-Robin circle method
    $fixed    = $teams[0];
    $rotating = array_slice($teams, 1);
    $rounds   = $n - 1;
    $fixtures = [];

    for ($gw = 1; $gw <= $rounds; $gw++) {
        // Fixed team pair
        if ($gw % 2 === 1) {
            $fixtures[] = ['gameweek' => $gw, 'home' => $fixed, 'away' => $rotating[0]];
        } else {
            $fixtures[] = ['gameweek' => $gw, 'home' => $rotating[0], 'away' => $fixed];
        }

        // Other N/2-1 pairs
        for ($i = 1; $i <= intdiv($n, 2) - 1; $i++) {
            $home = $rotating[$i];
            $away = $rotating[$n - 1 - $i];
            if ($gw % 2 === 0) [$home, $away] = [$away, $home];
            $fixtures[] = ['gameweek' => $gw, 'home' => $home, 'away' => $away];
        }

        // Rotate: last → front
        array_unshift($rotating, array_pop($rotating));
    }

    // Return leg (swap home/away, offset gameweek by $rounds)
    $returnFixtures = [];
    foreach ($fixtures as $f) {
        $returnFixtures[] = ['gameweek' => $f['gameweek'] + $rounds, 'home' => $f['away'], 'away' => $f['home']];
    }
    $fixtures = array_merge($fixtures, $returnFixtures);

    try {
        // Προφόρτωση παικτών ανά ομάδα + υπολογισμός default ενδεκάδας
        $playersByTeam = loadPlayersByTeam($db);
        $lineupByTeam  = [];
        foreach ($champ['teams'] as $t) {
            $lineupByTeam[$t['id']] = buildDefaultLineup($playersByTeam[$t['id']] ?? []);
        }

        // Delete existing matches (και τα lineups τους) για αυτό το πρωτάθλημα.
        // Σημείωση: σε αυτή την έκδοση του google/cloud-firestore το παλιό
        // $db->batch() δεν υπάρχει — χρησιμοποιούμε bulkWriter() που
        // αυτόματα χωρίζει τις εγγραφές σε batches των 500.
        $existing = $db->collection('matches')->where('championshipId', '=', $champId)->documents();
        $writer = $db->bulkWriter();
        foreach ($existing as $old) {
            if ($old->exists()) {
                deleteLineupsForMatch($db, $old->id(), $writer);
                $writer->delete($old->reference());
            }
        }
        $writer->close();

        // Insert new matches + lineups
        $writer = $db->bulkWriter();
        foreach ($fixtures as $f) {
            $ref = $db->collection('matches')->newDocument();
            $writer->set($ref, [
                'championshipId'   => $champId,
                'championshipName' => $champ['name'],
                'season'           => $champ['season'] ?? '',
                'gameweek'         => $f['gameweek'],
                'homeTeamId'       => $f['home']['id'],
                'homeTeamName'     => $f['home']['name'],
                'awayTeamId'       => $f['away']['id'],
                'awayTeamName'     => $f['away']['name'],
                'homeScore'        => 0,
                'awayScore'        => 0,
                'status'           => 'SCHEDULED',
                'venue'            => '',
                'createdAt'        => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            ]);

            // Default ενδεκάδες για home + away
            $writer->set(
                $ref->collection('lineups')->document($f['home']['id']),
                ['players' => $lineupByTeam[$f['home']['id']] ?? []]
            );
            $writer->set(
                $ref->collection('lineups')->document($f['away']['id']),
                ['players' => $lineupByTeam[$f['away']['id']] ?? []]
            );
        }
        $writer->close();

        // Update championship
        $db->collection('championships')->document($champId)->set(
            ['status' => 'ACTIVE', 'matchCount' => count($fixtures)],
            ['merge' => true]
        );

        flash('success', 'Κλήρωση ολοκληρώθηκε! ' . count($fixtures) . ' αγώνες σε ' . ($rounds * 2) . ' αγωνιστικές.');
    } catch (\Throwable $e) {
        flash('danger', 'Σφάλμα αποθήκευσης: ' . $e->getMessage());
    }
    header("Location: draw.php?id=$champId"); exit;
}

// ── POST: Δημιουργία ενδεκάδων μόνο (χωρίς re-draw) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_lineups') {
    if (!csrfValid()) { flash('danger','Μη έγκυρο token.'); header("Location: draw.php?id=$champId"); exit; }

    try {
        // 1) Βρες όλους τους αγώνες του πρωταθλήματος
        $matchDocs = [];
        foreach ($db->collection('matches')->where('championshipId', '=', $champId)->documents() as $mdoc) {
            if ($mdoc->exists()) $matchDocs[] = $mdoc;
        }
        if (empty($matchDocs)) {
            flash('warning', 'Δεν βρέθηκαν αγώνες. Κάνε πρώτα την κλήρωση.');
            header("Location: draw.php?id=$champId"); exit;
        }

        // 2) Προφόρτωση παικτών ανά ομάδα + υπολογισμός ενδεκάδων
        $playersByTeam = loadPlayersByTeam($db);
        $lineupByTeam  = [];
        foreach ($champ['teams'] as $t) {
            $lineupByTeam[$t['id']] = buildDefaultLineup($playersByTeam[$t['id']] ?? []);
        }

        // 3) Γράψε lineup documents για κάθε αγώνα
        $writer = $db->bulkWriter();
        $created = 0;
        foreach ($matchDocs as $mdoc) {
            $m       = $mdoc->data();
            $homeTid = $m['homeTeamId'] ?? null;
            $awayTid = $m['awayTeamId'] ?? null;
            if (!$homeTid || !$awayTid) continue;

            $writer->set(
                $mdoc->reference()->collection('lineups')->document($homeTid),
                ['players' => $lineupByTeam[$homeTid] ?? []]
            );
            $writer->set(
                $mdoc->reference()->collection('lineups')->document($awayTid),
                ['players' => $lineupByTeam[$awayTid] ?? []]
            );
            $created += 2;
        }
        $writer->close();

        flash('success', "Δημιουργήθηκαν $created lineup documents για " . count($matchDocs) . " αγώνες.");
    } catch (\Throwable $e) {
        flash('danger', 'Σφάλμα δημιουργίας ενδεκάδων: ' . $e->getMessage());
    }
    header("Location: draw.php?id=$champId"); exit;
}

// ── POST: Update match score/status ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_match') {
    if (!csrfValid()) { flash('danger','Μη έγκυρο token.'); header("Location: draw.php?id=$champId"); exit; }

    $matchId   = trim($_POST['match_id']  ?? '');
    $homeScore = max(0, (int)($_POST['home_score'] ?? 0));
    $awayScore = max(0, (int)($_POST['away_score'] ?? 0));
    $status    = $_POST['status'] ?? 'SCHEDULED';
    if (!in_array($status, ['SCHEDULED','LIVE','FINISHED'], true)) $status = 'SCHEDULED';

    try {
        $updateData = [
            'homeScore' => $homeScore,
            'awayScore' => $awayScore,
            'status'    => $status,
        ];

        // Λογική για liveStartTime:
        //   • Όταν περνάμε σε LIVE από άλλη κατάσταση → αποθήκευσε kickoff
        //     timestamp για να ξεκινήσει ο μετρητής στις mobile συσκευές.
        //   • Όταν περνάμε σε SCHEDULED (revert) → καθάρισέ το, ώστε
        //     μελλοντικό LIVE να ξαναξεκινήσει από το 0'.
        //   • Στο FINISHED δεν πειράζουμε τίποτα — το παλιό timestamp παραμένει
        //     για να μπορεί κανείς να δει «πότε ξεκίνησε».
        $existing = $db->collection('matches')->document($matchId)->snapshot();
        $prevStatus = $existing->exists() ? $existing->get('status') : null;

        if ($status === 'LIVE' && $prevStatus !== 'LIVE') {
            $updateData['liveStartTime'] = new \Google\Cloud\Core\Timestamp(new \DateTime());
        } elseif ($status === 'SCHEDULED') {
            $updateData['liveStartTime'] = null;
        }

        $db->collection('matches')->document($matchId)->set($updateData, ['merge' => true]);
        flash('success', 'Ο αγώνας ενημερώθηκε.');
    } catch (\Throwable $e) {
        flash('danger', 'Σφάλμα ενημέρωσης: ' . $e->getMessage());
    }
    header("Location: draw.php?id=$champId"); exit;
}

// ── Load matches ──────────────────────────────────────────────────────────────
// Σημείωση: δεν χρησιμοποιούμε orderBy στο Firestore γιατί ο συνδυασμός
// where(championshipId) + orderBy(gameweek) απαιτεί composite index.
// Φορτώνουμε όλους τους αγώνες του πρωταθλήματος και ταξινομούμε client-side.
$matches = [];
try {
    $snap = $db->collection('matches')
               ->where('championshipId', '=', $champId)
               ->documents();
    foreach ($snap as $doc) {
        if ($doc->exists()) $matches[] = ['id' => $doc->id()] + $doc->data();
    }
    usort($matches, function ($a, $b) {
        return ((int)($a['gameweek'] ?? 0)) <=> ((int)($b['gameweek'] ?? 0));
    });
} catch (\Throwable $e) {
    flash('danger', 'Σφάλμα φόρτωσης αγώνων: ' . $e->getMessage());
}

$byGameweek = [];
foreach ($matches as $m) {
    $byGameweek[$m['gameweek']][] = $m;
}
ksort($byGameweek);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3">
    <a href="championships.php" class="btn btn-sm btn-outline-secondary me-3">← Πίσω</a>
    <h2 class="mb-0">
        📅 <?= e($champ['name']) ?>
        <small class="text-muted fs-5"><?= e($champ['season'] ?? '') ?></small>
    </h2>
</div>

<div class="mb-3">
    <?php
    $st    = $champ['status'] ?? 'PENDING';
    $badge = match ($st) { 'ACTIVE' => 'bg-success', 'FINISHED' => 'bg-secondary', default => 'bg-warning text-dark' };
    ?>
    <span class="badge <?= $badge ?>"><?= e($st) ?></span>
    <span class="text-muted ms-2"><?= (int)($champ['teamCount'] ?? 0) ?> ομάδες</span>
    <?php if (!empty($matches)): ?>
        · <span class="text-muted"><?= count($matches) ?> αγώνες σε <?= count($byGameweek) ?> αγωνιστικές</span>
    <?php endif; ?>
</div>

<div class="mb-3">
    <strong>Ομάδες:</strong>
    <?php foreach ($champ['teams'] ?? [] as $t): ?>
        <span class="badge bg-light text-dark border ms-1"><?= e($t['name']) ?></span>
    <?php endforeach; ?>
</div>

<!-- Generate -->
<div class="card shadow-sm mb-4">
    <div class="card-body d-flex align-items-center gap-3 flex-wrap">
        <div>
            <strong>Αυτόματη Κλήρωση (Round-Robin, διπλός γύρος)</strong><br>
            <small class="text-muted">
                <?= max(0, (count($champ['teams'] ?? []) - 1) * 2) ?> αγωνιστικές ·
                <?= max(0, count($champ['teams'] ?? []) * (count($champ['teams'] ?? []) - 1)) ?> αγώνες συνολικά
                · <em>Δημιουργεί ταυτόχρονα και τις προεπιλεγμένες ενδεκάδες (4-4-2)</em>
                <?php if (!empty($matches)): ?>
                    · <span class="text-danger">Το υπάρχον πρόγραμμα θα αντικατασταθεί.</span>
                <?php endif; ?>
            </small>
        </div>
        <div class="ms-auto d-flex gap-2 flex-wrap">
            <?php if (!empty($matches)): ?>
            <form method="POST"
                  onsubmit="return confirm('Δημιουργία/αντικατάσταση ενδεκάδων για όλους τους αγώνες;')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="generate_lineups">
                <button type="submit" class="btn btn-outline-primary">
                    👥 Δημιουργία Ενδεκάδων
                </button>
            </form>
            <?php endif; ?>
            <form method="POST"
                  onsubmit="return confirm('<?= empty($matches) ? 'Δημιουργία κλήρωσης + ενδεκάδων;' : 'Αντικατάσταση υπάρχοντος προγράμματος + ενδεκάδων;' ?>')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="generate">
                <button type="submit" class="btn btn-success">
                    ⚡ <?= empty($matches) ? 'Δημιουργία Κλήρωσης' : 'Επαναδημιουργία' ?>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Schedule -->
<?php if (empty($byGameweek)): ?>
    <div class="alert alert-info">Δεν υπάρχει πρόγραμμα ακόμα. Πατήστε «Δημιουργία Κλήρωσης» παραπάνω.</div>
<?php else: foreach ($byGameweek as $gw => $gwMatches): ?>
<div class="card mb-3 shadow-sm">
    <div class="card-header fw-bold bg-dark text-white">
        Αγωνιστική <?= (int)$gw ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Εντός έδρας</th>
                    <th class="text-center" style="width:110px">Σκορ</th>
                    <th>Εκτός έδρας</th>
                    <th style="width:110px">Κατάσταση</th>
                    <th style="width:56px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($gwMatches as $m): ?>
            <tr>
                <td class="fw-semibold"><?= e($m['homeTeamName']) ?></td>
                <td class="text-center fw-bold">
                    <?= e((string)($m['homeScore'] ?? 0)) ?> – <?= e((string)($m['awayScore'] ?? 0)) ?>
                </td>
                <td class="fw-semibold"><?= e($m['awayTeamName']) ?></td>
                <td>
                    <?php
                    $ms = $m['status'] ?? 'SCHEDULED';
                    $mb = match($ms) { 'LIVE' => 'bg-success', 'FINISHED' => 'bg-secondary', default => 'bg-primary' };
                    ?>
                    <span class="badge <?= $mb ?>"><?= e($ms) ?></span>
                </td>
                <td>
                    <button class="btn btn-sm btn-outline-secondary"
                            data-bs-toggle="modal" data-bs-target="#editModal"
                            data-matchid="<?= e($m['id']) ?>"
                            data-home="<?= e($m['homeTeamName']) ?>"
                            data-away="<?= e($m['awayTeamName']) ?>"
                            data-hs="<?= (int)($m['homeScore'] ?? 0) ?>"
                            data-as="<?= (int)($m['awayScore'] ?? 0) ?>"
                            data-status="<?= e($ms) ?>">✏️</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; endif; ?>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action"   value="update_match">
            <input type="hidden" name="match_id" id="modalMatchId">
            <div class="modal-header">
                <h5 class="modal-title">Ενημέρωση Αγώνα</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="modalTeams" class="fw-bold mb-3 text-center fs-5"></p>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label" id="homeLabel">Γκολ εντός</label>
                        <input type="number" name="home_score" id="modalHs" class="form-control text-center fs-4 fw-bold" min="0" max="99">
                    </div>
                    <div class="col-6">
                        <label class="form-label" id="awayLabel">Γκολ εκτός</label>
                        <input type="number" name="away_score" id="modalAs" class="form-control text-center fs-4 fw-bold" min="0" max="99">
                    </div>
                </div>
                <div>
                    <label class="form-label">Κατάσταση</label>
                    <select name="status" id="modalStatus" class="form-select">
                        <option value="SCHEDULED">SCHEDULED</option>
                        <option value="LIVE">LIVE</option>
                        <option value="FINISHED">FINISHED</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Άκυρο</button>
                <button type="submit" class="btn btn-primary">Αποθήκευση</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('editModal').addEventListener('show.bs.modal', function(e) {
    const btn = e.relatedTarget;
    document.getElementById('modalMatchId').value     = btn.dataset.matchid;
    document.getElementById('modalTeams').textContent = btn.dataset.home + ' vs ' + btn.dataset.away;
    document.getElementById('homeLabel').textContent  = btn.dataset.home;
    document.getElementById('awayLabel').textContent  = btn.dataset.away;
    document.getElementById('modalHs').value          = btn.dataset.hs;
    document.getElementById('modalAs').value          = btn.dataset.as;
    document.getElementById('modalStatus').value      = btn.dataset.status;
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
