<?php
/**
 * tools/create_admin.php  Create the FIRST administrator, or reset a forgotten password.
 *   php tools/create_admin.php --username=admin --name="Full Name"
 *   php tools/create_admin.php --reset=username
 * The password is typed at the prompt (it is visible on Windows) or supplied through the
 * BIRMS_ADMIN_PASSWORD environment variable. There is no default password.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit("Command line only.\n"); }
require __DIR__ . '/../app/core.php';

function die_msg(string $s): never { fwrite(STDERR, $s . PHP_EOL); exit(2); }
$opt = [];
foreach (array_slice($argv, 1) as $a) { if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $opt[$m[1]] = $m[2] ?? '1'; } }

$name = (string) config('db.name');
if (!preg_match('/(_work|_test)$/', $name) && !isset($opt['i-have-a-backup'])) {
    die_msg("'{$name}' is not a _work/_test database. Back it up, then add --i-have-a-backup.");
}
try { db(); } catch (PDOException $e) { die_msg('Cannot connect: ' . $e->getMessage() . "\nRun: php tools/migrate.php up"); }

$pw = getenv('BIRMS_ADMIN_PASSWORD');
if ($pw === false || $pw === '') {
    fwrite(STDOUT, 'New password (min ' . config('security.password_min_length') . ' characters, letters and numbers): ');
    $pw = rtrim((string) fgets(STDIN), "\r\n");
}
$username = isset($opt['reset']) ? strtolower((string) $opt['reset']) : strtolower((string) ($opt['username'] ?? ''));
if (!v_username($username)) { die_msg('Give a valid --username (3-30 characters: a-z, 0-9, dot, dash, underscore).'); }
$errs = password_policy_errors($pw, $username);
if ($errs) { die_msg("Password not accepted:\n - " . implode("\n - ", $errs)); }

if (isset($opt['reset'])) {
    $row = db_one('SELECT user_id FROM users WHERE username = ?', [$username]);
    if (!$row) { die_msg("No user named {$username}."); }
    db_transaction(function () use ($pw, $row, $username): void {
        db_exec("UPDATE users SET password_hash = ?, must_change_password = 1, status = 'active' WHERE user_id = ?", [password_hash($pw, PASSWORD_DEFAULT), $row['user_id']]);
        db_exec('DELETE FROM login_failures WHERE username = ?', [$username]);
        audit('password_reset_cli', 'Users', 'Success', 'Password reset from the command line for ' . $username, 'user', (int) $row['user_id'], 'cli');
    });
    echo "Password reset. {$username} must choose a new password at the next sign-in.\n"; exit(0);
}
$full = trim((string) ($opt['name'] ?? ''));
if (!v_person_name($full)) { die_msg('Give the full name with --name="Juan Dela Cruz".'); }
if ((int) db_val("SELECT COUNT(*) FROM users WHERE role = 'admin'") > 0) { die_msg('An administrator already exists. Add users from the web page, or use --reset=username.'); }
db_transaction(function () use ($username, $pw, $full): void {
    db_exec("INSERT INTO users (username, password_hash, full_name, role, status, must_change_password) VALUES (?, ?, ?, 'admin', 'active', 0)", [$username, password_hash($pw, PASSWORD_DEFAULT), $full]);
    audit('user_created', 'Users', 'Success', 'First administrator created from the command line: ' . $username, 'user', (int) db()->lastInsertId(), 'cli');
});
echo "Administrator '{$username}' created. Open the system and sign in.\n";
