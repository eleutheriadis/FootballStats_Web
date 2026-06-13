<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__)->load();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true,
    ]);
}

use Kreait\Firebase\Factory;

function getDb(): \Google\Cloud\Firestore\FirestoreClient {
    static $db = null;
    if ($db === null) {
        $credFile = __DIR__ . '/' . ($_ENV['FIREBASE_CREDENTIALS'] ?? 'serviceAccountKey.json');
        $db = (new Factory)->withServiceAccount($credFile)->createFirestore()->database();
    }
    return $db;
}

/**
 * Επιστρέφει το default bucket του Firebase Storage.
 * Χρησιμοποιείται για ανέβασμα εικόνων (φωτο παικτών, logo ομάδων) ώστε να
 * έχουμε public URLs που μπορεί να φορτώσει το Android (Glide) απευθείας.
 */
function getStorageBucket(): \Google\Cloud\Storage\Bucket {
    static $bucket = null;
    if ($bucket === null) {
        $credFile = __DIR__ . '/' . ($_ENV['FIREBASE_CREDENTIALS'] ?? 'serviceAccountKey.json');
        $bucket = (new Factory)->withServiceAccount($credFile)->createStorage()->getBucket();
    }
    return $bucket;
}

function requireAuth(): void {
    if (empty($_SESSION['admin_logged_in'])) {
        header('Location: login.php');
        exit;
    }
}

function isLoggedIn(): bool {
    return !empty($_SESSION['admin_logged_in']);
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function csrfValid(): bool {
    return !empty($_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
}

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flashRender(): string {
    $html = '';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $cls = match ($f['type']) {
            'success' => 'alert-success',
            'danger'  => 'alert-danger',
            'warning' => 'alert-warning',
            default   => 'alert-info',
        };
        $html .= '<div class="alert ' . $cls . ' alert-dismissible"><button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
               . e($f['message']) . '</div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function positionLabel(string $code): string {
    return ['GK' => 'Τερματοφύλακας', 'DEF' => 'Αμυντικός',
            'MID' => 'Μέσος', 'FWD' => 'Επιθετικός'][$code] ?? $code;
}

/**
 * Ανεβάζει την εικόνα στο Firebase Storage και επιστρέφει public URL.
 *
 *   @param $file       το $_FILES['xxx'] entry
 *   @param $subfolder  "players" ή "teams" — γίνεται prefix στο Storage path
 *
 * Επιστρέφει:
 *   ['path' => 'https://storage.googleapis.com/.../players/abc.jpg']  σε επιτυχία
 *   ['error' => '...']                                                 σε αποτυχία
 *
 * Σημείωση: τα αρχεία ανεβαίνουν με predefinedAcl=publicRead ώστε να μπορεί
 * το Android να τα φορτώσει χωρίς authentication.
 */
function uploadImage(array $file, string $subfolder): array {
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return ['error' => 'Δεν επιλέχθηκε αρχείο.'];
    if ($file['error'] !== UPLOAD_ERR_OK)      return ['error' => 'Σφάλμα μεταφόρτωσης.'];
    if ($file['size'] > 2 * 1024 * 1024)       return ['error' => 'Το αρχείο υπερβαίνει τα 2MB.'];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']) ?: '';
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    if (!isset($allowed[$mime])) return ['error' => 'Μη επιτρεπτός τύπος αρχείου.'];

    try {
        $bucket = getStorageBucket();
        $objectName = $subfolder . '/' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];

        $bucket->upload(
            fopen($file['tmp_name'], 'r'),
            [
                'name'          => $objectName,
                'predefinedAcl' => 'publicRead',
                'metadata'      => ['contentType' => $mime],
            ]
        );

        // Public URL — δουλεύει αμέσως επειδή το object είναι publicRead.
        $url = 'https://storage.googleapis.com/' . $bucket->name() . '/' . $objectName;
        return ['path' => $url];
    } catch (\Throwable $e) {
        return ['error' => 'Αποτυχία ανεβάσματος: ' . $e->getMessage()];
    }
}
