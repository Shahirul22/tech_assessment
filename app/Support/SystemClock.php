<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
