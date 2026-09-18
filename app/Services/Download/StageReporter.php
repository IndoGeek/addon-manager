<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download;

// Optional capability of a downloader: naming the step an operation is on.
interface StageReporter
{
    // Registers the consumer of stage announcements, replacing any previous.
    public function setStageCallback(?callable $callback): void;

    // Announces the stage now in progress.
    public function stage(string $key, ?int $current = null, ?int $total = null): void;
}
