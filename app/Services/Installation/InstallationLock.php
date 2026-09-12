<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation;

use InvalidArgumentException;
use RuntimeException;

/**
 * Serializes ModpackInstaller runs for a single server using an atomic
 * filesystem lock directory.
 *
 * A lock is a directory created with mkdir()[0] under the configured
 * temporary root. Only one process can create it, so contending installations
 * for the same server fail fast. Stale locks are reclaimed after
 * $staleTimeoutSeconds based on their last-modified time.
 *
 * [0]: https://www.php.net/mkdir
 */
final class InstallationLock
{
    public function __construct(
        private readonly string $temporaryRoot,
        private readonly int $acquireTimeoutSeconds = 30,
        private readonly int $staleTimeoutSeconds = 900,
    ) {
    }

    public function acquire(string $serverId, string $token): string
    {
        if (!$this->validIdentifier($serverId)) {
            throw new InvalidArgumentException(
                'Invalid server identifier for locking.'
            );
        }

        if ($token === '') {
            throw new InvalidArgumentException(
                'A lock token is required.'
            );
        }

        $root = $this->lockRoot();

        if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
            throw new RuntimeException(
                'Unable to create the installation lock root.'
            );
        }

        $lockPath = $root . '/' . $serverId;

        $deadline = microtime(true) + $this->acquireTimeoutSeconds;

        while (true) {
            if (@mkdir($lockPath, 0750) === true) {
                break;
            }

            if (microtime(true) >= $deadline) {
                throw new InstallationLockedException(
                    'Another installation is already running for this server.'
                );
            }

            if ($this->reclaimIfStale($lockPath)) {
                continue;
            }

            usleep(100_000);
        }

        $tokenPath = $lockPath . '/.token';

        if (
            file_put_contents($tokenPath, $token) === false
        ) {
            @rmdir($lockPath);

            throw new RuntimeException(
                'Unable to write the installation lock token.'
            );
        }

        // Refresh the modification time so the lock is not mistaken for a
        // stale one immediately after being acquired.
        @touch($lockPath);

        return $lockPath;
    }

    public function release(string $lockPath, string $token): void
    {
        $tokenPath = $lockPath . '/.token';

        if (!is_dir($lockPath)) {
            return;
        }

        // Only the holder that recorded its token may release the lock. This
        // prevents a burst of requests from deleting a newer lock.
        $recorded = is_file($tokenPath)
            ? trim((string) @file_get_contents($tokenPath))
            : '';

        if ($recorded === '' || !hash_equals($recorded, $token)) {
            throw new InstallationLockedException(
                'The installation lock is held by a different request.'
            );
        }

        @unlink($tokenPath);
        @rmdir($lockPath);
    }

    private function reclaimIfStale(string $lockPath): bool
    {
        $mtime = @filemtime($lockPath);

        if (
            $mtime === false
            || (time() - $mtime) < $this->staleTimeoutSeconds
        ) {
            return false;
        }

        // Best-effort removal of a lock that has aged past the stale window.
        // If the directory is still in use the removal will fail (non-empty
        // token file) and the caller continues polling.
        @unlink($lockPath . '/.token');
        @rmdir($lockPath);

        return !is_dir($lockPath);
    }

    private function lockRoot(): string
    {
        return rtrim($this->temporaryRoot, DIRECTORY_SEPARATOR)
            . '/locks';
    }

    private function validIdentifier(string $serverId): bool
    {
        return preg_match('/^[A-Z0-9a-z_-]{1,64}$/', $serverId) === 1;
    }
}