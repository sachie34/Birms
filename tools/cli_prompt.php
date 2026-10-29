<?php
/**
 * Interactive CLI prompts shared by the XAMPP installer and admin bootstrap.
 * Secret input must never be read with ordinary fgets() while terminal echo is on.
 */
declare(strict_types=1);

function cli_prompt_line(string $message): string
{
    fwrite(STDOUT, $message);
    $line = fgets(STDIN);
    return trim($line === false ? '' : $line);
}

/** Read a secret with terminal echo disabled; fail closed if this cannot be done. */
function cli_prompt_secret(string $message): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        return cli_prompt_secret_windows($message);
    }

    if (!function_exists('exec') || !function_exists('stream_isatty') || !stream_isatty(STDIN)) {
        throw new RuntimeException(
            'This terminal cannot safely read a hidden password. Run the command from an interactive terminal or supply the supported secret environment variable for this operation.'
        );
    }

    fwrite(STDOUT, $message . ' (input hidden): ');
    $discard = [];
    $status = 1;
    exec('stty -echo 2>/dev/null', $discard, $status);
    if ($status !== 0) {
        throw new RuntimeException('Could not disable terminal echo. The password was not read; use a secure interactive terminal and retry.');
    }

    try {
        $line = fgets(STDIN);
        if ($line === false) {
            throw new RuntimeException('No password input was received.');
        }
        return rtrim($line, "\r\n");
    } finally {
        $restore = [];
        $restoreStatus = 1;
        exec('stty echo 2>/dev/null', $restore, $restoreStatus);
        fwrite(STDOUT, PHP_EOL);
        if ($restoreStatus !== 0) {
            fwrite(STDERR, "WARNING: Terminal echo could not be restored automatically. Run 'stty echo' before continuing to type.
");
        }
    }
}

/** Windows PowerShell Read-Host -AsSecureString hides keystrokes; only base64 leaves the child. */
function cli_prompt_secret_windows(string $message): string
{
    if (!function_exists('proc_open') || !function_exists('iconv')) {
        throw new RuntimeException(
            'Secure hidden password input requires Windows PowerShell and PHP iconv. The password was not read. Use an interactive supported Windows terminal or a secure environment-injection method.'
        );
    }

    $messageBase64 = base64_encode($message);
    $script = '$prompt = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("' . $messageBase64 . '")); '
        . '$secure = Read-Host -Prompt $prompt -AsSecureString; '
        . '$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure); '
        . 'try { $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr); '
        . '[Console]::Error.Write([Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($plain))) } '
        . 'finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }';
    $utf16 = iconv('UTF-8', 'UTF-16LE', $script);
    if ($utf16 === false) {
        throw new RuntimeException('Could not prepare the secure Windows password prompt. The password was not read.');
    }
    $command = ['powershell.exe', '-NoLogo', '-NoProfile', '-EncodedCommand', base64_encode($utf16)];
    $descriptors = [
        0 => ['file', 'php://stdin', 'r'],
        1 => ['file', 'php://stdout', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the secure Windows password prompt. The password was not read.');
    }

    $encoded = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $encoded = trim((string) $encoded);
    $value = $exitCode === 0 ? base64_decode($encoded, true) : false;
    if (!is_string($value)) {
        throw new RuntimeException('The secure Windows password prompt failed. The password was not accepted or displayed; retry from PowerShell/Command Prompt.');
    }
    return $value;
}
