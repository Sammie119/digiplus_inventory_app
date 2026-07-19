<?php

namespace App\Support;

class CurrentTenant
{
    protected static ?int $id = null;

    public static function id(): ?int
    {
        return static::$id;
    }

    public static function set(?int $tenantId): void
    {
        static::$id = $tenantId;
    }

    public static function clear(): void
    {
        static::$id = null;
    }

    public static function check(): bool
    {
        return static::$id !== null;
    }
}
