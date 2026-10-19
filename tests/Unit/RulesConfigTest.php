<?php

use App\Pool\Config\InvalidRulesConfig;
use App\Pool\Config\RulesConfig;
use App\Providers\PoolServiceProvider;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

function validRules(): array
{
    return [
        'tiers' => [
            'bronze' => ['window_hours' => 24],
            'silver' => ['window_hours' => 48],
            'gold' => ['window_hours' => 72],
        ],
        'status_levels' => [
            ['name' => 'standard', 'min_units' => 0],
            ['name' => 'elevated', 'min_units' => 10],
            ['name' => 'high', 'min_units' => 30],
        ],
    ];
}

it('loads a valid config', function () {
    $rules = RulesConfig::fromArray(validRules());

    expect($rules->windowHours('bronze'))->toBe(24)
        ->and($rules->windowHours('silver'))->toBe(48)
        ->and($rules->windowHours('gold'))->toBe(72)
        ->and(array_keys($rules->tiers()))->toBe(['bronze', 'silver', 'gold'])
        ->and($rules->statusLevels())->toHaveCount(3)
        ->and($rules->statusLevels()[2])->toBe(['name' => 'high', 'min_units' => 30]);
});

it('accepts the shipped config/pool_rules.php', function () {
    $rules = RulesConfig::fromArray(config('pool_rules'));

    expect($rules->windowHours('silver'))->toBe(48)
        ->and(array_column($rules->statusLevels(), 'min_units'))->toBe([0, 10, 30]);
});

it('rejects an unknown tier when asking for a window', function () {
    RulesConfig::fromArray(validRules())->windowHours('platinum');
})->throws(InvalidArgumentException::class, 'platinum');

it('rejects an invalid config and names the key', function (callable $mutate, string $key) {
    $rules = validRules();
    $mutate($rules);

    try {
        RulesConfig::fromArray($rules);
    } catch (InvalidRulesConfig $e) {
        expect($e->key)->toBe($key)
            ->and($e->getMessage())->toContain($key);

        return;
    }

    $this->fail("Expected InvalidRulesConfig for [{$key}].");
})->with([
    'tier without window_hours' => [function (array &$r) {
        unset($r['tiers']['silver']['window_hours']);
    }, 'tiers.silver.window_hours'],
    'tier window of zero' => [function (array &$r) {
        $r['tiers']['silver']['window_hours'] = 0;
    }, 'tiers.silver.window_hours'],
    'tier window negative' => [function (array &$r) {
        $r['tiers']['gold']['window_hours'] = -5;
    }, 'tiers.gold.window_hours'],
    'tier window not an integer' => [function (array &$r) {
        $r['tiers']['bronze']['window_hours'] = '24';
    }, 'tiers.bronze.window_hours'],
    'no tiers' => [function (array &$r) {
        $r['tiers'] = [];
    }, 'tiers'],
    'two status levels' => [function (array &$r) {
        array_pop($r['status_levels']);
    }, 'status_levels'],
    'four status levels' => [function (array &$r) {
        $r['status_levels'][] = ['name' => 'extreme', 'min_units' => 50];
    }, 'status_levels'],
    'first level not starting at 0' => [function (array &$r) {
        $r['status_levels'][0]['min_units'] = 5;
    }, 'status_levels.0.min_units'],
    'equal thresholds' => [function (array &$r) {
        $r['status_levels'][1]['min_units'] = 0;
    }, 'status_levels.1.min_units'],
    'descending thresholds' => [function (array &$r) {
        $r['status_levels'][2]['min_units'] = 10;
    }, 'status_levels.2.min_units'],
    'window scale of zero' => [function (array &$r) {
        $r['window_scale'] = 0;
    }, 'window_scale'],
    'window scale above one' => [function (array &$r) {
        $r['window_scale'] = 1.5;
    }, 'window_scale'],
    'window scale not a number' => [function (array &$r) {
        $r['window_scale'] = 'abc';
    }, 'window_scale'],
    'level without a name' => [function (array &$r) {
        $r['status_levels'][1]['name'] = '';
    }, 'status_levels.1.name'],
]);

it('binds RulesConfig as a singleton built from config/pool_rules.php', function () {
    expect(app(RulesConfig::class))->toBeInstanceOf(RulesConfig::class)
        ->and(app(RulesConfig::class))->toBe(app(RulesConfig::class))
        ->and(app(RulesConfig::class)->windowHours('gold'))->toBe(72);
});

it('refuses to start when the config is invalid and names the key', function () {
    config(['pool_rules.tiers.silver.window_hours' => 0]);

    $provider = new PoolServiceProvider(app());
    $provider->register();
    $provider->boot();
})->throws(InvalidRulesConfig::class, 'tiers.silver.window_hours');

it('gives window seconds and the expiry instant for a tier', function () {
    $rules = RulesConfig::fromArray(validRules());
    $at = CarbonImmutable::parse('2026-10-19 10:00:00.250', 'UTC');

    expect($rules->windowSeconds('bronze'))->toBe(86400)
        ->and($rules->windowSeconds('gold'))->toBe(259200)
        ->and($rules->expiresAt('bronze', $at)->equalTo($at->addHours(24)))->toBeTrue();
});

it('maps held units to a status with inclusive thresholds', function (int $held, string $status) {
    expect(RulesConfig::fromArray(validRules())->statusFor($held))->toBe($status);
})->with([[0, 'standard'], [9, 'standard'], [10, 'elevated'], [29, 'elevated'], [30, 'high'], [100, 'high']]);

it('names the lowest status', function () {
    expect(RulesConfig::fromArray(validRules())->lowestStatus())->toBe('standard');
});

it('scales windows with window_scale and treats a missing scale as 1', function () {
    $rules = RulesConfig::fromArray([...validRules(), 'window_scale' => 0.0013889]);

    expect($rules->windowSeconds('bronze'))->toBe(120)
        ->and($rules->windowSeconds('silver'))->toBe(240)
        ->and($rules->windowSeconds('gold'))->toBe(360)
        ->and(RulesConfig::fromArray(validRules())->windowSeconds('bronze'))->toBe(86400);
});

it('keeps the shipped window scale at 1 under test', function () {
    expect(RulesConfig::fromArray(config('pool_rules'))->windowSeconds('bronze'))->toBe(86400);
});
