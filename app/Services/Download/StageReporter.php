<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Download;

// Optional capability of a downloader: naming the step an operation is on.
// A modpack install is several phases long (fetch the archive, extract it,
// read the manifest, fetch the mods it lists, deploy) and each one can take
// minutes, so consumers need to say what is happening instead of showing one
// anonymous percentage that resets between window re-anchors.
interface StageReporter
{
    // Registers the consumer of stage announcements, replacing any previous.
    // @param null|callable(string $key, int|null $current, int|null $total): void
    public function setStageCallback(?callable $callback): void;

    // Announces the stage now in progress. A stage is finished implicitly when
    // the next one starts. $current/$total carry the stage's own item counts
    // when it walks a counted list (the mods a manifest references).
    public function stage(string $key, ?int $current = null, ?int $total = null): void;
}
