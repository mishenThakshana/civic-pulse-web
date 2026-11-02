<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case Citizen = 'citizen';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Staff => 'Staff',
            self::Citizen => 'Citizen',
        };
    }

    /**
     * Roles that sign in to the web panel rather than the mobile app.
     *
     * @return list<self>
     */
    public static function panelRoles(): array
    {
        return [self::Admin, self::Staff];
    }
}
