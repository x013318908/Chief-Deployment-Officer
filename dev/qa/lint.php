<?php

declare(strict_types=1);

$rootDir = dirname(__DIR__, 2);
$targets = [
    $rootDir . DIRECTORY_SEPARATOR . 'public_html',
    $rootDir . DIRECTORY_SEPARATOR . 'dev',
];

$phpBinary = escapeshellarg(PHP_BINARY);
$checked = 0;
$failures = [];

foreach ($targets as $target) {
    if (!is_dir($target)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
            continue;
        }

        $filePath = $fileInfo->getPathname();
        $command = $phpBinary . ' -l ' . escapeshellarg($filePath);
        exec($command . ' 2>&1', $output, $exitCode);
        $checked++;

        if ($exitCode !== 0) {
            $failures[] = [
                'path' => $filePath,
                'output' => implode(PHP_EOL, $output),
            ];
        }

        $output = [];
    }
}

if ($checked === 0) {
    fwrite(STDERR, "No PHP files found.\n");
    exit(1);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[lint] {$failure['path']}\n{$failure['output']}\n");
    }

    exit(1);
}

fwrite(STDOUT, "Lint OK ({$checked} files)\n");
