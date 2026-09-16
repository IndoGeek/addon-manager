<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Archive;

use InvalidArgumentException;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationCancelledException;
use RuntimeException;
use ZipArchive;

final class ArchiveExtractor
{
    /**
     * @var null|callable(): bool
     */
    private $cancelChecker = null;

    public function __construct(
        private readonly string $temporaryRoot,
        private readonly int $maxArchiveBytes = 10_737_418_240,
        private readonly int $maxExtractedBytes = 21_474_836_480,
        private readonly int $maxEntries = 50_000,
        private readonly int $maxEntryBytes = 10_737_418_240,
    ) {
    }

    /**
     * @param null|callable(): bool $checker
     */
    public function setCancelChecker(?callable $checker): void
    {
        $this->cancelChecker = $checker;
    }

    public function extract(string $archivePath): string
    {
        if (
            !is_dir($this->temporaryRoot)
            && !mkdir($this->temporaryRoot, 0750, true)
            && !is_dir($this->temporaryRoot)
        ) {
            throw new RuntimeException(
                'Unable to create the temporary extraction root.'
            );
        }

        $validator = new ArchiveValidator(
            maxArchiveBytes: $this->maxArchiveBytes,
            maxExtractedBytes: $this->maxExtractedBytes,
            maxEntries: $this->maxEntries,
            maxEntryBytes: $this->maxEntryBytes,
        );

        $validator->validate($archivePath);

        $destination = $this->createDestination();

        $zip = new ZipArchive();

        if ($zip->open($archivePath) !== true) {
            $this->removeDirectory($destination);

            throw new InvalidArgumentException(
                'The archive could not be opened for extraction.'
            );
        }

        try {
            $this->extractEntries($zip, $destination);

            return $destination;
        } catch (\Throwable $exception) {
            $this->removeDirectory($destination);

            throw $exception;
        } finally {
            $zip->close();
        }
    }

    private function extractEntries(
        ZipArchive $zip,
        string $destination,
    ): void {
        $writtenBytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $checker = $this->cancelChecker;

            if ($checker !== null && $checker()) {
                throw new InstallationCancelledException(
                    'Installation cancelled.'
                );
            }

            $entry = $zip->statIndex($index);

            if ($entry === false) {
                throw new RuntimeException(
                    'The archive contains an unreadable entry.'
                );
            }

            $name = (string) ($entry['name'] ?? '');

            if (str_ends_with($name, '/')) {
                if (!is_dir($destination . '/' . rtrim($name, '/'))) {
                    @mkdir(
                        $destination . '/' . rtrim($name),
                        0750,
                        true,
                    );
                }

                continue;
            }

            $directory = dirname($destination . '/' . $name);

            if (
                !is_dir($directory)
                && !@mkdir($directory, 0750, true)
                && !is_dir($directory)
            ) {
                throw new RuntimeException(
                    'Unable to create an extraction directory for the archive.'
                );
            }

            $entryStream = $zip->getStream($name);

            if ($entryStream === false) {
                throw new InvalidArgumentException(
                    'The archive contains a corrupt or unreadable entry.'
                );
            }

            $target = $destination . '/' . $name;

            $handle = @fopen($target, 'wb');

            if ($handle === false) {
                fclose($entryStream);

                throw new RuntimeException(
                    'Unable to create an extraction target for the archive.'
                );
            }

            try {
                while (!feof($entryStream)) {
                    $checker = $this->cancelChecker;

                    if ($checker !== null && $checker()) {
                        throw new InstallationCancelledException(
                            'Installation cancelled.'
                        );
                    }

                    $chunk = fread($entryStream, 262144);

                    if ($chunk === false) {
                        throw new InvalidArgumentException(
                            'The archive contains a corrupt or unreadable entry.'
                        );
                    }

                    if ($chunk === '') {
                        break;
                    }

                    $written = fwrite($handle, $chunk);

                    if ($written === false || $written !== strlen($chunk)) {
                        throw new RuntimeException(
                            'Unable to extract an entry from the archive.'
                        );
                    }

                    $writtenBytes += strlen($chunk);

                    if ($writtenBytes > $this->maxExtractedBytes) {
                        throw new InvalidArgumentException(
                            'The archive exceeds the maximum allowed extracted size.'
                        );
                    }
                }
            } finally {
                fclose($handle);
            }
        }
    }

    private function createDestination(): string
    {
        $destination = $this->temporaryRoot . '/' . bin2hex(random_bytes(16));

        if (!mkdir($destination, 0750, true) && !is_dir($destination)) {
            throw new RuntimeException(
                'Unable to create the extraction directory.'
            );
        }

        return $destination;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}