<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Citizen = 'citizen';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Citizen => 'Citizen',
        };
    }
}
