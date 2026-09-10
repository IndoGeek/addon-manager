<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Installation;

enum PackageLayout: string
{
    case DIRECT = 'direct';
    case OVERRIDES = 'overrides';
}
