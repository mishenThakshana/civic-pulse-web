<?php

namespace App\Support;

/**
 * Formats elapsed time for the panel's stat cards.
 */
class Duration
{
    /**
     * Turns a number of hours into a short label: "45m", "6h", "6.5h",
     * "2d 4h" or "13d", or an em dash when there is nothing to show.
     */
    public static function hours(?float $hours): string
    {
        if ($hours === null) {
            return '—';
        }

        if ($hours < 1) {
            return max(1, (int) round($hours * 60)).'m';
        }

        if ($hours < 24) {
            $rounded = round($hours, 1);

            return ($rounded == (int) $rounded ? (int) $rounded : $rounded).'h';
        }

        $totalHours = (int) round($hours);
        $days = intdiv($totalHours, 24);
        $remainingHours = $totalHours % 24;

        return $remainingHours > 0 ? "{$days}d {$remainingHours}h" : "{$days}d";
    }
}
