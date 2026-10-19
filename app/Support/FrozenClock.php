<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A clock that stands still, for tests. It only moves when told to.
 */
class FrozenClock implements Clock
{
    public function __construct(private CarbonImmutable $now) {}

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    public function set(CarbonImmutable $now): void
    {
        $this->now = $now;
    }

    /**
     * @param  string  $interval  Any Carbon interval string, for example '1 ms' or '24 hours'.
     */
    public function advance(string $interval): void
    {
        $this->now = $this->now->add($interval);
    }
}
