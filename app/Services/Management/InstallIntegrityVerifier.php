<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server\ServerFileTarget;

/**
 * Verifies that every file a modpack owns is still present on a server.
 *
 * Used by the installed-modpacks list so a modpack whose files were removed
 * outside of the extension (e.g. from the Files tab) either drops out of the
 * list entirely or is flagged as degraded with the exact set of files that can
 * be restored.
 */
final class InstallIntegrityVerifier
{
    /**
     * @return list<string> owned relative paths that no longer exist
     */
    public function missingFiles(
        InstallRecord $record,
        ServerFileTarget $target,
    ): array {
        $missing = [];

        foreach ($record->ownedFiles() as $relativePath) {
            if (!$target->exists($relativePath)) {
                $missing[] = $relativePath;
            }
        }

        return $missing;
    }

    /**
     * Whether every single owned file is gone, meaning the modpack has been
     * effectively removed out-of-band.
     */
    public function isCompletelyGone(
        InstallRecord $record,
        ServerFileTarget $target,
    ): bool {
        $owned = $record->ownedFiles();

        if ($owned === []) {
            return false;
        }

        return count($this->missingFiles($record, $target)) === count($owned);
    }
}