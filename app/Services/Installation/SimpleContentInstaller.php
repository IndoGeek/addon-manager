<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\ConcurrentDownloader;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download\Downloader;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;
use RuntimeException;

// Lightweight install path for single-file content (mods, plugins,
// datapacks, resource packs, shaders).
//
// Modpacks need the full pipeline (mrpack/zip extraction, dependency
// resolution, overrides). Content is downloads plus uploads: fetch the
// primary file of each version, stream it into the directory its content
// type belongs to, and return the relative path of each so the caller can
// record file-level ownership for uninstall.
//
// When several files are installed in one run (the picked content plus its
// recommended dependencies) they are downloaded concurrently through the
// shared batch engine, so every file in the run makes progress at once;
// uploads to the server node still happen one file at a time.
final class SimpleContentInstaller
{
    public function __construct(
        private readonly Downloader $downloader,
    ) {}

    /**
     * Downloads $sourceUrl and uploads it as <targetDirectory>/<filename>.
     *
     * @param  callable(): bool  $cancelChecker
     * @param  callable(?int, ?int): void  $onProgress  Mirror of the
     *         downloader's progress callback, so the controller can surface
     *         percent-complete and keep the install lock alive.
     *
     * @return array{path: string, size: int} The server-relative path the
     *         file was placed at and its byte size.
     */
    public function install(
        string $sourceUrl,
        string $filename,
        string $targetDirectory,
        ServerFileTarget $target,
        ?callable $cancelChecker = null,
        ?callable $onProgress = null,
    ): array {
        [$filename, $targetDirectory] = $this->validateTarget(
            $filename,
            $targetDirectory,
        );

        if (method_exists($this->downloader, 'setCancelChecker')) {
            $this->downloader->setCancelChecker($cancelChecker);
        }

        if ($onProgress !== null) {
            $this->downloader->setProgressCallback($onProgress);
        }

        // The DownloadManager returns a temporary file path it controls;
        // ownership of cleanup stays with it via its own workspace rules.
        $downloaded = $this->downloader->download($sourceUrl);

        if (!is_file($downloaded)) {
            throw new RuntimeException(
                'The downloaded content file could not be located.'
            );
        }

        $size = filesize($downloaded);

        $target->ensureDirectory($targetDirectory);
        $target->putFile($targetDirectory . '/' . $filename, $downloaded);

        // The temporary download is owned by this call; removing it here
        // keeps the temporary root clean without waiting for GC.
        @unlink($downloaded);

        return [
            'path' => $targetDirectory . '/' . $filename,
            'size' => $size === false ? 0 : $size,
        ];
    }

    /**
     * Downloads every item at once and uploads each into its own target
     * directory.
     *
     * @param  array<int, array{url: string, bytes?: int, filename: string, directory: string}>  $items
     * @param  string  $workspace  Scratch directory the concurrent engine
     *         streams into; created when missing and removed afterwards.
     * @param  callable(): bool  $cancelChecker
     * @param  callable(?int, ?int): void  $onProgress  Aggregate progress.
     * @param  callable(array<int, array{downloaded_bytes: int, total_bytes: int, done: bool, failed: bool}>): void  $onFileProgress
     *         Per-file progress, keyed by item index.
     * @param  callable(int, int): void  $onUpload  Called with the 0-based
     *         index and the total file count before each upload.
     *
     * @return array<int, array{path: string, size: int}> Results keyed by
     *         the item index they came from.
     */
    public function installBatch(
        array $items,
        ServerFileTarget $target,
        string $workspace,
        ?callable $cancelChecker = null,
        ?callable $onProgress = null,
        ?callable $onFileProgress = null,
        ?callable $onUpload = null,
    ): array {
        $prepared = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException(
                    'The content install list is malformed.'
                );
            }

            [$filename, $directory] = $this->validateTarget(
                (string) ($item['filename'] ?? ''),
                (string) ($item['directory'] ?? ''),
            );

