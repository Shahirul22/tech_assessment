<?php

namespace App\Pool\Audit;

use Carbon\CarbonImmutable;

/**
 * How one correction changed the corrected operator's status (M3 DD8, FR7.2).
 */
final readonly class StatusChange
{
    /**
     * @param  string  $before  The status at the correction moment without the correction.
     * @param  string  $after  The status at the correction moment with it.
     * @param  list<array{label: string, seq: ?int, at: CarbonImmutable, before: string, after: string}>  $points
     *                                                                                                             The correction moment first, then the later points where before and after differ.
     */
    public function __construct(
        public string $before,
        public string $after,
        public array $points,
    ) {}
}
