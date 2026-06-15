<?php
/**
 * Διαγνωστικό για να βρούμε γιατί δεν φορτώνει η σελίδα.
 * ΑΦΑΙΡΕΣΕ αυτό το αρχείο μετά τη διάγνωση — εκθέτει πληροφορίες του server.
 *
 * Χρήση: άνοιξε https://your-server/path/to/FootballStats_Web/diagnose.php
 */

// Show all errors
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "=== ΔΙΑΓΝΩΣΤΙΚΟ FOOTBALLSTATS_WEB ===\n\n";

// 1) PHP version
echo "[1] PHP version: " . PHP_VERSION . "\n";
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    echo "    ❌ ΠΡΟΒΛΗΜΑ: Το kreait/firebase-php 7.x απαιτεί PHP >= 8.1\n";
} else {
    echo "    ✅ OK\n";
}

// 2) Required extensions
echo "\n[2] PHP extensions:\n";
$required = ['mbstring', 'openssl', 'curl', 'json', 'fileinfo', 'gmp', 'sodium', 'bcmath'];
foreach ($required as $ext) {
    $ok = extension_loaded($ext);
    echo "    " . ($ok ? "✅" : "❌") . " $ext\n";
}

// 3) Working directory
echo "\n[3] Working directory: " . __DIR__ . "\n";

// 4) Files present
echo "\n[4] Required files:\n";
$files = [
    'config.php'             => 'core',
    'composer.json'          => 'composer',
    'vendor/autoload.php'    => 'composer install τρέξατε;',
    '.env'                   => 'cp .env.example .env',
    'serviceAccountKey.json' => 'κατέβασε από Firebase Console',
];
foreach ($files as $f => $hint) {
    $path = __DIR__ . '/' . $f;
    $ok   = file_exists($path);
    $size = $ok ? filesize($path) : 0;
    echo "    " . ($ok ? "✅" : "❌") . " $f"
       . ($ok ? " ($size bytes)" : " — λείπει: $hint")
       . "\n";
}

// 5) Write permissions
echo "\n[5] Write permissions:\n";
$dirs = ['uploads', 'uploads/players', 'uploads/teams'];
foreach ($dirs as $d) {
    $path = __DIR__ . '/' . $d;
    if (!is_dir($path)) {
        echo "    ⚠️  $d → δεν υπάρχει\n";
        continue;
    }
    $w = is_writable($path);
    echo "    " . ($w ? "✅" : "❌") . " $d → " . substr(sprintf('%o', fileperms($path)), -4) . "\n";
}

// 6) Composer autoload
echo "\n[6] Composer autoload:\n";
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    try {
        require_once __DIR__ . '/vendor/autoload.php';
        echo "    ✅ vendor/autoload.php loaded\n";
    } catch (\Throwable $e) {
        echo "    ❌ Crash: " . $e->getMessage() . "\n";
    }
} else {
    echo "    ❌ Δεν υπάρχει — τρέξε: composer install\n";
}

// 7) .env loading
echo "\n[7] .env:\n";
if (file_exists(__DIR__ . '/.env') && class_exists('Dotenv\Dotenv')) {
    try {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
        echo "    ✅ .env loaded\n";
        echo "    ADMIN_USERNAME      = " . ($_ENV['ADMIN_USERNAME'] ?? '(missing)') . "\n";
        echo "    FIREBASE_CREDENTIALS = " . ($_ENV['FIREBASE_CREDENTIALS'] ?? '(default)') . "\n";
    } catch (\Throwable $e) {
        echo "    ❌ Crash: " . $e->getMessage() . "\n";
    }
} else {
    echo "    ⚠️  Skipped\n";
}

// 8) Firebase connection test
echo "\n[8] Firebase σύνδεση:\n";
$credFile = __DIR__ . '/' . ($_ENV['FIREBASE_CREDENTIALS'] ?? 'serviceAccountKey.json');
if (!file_exists($credFile)) {
    echo "    ❌ Δεν βρίσκω το credentials file: $credFile\n";
} else {
    try {
        $factory = (new \Kreait\Firebase\Factory)->withServiceAccount($credFile);
        $db = $factory->createFirestore()->database();
        echo "    ✅ Firestore client OK\n";

        // Try a read
        $docs = $db->collection('teams')->limit(1)->documents();
        $count = 0;
        foreach ($docs as $d) { $count++; }
        echo "    ✅ Read test (teams): $count document(s)\n";
    } catch (\Throwable $e) {
        echo "    ❌ Crash: " . $e->getMessage() . "\n";
        echo "       File:  " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

echo "\n=== ΤΕΛΟΣ ===\n";
echo "\n⚠️  ΑΦΑΙΡΕΣΕ αυτό το αρχείο: rm " . __FILE__ . "\n";
