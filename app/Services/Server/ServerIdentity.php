<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Server;

use InvalidArgumentException;

final class ServerIdentity
{
    public function __construct(
        public readonly string $uuid,
    ) {
        if (!self::isValidUuid($uuid)) {
            throw new InvalidArgumentException(
                "Invalid server UUID: {$uuid}"
            );
        }
    }

    public static function fromUuid(string $uuid): self
    {
        return new self($uuid);
    }

    public function equals(self $other): bool
    {
        return $this->uuid === $other->uuid;
    }

    private static function isValidUuid(string $uuid): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid,
        ) === 1;
    }
}
