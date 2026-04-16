<?php

namespace App\Enums;

enum ApplicationRole: string
{
    case SuperAdmin = 'superadmin';
    case Admin = 'admin';
    case User = 'user';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }
}
