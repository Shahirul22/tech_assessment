<?php

namespace App\Pool;

/**
 * How a request ended, derived from what was asked and what was granted (FR1.4, FR1.8).
 */
enum AllocationOutcome: string
{
    case Full = 'full';
    case Partial = 'partial';
    case None = 'none';

    public static function fromGrant(int $requested, int $granted): self
    {
        return match (true) {
            $granted <= 0 => self::None,
            $granted >= $requested => self::Full,
            default => self::Partial,
        };
    }
}
