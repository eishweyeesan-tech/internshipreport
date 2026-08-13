<?php
/**
 * tests/phone_validation_test.php
 *
 * Regression tests for Myanmar phone-number validation + normalization.
 *
 * Covers:
 *   1. Accepted formats (09..., +959..., separated forms)
 *   2. Rejected formats (no prefix, letters, arbitrary special chars)
 *   3. Normalization to the local 09... format used in the database
 *   4. Legacy values already stored in the DB stay accepted
 *   5. Backend guards wired into every phone-number form handler
 *   6. Database save format (scratch DB, mirrors the INSERT shape used
 *      by admin/manage-companies.php)
 *
 * Run with the PHP CLI from the project root:
 *   php tests/phone_validation_test.php
 */

require_once __DIR__ . '/../includes/phone_validation.php';

// ── Minimal assertion harness ──────────────────────────────────
$GLOBALS['assertions'] = 0;
$GLOBALS['failures']   = 0;

function check(bool $cond, string $label): void
{
    $GLOBALS['assertions']++;
    if ($cond) {
        echo "  [PASS] {$label}\n";
    } else {
        $GLOBALS['failures']++;
        echo "  [FAIL] {$label}\n";
    }
}

function src(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Cannot read source file: {$path}");
    }
    return $contents;
}

echo "== Scenario 1: valid formats are accepted\n";

$valid = [
    '09123456789',
    '+959123456789',
    '09 123 456 789',
    '09-123-456-789',
    '09454104282',
    '095019865',
    '+95 9123456789',
];
foreach ($valid as $v) {
    check(phone_validation_error($v) === null, sprintf('%s (len %d) -> accepted', $v, strlen($v)));
}

echo "\n== Scenario 2: invalid formats are rejected\n";

$invalid = [
    '123456',
    '09123',
    '09abc123456',
    '+959abc12345',
    'hello',
    '09 1234',
];
foreach ($invalid as $v) {
    check(phone_validation_error($v) !== null, sprintf('%s (len %d) -> rejected', $v, strlen($v)));
}

check(phone_validation_error('09abc123456') === 'Phone number can only contain valid phone-number characters.',
    'alphabetic input returns the invalid-characters message');
check(phone_validation_error('123456') === 'Please enter a valid phone number.',
    'digit-only input without the 09/+959 prefix returns the format message');
check(phone_validation_error('') === null, 'empty phone field is allowed (optional)');

echo "\n== Scenario 3: normalization to the stored 09... format\n";

$normalized = [
    '09 123 456 789' => '09123456789',
    '09-123-456-789' => '09123456789',
    '+959123456789'  => '09123456789',
    '+95 9123456789' => '09123456789',
    '09454104282'    => '09454104282',
    '095019865'      => '095019865',
];
foreach ($normalized as $input => $expected) {
    check(normalize_phone($input) === $expected, sprintf('%s -> %s', $input, $expected));
}
check(phone_validation_error('09 123 456 789') === null, 'separated form still passes validation after normalization');

echo "\n== Scenario 4: backend guards wired into every phone form handler\n";

$ROOT = __DIR__ . '/..';

$guards = [
    [$ROOT . '/admin/manage-companies.php', 'phone_validation_error($contact_phone)'],
    [$ROOT . '/admin/manage-companies.php', 'normalize_phone($contact_phone)'],
    [$ROOT . '/supervisor/profile.php',      'phone_validation_error($new_phone)'],
    [$ROOT . '/supervisor/profile.php',      'normalize_phone($new_phone)'],
    [$ROOT . '/student/profile.php',         'phone_validation_error($phone)'],
    [$ROOT . '/student/profile.php',         'phone_validation_error($instructor_phone)'],
    [$ROOT . '/student/profile.php',         'normalize_phone($phone)'],
    [$ROOT . '/student/profile.php',         'normalize_phone($instructor_phone)'],
];
foreach ($guards as [$file, $needle]) {
    check(strpos(src($file), $needle) !== false, basename(dirname($file)) . '/' . basename($file) . " uses {$needle}");
}

echo "\n== Scenario 5: database save format (scratch DB)\n";

$dbOk  = true;
$dbErr = '';
try {
    $admin = new PDO("mysql:host=localhost;port=3306;charset=utf8mb4", 'root', 'root', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    $dbOk  = false;
    $dbErr = $e->getMessage();
}

if ($dbOk) {
    $testDb = 'intern_report_db_test_phone';
    $admin->exec("DROP DATABASE IF EXISTS `{$testDb}`");
    $admin->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4");
    $pdo = new PDO("mysql:host=localhost;port=3306;dbname={$testDb};charset=utf8mb4", 'root', 'root', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("
        CREATE TABLE companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(150) NOT NULL,
            contact_phone VARCHAR(30) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Mirrors the INSERT shape in admin/manage-companies.php after the
    // handler normalizes the submitted value.
    $contact_phone = normalize_phone('+959 123 456 789');
    $pdo->prepare("INSERT INTO companies (company_name, contact_phone) VALUES (?, ?)")
        ->execute(['Test Co', $contact_phone]);

    $stored = $pdo->query("SELECT contact_phone FROM companies WHERE company_name = 'Test Co'")->fetchColumn();
    check($stored === '09123456789', 'stored contact_phone is the normalized 09... form (got "' . $stored . '")');
    check(strlen($stored) <= 30, 'normalized value fits in VARCHAR(30)');

    // Legacy value preserved: raw legacy strings round-trip unchanged.
    $pdo->prepare("INSERT INTO companies (company_name, contact_phone) VALUES (?, ?)")
        ->execute(['Legacy Co', '095019865']);
    $legacy = $pdo->query("SELECT contact_phone FROM companies WHERE company_name = 'Legacy Co'")->fetchColumn();
    check($legacy === '095019865', 'existing legacy value 095019865 is preserved untouched');

    $admin->exec("DROP DATABASE IF EXISTS `{$testDb}`");
} else {
    echo "  [SKIP] MySQL unavailable ({$dbErr}) — DB save-format checks skipped\n";
}

echo "\n-----------------------------------------------\n";
if ($GLOBALS['failures'] === 0) {
    echo "ALL {$GLOBALS['assertions']} ASSERTIONS PASSED\n";
    exit(0);
} else {
    echo "{$GLOBALS['failures']} of {$GLOBALS['assertions']} assertions FAILED\n";
    exit(1);
}
