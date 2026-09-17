<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Archive;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation\InstallationCancelledException;
use RuntimeException;

// Produces a normalized STORED (uncompressed) zip archive by streaming every entry directly into the destination file.
final class StoredZipWriter
{
    // @var array<int, array{name: string, crc: int, size: int, offset: int, dosTime: int}>
    private array $centralDirectory = [];

    // @param resource $output Seekable destination handle ('wb+').
    public function __construct(
        private $output,
        private readonly ?\Closure $cancelChecker = null,
        private readonly ?\Closure $progressCallback = null,
    ) {
    }

    // Streams the given entry into the archive as one STORED member.
    public function addFileFromStream(
        $stream,
        string $relative,
        int $baseDone = 0,
        ?int $totalBytes = null,
    ): void {
        $this->ensureNotCancelled();

        $offset = (int) ftell($this->output);
        $nameLength = strlen($relative);
        $dosTime = $this->dosDateTime();

        $this->writeLocalHeader($relative, $nameLength, $dosTime);

        $crcHash = hash_init('crc32b');
        $size = 0;

        while (!feof($stream)) {
            $this->ensureNotCancelled();

            $chunk = fread($stream, 262144);

            if ($chunk === false) {
                throw new RuntimeException(
                    'Unable to read a modpack file: ' . $relative
                );
            }

            if ($chunk === '') {
                if (feof($stream)) {
                    break;
                }

                continue;
            }

            $written = fwrite($this->output, $chunk);

            if ($written === false || $written !== strlen($chunk)) {
                throw new RuntimeException(
                    'Unable to write a modpack file into the normalized archive: ' . $relative
                );
            }

            hash_update($crcHash, $chunk);

            $size += strlen($chunk);

            $callback = $this->progressCallback;

            if ($callback !== null) {
                $callback($baseDone + $size, $totalBytes);
            }
        }

        if ($size > 0xFFFFFFFF) {
            throw new RuntimeException(
                'A modpack file in the normalized archive exceeds the maximum supported size.',
            );
        }

        $crc = unpack('N', hash_final($crcHash, true))[1];

        // Patch the placeholder header with the real CRC and sizes.
        fseek($this->output, $offset + 14, SEEK_SET);
        fwrite($this->output, pack('V', $crc));
        fwrite($this->output, pack('V', $size));
        fwrite($this->output, pack('V', $size));
        fseek($this->output, 0, SEEK_END);

        $this->centralDirectory[] = [
            'name' => $relative,
            'crc' => $crc,
            'size' => $size,
            'offset' => $offset,
            'dosTime' => $dosTime,
        ];
    }

    // Appends the central directory and the end-of-central-directory record.
    public function finish(): void
    {
        if (count($this->centralDirectory) > 0xFFFF) {
            throw new RuntimeException(
                'The normalized modpack archive contains too many files.',
            );
        }

        $centralDirectoryOffset = (int) ftell($this->output);

        foreach ($this->centralDirectory as $entry) {
            $nameLength = strlen($entry['name']);

            fwrite($this->output, pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $entry['dosTime'] & 0xFFFF, ($entry['dosTime'] >> 16) & 0xFFFF, $entry['crc'], $entry['size'], $entry['size'], $nameLength, 0, 0, 0, 0, 0100644 << 16, $entry['offset']));
            fwrite($this->output, $entry['name']);
        }

        $centralDirectorySize = (int) ftell($this->output) - $centralDirectoryOffset;

        fwrite($this->output, pack('VvvvvVVv', 0x06054b50, 0, 0, count($this->centralDirectory), count($this->centralDirectory), $centralDirectorySize, $centralDirectoryOffset, 0));
    }

    private function ensureNotCancelled(): void
    {
        $checker = $this->cancelChecker;

        if ($checker !== null && $checker()) {
            throw new InstallationCancelledException(
                'Installation cancelled.'
            );
        }
    }

    private function writeLocalHeader(
        string $name,
        int $nameLength,
        int $dosTime,
    ): void {
        fwrite($this->output, pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime & 0xFFFF, ($dosTime >> 16) & 0xFFFF, 0, 0, 0, $nameLength, 0));
        fwrite($this->output, $name);
    }

    private function dosDateTime(): int
    {
        $now = getdate();

        $date = (($now['year'] - 1980) << 9)
            | ($now['mon'] << 5)
            | $now['mday'];

        $time = ($now['hours'] << 11)
            | ($now['minutes'] << 5)
            | intdiv($now['seconds'], 2);

        return ($date << 16) | $time;
    }
}