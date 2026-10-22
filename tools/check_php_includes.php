<?php
/**
 * Repository-wide static include-path check for BIRMS.
 *
 * Run with:
 *   php tools/check_php_includes.php
 *
 * This catches missing files referenced through common literal include patterns
 * before they turn into blank tabs or fatal "Failed opening required" errors on XAMPP.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Command line only.\n");
    exit(2);
}

$root = dirname(__DIR__);
$requiredFiles = [
    'app/bootstrap.php',
    'app/core.php',
    'app/infobip_sms_provider.php',
    'tools/test_infobip_sms.php',
];

$errors = [];
foreach ($requiredFiles as $relative) {
    if (!is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
        $errors[] = "Required project file is missing: {$relative}";
    }
}

function check_include_target(string $base, string $literal, string $root): ?string
{
    // Only check static paths. Dynamic includes cannot be resolved reliably without execution.
    if ($literal === '' || str_contains($literal, '$') || str_contains($literal, "\0")) {
        return null;
    }
    $combined = str_replace('\\', '/', $base . '/' . $literal);
    $parts = [];
    foreach (explode('/', $combined) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if ($parts) {
                array_pop($parts);
            }
            continue;
        }
        $parts[] = $part;
    }
    $target = implode('/', $parts);
    $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $target);
    return is_file($absolute) ? null : $target;
}

$scanned = 0;
$checked = 0;
$patterns = [
    'dir' => '~\\b(?:require|include)(?:_once)?\\s*\\(?\\s*__DIR__\\s*\\.\\s*([\'"])([^\'"]+)\\1~',
    'root' => '~\\b(?:require|include)(?:_once)?\\s*\\(?\\s*APP_ROOT\\s*\\.\\s*([\'"])([^\'"]+)\\1~',
];

foreach (['app', 'public', 'tests', 'tools'] as $directory) {
    $start = $root . DIRECTORY_SEPARATOR . $directory;
    if (!is_dir($start)) {
        $errors[] = "Expected source directory is missing: {$directory}/";
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($start, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $info) {
        if (!$info->isFile() || strtolower($info->getExtension()) !== 'php') {
            continue;
        }
        $absolute = $info->getPathname();
        $file = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
        $source = file_get_contents($absolute);
        if ($source === false) {
            $errors[] = "Could not read PHP source file: {$file}";
            continue;
        }
        $scanned++;

        foreach ($patterns as $kind => $pattern) {
            if (!preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                $literal = $match[2];
                $base = $kind === 'root' ? '' : dirname($file);
                $missing = check_include_target($base, $literal, $root);
                if ($missing !== null) {
                    $line = substr_count(substr($source, 0, (int) strpos($source, $match[0])), "\n") + 1;
                    $errors[] = "{$file}:{$line} references missing file '{$missing}'";
                }
                $checked++;
            }
        }
    }
}

if ($errors) {
    fwrite(STDERR, "FAIL: PHP include-path check found " . count($errors) . " issue(s):\n");
    foreach (array_unique($errors) as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

echo "PASS: checked {$scanned} PHP files and {$checked} static include path(s); all referenced targets exist.\n";
echo "PASS: shared bootstrap, PHP core, and Infobip test/provider files are present.\n";
