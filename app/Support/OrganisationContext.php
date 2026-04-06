<?php

namespace App\Support;

final class OrganisationContext
{
    private static ?int $organisationId = null;

    public static function set(?int $organisationId): void
    {
        self::$organisationId = $organisationId;
    }

    public static function id(): ?int
    {
        return self::$organisationId;
    }
}
