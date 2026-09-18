<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerRelativePath;
use InvalidArgumentException;
use Throwable;

// Removes exactly the files an install record owns, and nothing else.
final class OwnershipRemover
{
    public function __construct(
        private readonly ServerFileTarget $serverFileTarget,
    ) {
    }

    // @param list<string> $relativePaths
    // @param bool $pruneEmptyDirs When true, parent directories that become
    //        empty after a removal are deleted too (modpack semantics). Must
    //        be false for single-file content (mods): the user may keep
    //        other mods in the same directory — removing mods/fabric-api.jar
    //        must never delete the mods directory itself.
    // @return array{deleted: list<string>, missing: list<string>, errors: list<string>}
    public function remove(array $relativePaths, bool $pruneEmptyDirs = true): array
    {
        $deleted = [];
        $missing = [];
        $errors = [];

        foreach ($relativePaths as $relativePath) {
            if (!is_string($relativePath) || trim($relativePath) === '') {
                continue;
            }

            try {
                $relativePath = ServerRelativePath::normalize($relativePath);
            } catch (InvalidArgumentException $exception) {
                $errors[] = 'Invalid owned path: ' . $exception->getMessage();

                continue;
            }

            if (!$this->serverFileTarget->exists($relativePath)) {
                $missing[] = $relativePath;

                continue;
            }

            if ($this->serverFileTarget->isDirectory($relativePath)) {
                $errors[] = "Owned path is a directory: {$relativePath}";

                continue;
            }

            try {
                $this->serverFileTarget->delete($relativePath);

                $deleted[] = $relativePath;

                if ($pruneEmptyDirs) {
                    $this->pruneEmptyParents($relativePath);
                }
            } catch (Throwable $exception) {
                $errors[] = "Unable to remove owned file: {$relativePath}";
            }
        }

        return [
            'deleted' => $deleted,
            'missing' => $missing,
            'errors' => $errors,
        ];
    }

    // Removes the parent chain of a deleted file while every directory stays empty.
    private function pruneEmptyParents(string $relativePath): void
    {
        $parent = $this->normalizedParent($relativePath);

        while ($parent !== null) {
            if (
                !$this->serverFileTarget->isDirectory($parent)
                || !$this->serverFileTarget->isEmptyDirectory($parent)
            ) {
                return;
            }

            try {
                $this->serverFileTarget->removeDirectory($parent);
            } catch (Throwable) {
                return;
            }

            $parent = $this->normalizedParent($parent);
        }
    }

    private function normalizedParent(string $relativePath): ?string
    {
        $parent = dirname($relativePath);

        if ($parent === '.' || $parent === '' || $parent === $relativePath) {
            return null;
        }

        try {
            return ServerRelativePath::normalize($parent);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}