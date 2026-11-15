<?php

namespace App\Enums;

/**
 * The ten issue categories the AI screening service is trained on
 * (ai/app/schemas/report.py IssueCategory). The backed value is the
 * category slug stored in the categories table, so a prediction from the
 * service maps to a database category through aiKey().
 */
enum IssueCategory: string
{
    case Pothole = 'pothole';
    case RoadDamage = 'road-damage';
    case Garbage = 'garbage';
    case Flooding = 'flooding';
    case BlockedDrain = 'blocked-drain';
    case BrokenStreetLight = 'broken-street-light';
    case FallenTree = 'fallen-tree';
    case DamagedSign = 'damaged-sign';
    case DamagedPublicProperty = 'damaged-public-property';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Pothole => 'Pothole',
            self::RoadDamage => 'Road damage',
            self::Garbage => 'Garbage',
            self::Flooding => 'Flooding',
            self::BlockedDrain => 'Blocked drain',
            self::BrokenStreetLight => 'Broken street light',
            self::FallenTree => 'Fallen tree',
            self::DamagedSign => 'Damaged sign',
            self::DamagedPublicProperty => 'Damaged public property',
            self::Other => 'Other',
        };
    }

    /**
     * The label the AI service uses for this category, e.g. "blocked_drain".
     */
    public function aiKey(): string
    {
        return str_replace('-', '_', $this->value);
    }

    public static function fromAiKey(string $key): ?self
    {
        return self::tryFrom(str_replace('_', '-', strtolower(trim($key))));
    }

    /**
     * Categories used before the list was aligned with the AI service, mapped
     * to the issue category their reports move to.
     *
     * @return array<string, self>
     */
    public static function legacy(): array
    {
        return [
            'drainage' => self::BlockedDrain,
            'lighting' => self::BrokenStreetLight,
            'waste-management' => self::Garbage,
            'public-safety' => self::Other,
            'water-supply' => self::Other,
        ];
    }
}
