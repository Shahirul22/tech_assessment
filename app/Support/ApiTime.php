<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The one timestamp format the API uses: ISO-8601, UTC, microseconds.
 */
final class ApiTime
{
    public static function format(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
