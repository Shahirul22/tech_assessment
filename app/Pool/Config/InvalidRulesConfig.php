<?php

namespace App\Pool\Config;

use RuntimeException;

class InvalidRulesConfig extends RuntimeException
{
    /**
     * @param  string  $key  The dotted config key that is wrong, for example "tiers.silver.window_hours".
     */
    public function __construct(public readonly string $key, string $reason)
    {
        parent::__construct("Invalid pool rules config at [{$key}]: {$reason}");
    }
}