            $prepared[$index] = [
                'url' => (string) ($item['url'] ?? ''),
                'bytes' => max(0, (int) ($item['bytes'] ?? 0)),
                'filename' => $filename,
                'directory' => $directory,
            ];
        }

        if ($prepared === []) {
            return [];
        }

        // A single file needs no batch engine, and a downloader without one
        // (test doubles, alternative drivers) falls back to one at a time.
        if (
            count($prepared) === 1
            || !$this->downloader instanceof ConcurrentDownloader
        ) {
            $results = [];

            foreach ($prepared as $index => $item) {
                $results[$index] = $this->install(
                    sourceUrl: $item['url'],
                    filename: $item['filename'],
                    targetDirectory: $item['directory'],
                    target: $target,
                    cancelChecker: $cancelChecker,
                    onProgress: $onProgress,
                );
            }

            return $results;
        }

        if (
            !is_dir($workspace)
            && !@mkdir($workspace, 0750, true)
            && !is_dir($workspace)
        ) {
            throw new RuntimeException(
                'Unable to create the content download workspace.'
            );
        }

        if (method_exists($this->downloader, 'setCancelChecker')) {
            $this->downloader->setCancelChecker($cancelChecker);
        }

        if ($onProgress !== null) {
            $this->downloader->setProgressCallback($onProgress);
        }

        if (
            $onFileProgress !== null
            && method_exists($this->downloader, 'setBatchProgressCallback')
        ) {
            $this->downloader->setBatchProgressCallback($onFileProgress);
        }

        // Anchor the aggregate bar on the total the provider declared, so
        // the percentage measures progress rather than mirroring the sum of
        // what already arrived.
        $declaredTotal = 0;

        foreach ($prepared as $item) {
            $declaredTotal += $item['bytes'];
        }

        $this->downloader->setProgressOffset(
            0,
            $declaredTotal > 0 ? $declaredTotal : null,
        );

        $tasks = [];

        foreach ($prepared as $index => $item) {
            $tasks[] = [
                'id' => $index,
                'urls' => [$item['url']],
                'bytes' => $item['bytes'],
                // Each file gets its own on-disk name so two items that
                // happen to resolve to the same module name cannot clash.
                'destination' => $workspace . '/' . $index . '-'
                    . $item['filename'],
            ];
        }

        try {
            $downloaded = $this->downloader->downloadBatch($tasks);
        } finally {
            if (method_exists($this->downloader, 'setBatchProgressCallback')) {
                $this->downloader->setBatchProgressCallback(null);
            }
        }

        $results = [];

        try {
            $total = count($prepared);

            foreach ($prepared as $index => $item) {
                $path = $downloaded[$index] ?? null;

                if (!is_string($path) || !is_file($path)) {
                    throw new RuntimeException(
                        'A content file in this install could not be downloaded.'
                    );
                }

                if ($onUpload !== null) {
                    $onUpload($index, $total);
                }

                $size = filesize($path);

                $target->ensureDirectory($item['directory']);
                $target->putFile(
                    $item['directory'] . '/' . $item['filename'],
                    $path,
                );

                $results[$index] = [
                    'path' => $item['directory'] . '/' . $item['filename'],
                    'size' => $size === false ? 0 : $size,
                ];
            }
        } finally {
            // The batch files are ours; the workspace goes away with them.
            foreach ($prepared as $index => $item) {
                $path = $downloaded[$index] ?? null;

                if (is_string($path) && is_file($path)) {
                    @unlink($path);
                }
            }

            @rmdir($workspace);
        }

        return $results;
    }

    // Strips anything that could escape the target directory, keeping the
    // basename the provider actually advertises (e.g. "sodium-0.6.0.jar").
    private function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));

        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? '';

        return ltrim($filename, '.');
    }

    // @return array{0: string, 1: string} sanitized filename + directory
    private function validateTarget(
        string $filename,
        string $targetDirectory,
    ): array {
        $filename = $this->safeFilename($filename);

        if ($filename === '') {
            throw new InvalidArgumentException(
                'The content file name could not be determined.'
            );
        }

        if (
            $targetDirectory === ''
            || preg_match('/^[A-Za-z0-9._\/-]{1,128}$/', $targetDirectory) !== 1
            || str_contains($targetDirectory, '..')
        ) {
            throw new InvalidArgumentException(
                'The install target directory is invalid.'
            );
        }

        return [$filename, rtrim($targetDirectory, '/')];
    }
}
