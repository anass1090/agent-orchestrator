<?php

namespace App\Support;

use Carbon\CarbonInterface;

class Duration
{
    /**
     * Human duration: 3.2s, 4m07s, 1h12m.
     */
    public static function format(?int $milliseconds): string
    {
        if ($milliseconds === null) {
            return '–';
        }

        $seconds = intdiv($milliseconds, 1000);

        return match (true) {
            $seconds >= 3600 => sprintf('%dh%02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)),
            $seconds >= 60 => sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60),
            default => sprintf('%.1fs', $milliseconds / 1000),
        };
    }

    /**
     * Offset of a moment from the start of a run: +00:47, +12:03, +1:04:10.
     */
    public static function offset(?CarbonInterface $start, CarbonInterface $at): string
    {
        if ($start === null) {
            return $at->format('H:i:s');
        }

        $seconds = max(0, (int) $start->diffInSeconds($at));

        return $seconds >= 3600
            ? sprintf('+%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('+%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
