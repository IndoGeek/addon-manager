<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Archive;

use InvalidArgumentException;

use RuntimeException;

use ZipArchive;

final class ArchiveExtractor

{

    public function __construct(

        private readonly string $temporaryRoot,

    ) {

    }

    public function extract(string $archivePath): string

    {

        if (!is_dir($this->temporaryRoot)) {

            if (!mkdir($this->temporaryRoot, 0750, true) && !is_dir($this->temporaryRoot)) {

                throw new RuntimeException('Unable to create the temporary extraction root.');

            }

        }

        $validator = new ArchiveValidator();

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

            if (!$zip->extractTo($destination)) {

                throw new RuntimeException(

                    'The archive could not be extracted.'

                );

            }

        } catch (\Throwable $exception) {

            $this->removeDirectory($destination);

            throw $exception;

        } finally {

            $zip->close();

        }

        return $destination;

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

                rmdir($item->getPathname());

            } else {

                unlink($item->getPathname());

            }

        }

        rmdir($directory);

    }

}

