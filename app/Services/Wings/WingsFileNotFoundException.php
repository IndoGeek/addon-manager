<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Wings;

// Raised when Wings reports that a file or directory does not exist (HTTP 404).
final class WingsFileNotFoundException extends WingsHttpException
{
    public function __construct()
    {
        parent::__construct(404);
    }
}