<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Operator Tiers
    |--------------------------------------------------------------------------
    |
    | How many hours a temporary allocation is held before its units return to
    | the pool, by the operator's tier when the allocation was logged. The tier
    | names and hours are placeholders until the business confirms them.
    |
    */

    'tiers' => [
        'bronze' => ['window_hours' => 24],
        'silver' => ['window_hours' => 48],
        'gold' => ['window_hours' => 72],
    ],

    /*
    |--------------------------------------------------------------------------
    | Status Levels
    |--------------------------------------------------------------------------
    |
    | Exactly three levels. A holder's status is the highest level whose
    | min_units they reach. The first level starts at 0 and the thresholds must
    | be strictly ascending. The values are placeholders.
    |
    */

    'status_levels' => [
        ['name' => 'standard', 'min_units' => 0],
        ['name' => 'elevated', 'min_units' => 10],
        ['name' => 'high', 'min_units' => 30],
    ],

    /*
    |--------------------------------------------------------------------------
    | Window Scale
    |--------------------------------------------------------------------------
    |
    | A demo switch. Multiplies every tier window so a 24 hour window can be
    | watched end in minutes (0.0013889 makes 24 hours last 2 minutes). Must be
    | above 0 and at most 1. Leave it at 1 for real use.
    |
    */

    'window_scale' => (float) env('POOL_WINDOW_SCALE', 1.0),

];
