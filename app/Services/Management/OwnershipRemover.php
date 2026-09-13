<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerRelativePath;
use InvalidArgumentException;
use Throwable;

/**
 * Removes exactly the files an install record owns, and nothing else.
 *
 * Each candidate path is re-validated as a server-relative path immediately
 * before deletion (defense in depth against a corrupted or tampered store).
 * Paths that are missing are tolerated deterministically, and directories are
 * never deleted: only files are removed, so unrelated server structure,
 * worlds, logs and configuration are untouched.
 */
final class OwnershipRemover
{
    public function __construct(
        private readonly ServerFileTarget $serverFileTarget,
    ) {
    }

    /**
     * @param list<string> $relativePaths
     *
     * @return array{deleted: list<string>, missing: list<string>, errors: list<string>}
     */
    public function remove(array $relativePaths): array
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
}