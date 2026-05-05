<?php

namespace App\Services;

class FormatService
{
    /**
     * Format a duration in milliseconds to a human-readable string.
     *
     * Breaks the value down into minutes, seconds, and milliseconds,
     * omitting zero-valued segments for brevity (e.g. "2m 30s 150ms").
     *
     * @param  float  $input  Duration in milliseconds.
     */
    public function formatMs(float $input): string
    {
        $ms = $input % 1000;
        $dateString = $ms.'ms';
        $input = floor($input / 1000);
        $seconds = $input % 60;
        $input = floor($input / 60);
        $minutes = $input % 60;
        $hours = floor($input / 60);
        if ($seconds > 0) {
            $dateString = $seconds.'s '.$dateString;
        }
        if ($minutes > 0) {
            $dateString = $minutes.'m '.$dateString;
        }
        if ($hours > 0) {
            $dateString = $hours.'h '.$dateString;
        }

        return $dateString;
    }

    /**
     * Format a byte count to a human-readable size string.
     *
     * Automatically selects the most appropriate unit (B, KB, MB, GB, TB)
     * based on magnitude and rounds to the given precision.
     *
     * @param  int|float  $bytes  The number of bytes.
     * @param  int  $precision  Decimal places to round to.
     */
    public function formatBytes($bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);
        // this will also work in place of the above line:
        // $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision).$units[$pow];
    }
}
