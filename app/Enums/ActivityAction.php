<?php

namespace App\Enums;

enum ActivityAction: string
{
    case SignedIn = 'auth.signed_in';
    case SignedOut = 'auth.signed_out';

    case CitizenUpdated = 'citizen.updated';
    case CitizenApproved = 'citizen.approved';
    case CitizenRejected = 'citizen.rejected';
    case CitizenActivated = 'citizen.activated';
    case CitizenDeactivated = 'citizen.deactivated';
    case CitizenDeleted = 'citizen.deleted';

    case ReportUpdated = 'report.updated';

    case CategoryCreated = 'category.created';
    case CategoryUpdated = 'category.updated';
    case CategoryDeleted = 'category.deleted';

    case DepartmentCreated = 'department.created';
    case DepartmentUpdated = 'department.updated';
    case DepartmentDeleted = 'department.deleted';

    case StaffCreated = 'staff.created';
    case StaffUpdated = 'staff.updated';
    case StaffActivated = 'staff.activated';
    case StaffDeactivated = 'staff.deactivated';
    case StaffDeleted = 'staff.deleted';

    public function label(): string
    {
        return match ($this) {
            self::SignedIn => 'Signed in',
            self::SignedOut => 'Signed out',
            self::CitizenUpdated => 'Citizen updated',
            self::CitizenApproved => 'Citizen approved',
            self::CitizenRejected => 'Citizen rejected',
            self::CitizenActivated => 'Citizen activated',
            self::CitizenDeactivated => 'Citizen deactivated',
            self::CitizenDeleted => 'Citizen deleted',
            self::ReportUpdated => 'Report updated',
            self::CategoryCreated => 'Category added',
            self::CategoryUpdated => 'Category updated',
            self::CategoryDeleted => 'Category deleted',
            self::DepartmentCreated => 'Department added',
            self::DepartmentUpdated => 'Department updated',
            self::DepartmentDeleted => 'Department deleted',
            self::StaffCreated => 'Staff added',
            self::StaffUpdated => 'Staff updated',
            self::StaffActivated => 'Staff activated',
            self::StaffDeactivated => 'Staff deactivated',
            self::StaffDeleted => 'Staff deleted',
        };
    }

    /**
     * The badge modifier used to colour this action in the activity log.
     */
    public function badge(): string
    {
        return match ($this) {
            self::CitizenApproved, self::CitizenActivated, self::StaffActivated,
            self::CategoryCreated, self::DepartmentCreated, self::StaffCreated => 'green',
            self::CitizenRejected, self::CitizenDeleted, self::CitizenDeactivated,
            self::CategoryDeleted, self::DepartmentDeleted, self::StaffDeleted, self::StaffDeactivated => 'red',
            self::ReportUpdated => 'blue',
            self::CitizenUpdated, self::CategoryUpdated, self::DepartmentUpdated, self::StaffUpdated => 'amber',
            self::SignedIn, self::SignedOut => '',
        };
    }
}
