<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Archive;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

final class ArchiveValidator
{
    public function __construct(
        private readonly int $maxArchiveBytes = 2_147_483_648,
        private readonly int $maxExtractedBytes = 8_589_934_592,
        private readonly int $maxEntries = 50_000,
        private readonly int $maxEntryBytes = 1_073_741_824,
    ) {
    }

    public function validate(string $archivePath): void
    {
        if (!is_file($archivePath)) {
            throw new InvalidArgumentException('Archive file does not exist.');
        }

        $archiveSize = filesize($archivePath);

        if ($archiveSize === false) {
            throw new RuntimeException('Unable to determine archive size.');
        }

        if ($archiveSize > $this->maxArchiveBytes) {
            throw new InvalidArgumentException('Archive exceeds the maximum allowed size.');
        }

        $zip = new ZipArchive();

        $result = $zip->open($archivePath);

        if ($result !== true) {
            throw new InvalidArgumentException('The archive is not a valid ZIP file.');
        }

        try {
            if ($zip->numFiles > $this->maxEntries) {
                throw new InvalidArgumentException(
                    'Archive contains too many entries.'
                );
            }

            $totalExtractedBytes = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);

                if ($entry === false) {
                    throw new InvalidArgumentException(
                        'Unable to inspect an archive entry.'
                    );
                }

                $name = $entry['name'] ?? '';

                $this->validatePath($name);
                $this->validateEntryType($zip, $index, $name);

                $size = (int) ($entry['size'] ?? 0);

                if ($size < 0 || $size > $this->maxEntryBytes) {
                    throw new InvalidArgumentException(
                        "Archive entry exceeds the maximum allowed size: {$name}"
                    );
                }

                $totalExtractedBytes += $size;

                if ($totalExtractedBytes > $this->maxExtractedBytes) {
                    throw new InvalidArgumentException(
                        'Archive exceeds the maximum allowed extracted size.'
                    );
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function validatePath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException(
                'Archive contains an invalid entry path.'
            );
        }

        // Backslashes are rejected so Windows-style traversal cannot bypass
        // the path checks performed by the deployment layer.
        if (str_contains($path, '\\')) {
            throw new InvalidArgumentException(
                "Archive contains an invalid path: {$path}"
            );
        }

        // ZIP entries must always be relative to the extraction root.
        if (str_starts_with($path, '/')) {
            throw new InvalidArgumentException(
                "Archive contains an absolute path: {$path}"
            );
        }

        // Also reject Windows drive-letter paths.
        if (preg_match('/^[A-Za-z]:($|\/)/', $path) === 1) {
            throw new InvalidArgumentException(
                "Archive contains an absolute path: {$path}"
            );
        }

        $parts = explode('/', $path);

        foreach ($parts as $part) {
            if ($part === '..') {
                throw new InvalidArgumentException(
                    "Archive contains a path traversal entry: {$path}"
                );
            }
        }
    }

    private function validateEntryType(
        ZipArchive $zip,
        int $index,
        string $name,
    ): void {
        $opsys = 0;
        $attributes = 0;

        if (
            $zip->getExternalAttributesIndex(
                $index,
                $opsys,
                $attributes,
            )
        ) {
            $fileType = ($attributes >> 16) & 0xF000;

            // 0120000 = symbolic link in Unix file mode bits.
            if ($fileType === 0xA000) {
                throw new InvalidArgumentException(
                    "Archive contains a symbolic link: {$name}"
                );
            }
        }
    }
}
