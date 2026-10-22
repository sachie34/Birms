<?php
/**
 * Generate a fresh SHA-256 manifest for this exact BIRMS working copy.
 *
 * Usage:
 *   php tools/generate_manifest.php
 *
 * The manifest is a local integrity snapshot, not proof of download authenticity.
 * Never include credentials, logs, backups, or private uploads in the manifest.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found. Run this tool from PHP CLI.\n");
}

$root = realpath(dirname(__DIR__));
if ($root === false || !is_dir($root)) {
    fwrite(STDERR, "ERROR: Could not resolve the BIRMS project root.\n");
    exit(2);
}

$manifestPath = $root . DIRECTORY_SEPARATOR . 'MANIFEST.txt';
$excludedExact = [
    'MANIFEST.txt',
    '.env',
    'Api',
    'app/config.local.php',
    'app/infobip.local.php',
];
$excludedPrefixes = [
    '.git/',
    'storage/logs/',
    'storage/backups/',
    'storage/private_uploads/',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

$checksums = [];
foreach ($iterator as $entry) {
    if (!$entry instanceof SplFileInfo || !$entry->isFile() || $entry->isLink()) {
        continue;
    }

    $absolutePath = $entry->getPathname();
    $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($root) + 1));

    if (in_array($relativePath, $excludedExact, true)) {
        continue;
    }

    $skip = false;
    foreach ($excludedPrefixes as $prefix) {
        if (str_starts_with($relativePath, $prefix)) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    $hash = hash_file('sha256', $absolutePath);
    if (!is_string($hash)) {
        fwrite(STDERR, "ERROR: Could not hash file: {$relativePath}\n");
        exit(2);
    }
    $checksums[$relativePath] = $hash;
}

ksort($checksums, SORT_STRING);
$lines = [
    'BIRMS source-file manifest (SHA-256)',
    'Generated: ' . date(DATE_ATOM),
    'Project root: ' . basename($root),
    'Excluded: MANIFEST.txt itself, local config/API keys, .env, legacy Api file, Git metadata, logs, backups, and private uploads.',
    'Purpose: detect later changes to this same working copy; this list is not proof of download authenticity.',
    '',
];

foreach ($checksums as $path => $hash) {
    $lines[] = $hash . '  ' . $path;
}

$output = implode("\n", $lines) . "\n";
if (file_put_contents($manifestPath, $output, LOCK_EX) === false) {
    fwrite(STDERR, "ERROR: Could not write MANIFEST.txt. Check folder permissions.\n");
    exit(2);
}

fwrite(STDOUT, 'Manifest generated: ' . count($checksums) . ' files recorded. Private configuration and runtime data were excluded.' . PHP_EOL);
