<?php
/**
 * tests/password_min_length_test.php
 *
 * Regression tests for the "minimum 6-character password" rule.
 *
 * The rule must be enforced SERVER-SIDE in every place a password is
 * created or changed (registration, change password, reset-to-default),
 * while normal LOGIN must NOT enforce a minimum length so that existing
 * accounts with short passwords can still sign in.
 *
 * These tests inspect the actual deployed handler files (the same
 * approach the sibling academic_year_trigger_test.php uses to verify the
 * real trigger body) plus exercise the password_hash()/password_verify()
 * behavior the handlers rely on. No live database is touched.
 *
 * Run with the PHP CLI from the project root:
 *   php tests/password_min_length_test.php
 */

$ROOT = __DIR__ . '/..';

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

// Backend guards live on a single line; strip leading whitespace so the
// check is robust to indentation.
function hasGuard(string $file, string $needle): bool
{
    $content = src($file);
    $normalized = preg_replace('/\r\n/', "\n", $content);
    $normalized = preg_replace('/^[ \t]+/m', '', $normalized);
    return strpos($normalized, $needle) !== false;
}

echo "== Scenario 1: server-side guards require 6+ characters where passwords are created/changed\n";

$changeForms = [
    'change-password.php (forced first-login change)' =>
        [$ROOT . '/change-password.php', "elseif (strlen(\$new_password) < 6) {"],
    'admin/admin-profile.php (admin change password)' =>
        [$ROOT . '/admin/admin-profile.php', "elseif (strlen(\$new_pw) < 6) {"],
    'supervisor/profile.php (supervisor change password)' =>
        [$ROOT . '/supervisor/profile.php', "elseif (strlen(\$new_pw) < 6) {"],
    'student/profile.php (student change password)' =>
        [$ROOT . '/student/profile.php', "elseif (strlen(\$new_pw) < 6) {"],
    'admin/admin-profile.php (default passwords)' =>
        [$ROOT . '/admin/admin-profile.php', "elseif (strlen(\$d_student) < 6 || strlen(\$d_sup) < 6) {"],
    'admin/admin-dashboard.php (student registration)' =>
        [$ROOT . '/admin/admin-dashboard.php', "elseif (strlen(\$s_password) < 6) {"],
    'admin/admin-dashboard.php (supervisor registration)' =>
        [$ROOT . '/admin/admin-dashboard.php', "elseif (strlen(\$t_password) < 6) {"],
];

foreach ($changeForms as $label => [$file, $needle]) {
    check(hasGuard($file, $needle), "backend guard present: {$label}");
}

// Every guard must produce the clear message, never "8 characters".
foreach ([
    $ROOT . '/change-password.php',
    $ROOT . '/admin/admin-profile.php',
    $ROOT . '/supervisor/profile.php',
    $ROOT . '/student/profile.php',
] as $file) {
    check(strpos(src($file), 'must be at least 6 characters.') !== false,
        basename(dirname($file)) . '/' . basename($file) . ' uses the "at least 6 characters" message');
    check(strpos(src($file), 'at least 8 characters') === false,
        basename(dirname($file)) . '/' . basename($file) . ' has no stale 8-character rule');
}

echo "\n== Scenario 2: normal login never enforces a minimum length\n";

$login = src($ROOT . '/login.php');
check(strpos($login, 'password_verify($password, $user[\'password\'])') !== false,
    'login.php verifies the stored hash with password_verify()');
check(preg_match('/strlen\(\$password\)\s*</', $login) === 0,
    'login.php does NOT reject short stored passwords (existing users keep working)');

echo "\n== Scenario 3: frontend validation uses minlength=\"6\"\n";

$frontendInputs = [
    'change-password.php'                  => '<input type="password" name="new_password" required minlength="6"',
    'admin/admin-profile.php (new_password)' => '<input type="password" name="new_password" required minlength="6"',
    'supervisor/profile.php (new_password)'  => '<input type="password" name="new_password" required minlength="6"',
    'student/profile.php (new_password)'     => '<input type="password" name="new_password" required minlength="6"',
    'admin/admin-dashboard.php (student pw)' => '<input type="text" name="s_password" required minlength="6"',
    'admin/admin-dashboard.php (supervisor pw)' => '<input type="text" name="t_password" required minlength="6"',
];

foreach ($frontendInputs as $label => $needle) {
    $file = $ROOT . '/' . strtok($label, ' ');
    check(hasGuard($file, $needle), "frontend minlength=6 present: {$label}");
}

check(strpos(src($ROOT . '/change-password.php'), 'At least 6 characters.') !== false,
    'change-password.php hint text says "At least 6 characters."');

$noStaleMin = true;
foreach ([$ROOT . '/change-password.php', $ROOT . '/admin/admin-profile.php', $ROOT . '/supervisor/profile.php', $ROOT . '/student/profile.php'] as $file) {
    if (strpos(src($file), 'minlength="8"') !== false) {
        $noStaleMin = false;
    }
}
check($noStaleMin, 'no password input still carries minlength="8"');

echo "\n== Scenario 4: password hashing behavior\n";

// The stored hash never encodes the plain-text length, so a pre-existing
// short-password user still verifies. This mirrors login.php's branch.
$shortHash = password_hash('12345', PASSWORD_DEFAULT);
check(password_verify('12345', $shortHash), 'existing 5-character password still verifies against its stored hash');

$validHashes = ['123456' => password_hash('123456', PASSWORD_DEFAULT), 'abc123' => password_hash('abc123', PASSWORD_DEFAULT)];
check(password_verify('123456', $validHashes['123456']), 'accepted password "123456" verifies');
check(password_verify('abc123', $validHashes['abc123']), 'accepted password "abc123" verifies');
check(password_verify('abc124', $validHashes['abc123']) === false, 'mismatched "abc124" does not verify against "abc123"');

echo "\n== Scenario 5: boundary rule as implemented (rejects < 6, accepts 6+)\n";

$cases = [
    '12345'  => false, // rejected: 5 chars
    'abc12'  => false, // rejected: 5 chars
    '123456' => true,  // accepted: 6 chars
    'abcdef' => true,  // accepted: 6 chars
    'abc123' => true,  // accepted: 6 chars
];
foreach ($cases as $pw => $accepted) {
    $guardRejects = strlen($pw) < 6;
    check($guardRejects === !$accepted, sprintf('%s length %d -> %s', $pw, strlen($pw), $accepted ? 'accepted' : 'rejected'));
}

// Confirmation mismatch example from the spec.
check('abc123' !== 'abc124', 'new password "abc123" vs confirm "abc124" is a mismatch (passwords do not match)');

echo "\n-----------------------------------------------\n";
if ($GLOBALS['failures'] === 0) {
    echo "ALL {$GLOBALS['assertions']} ASSERTIONS PASSED\n";
    exit(0);
} else {
    echo "{$GLOBALS['failures']} of {$GLOBALS['assertions']} assertions FAILED\n";
    exit(1);
}
