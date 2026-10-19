<?php

use App\Support\Clock;
use App\Support\FrozenClock;
use App\Support\SystemClock;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

it('returns the same instant twice from a frozen clock', function () {
    $clock = new FrozenClock(CarbonImmutable::parse('2026-10-17 10:00:00.123456 UTC'));

    expect($clock->now()->equalTo($clock->now()))->toBeTrue()
        ->and($clock->now()->format('Y-m-d\TH:i:s.u'))->toBe('2026-10-17T10:00:00.123456');
});

it('advances a frozen clock by exactly one millisecond', function () {
    $clock = new FrozenClock(CarbonImmutable::parse('2026-10-17 10:00:00.000000 UTC'));

    $clock->advance('1 ms');

    expect($clock->now()->format('Y-m-d\TH:i:s.u'))->toBe('2026-10-17T10:00:00.001000');
});

it('sets a frozen clock to a new instant', function () {
    $clock = new FrozenClock(CarbonImmutable::parse('2026-10-17 10:00:00 UTC'));

    $clock->set(CarbonImmutable::parse('2026-10-18 00:00:00 UTC'));

    expect($clock->now()->toIso8601String())->toBe('2026-10-18T00:00:00+00:00');
});

it('returns the real time from the system clock', function () {
    $before = CarbonImmutable::now();
    $now = (new SystemClock)->now();
    $after = CarbonImmutable::now();

    expect($now)->toBeInstanceOf(CarbonImmutable::class)
        ->and($now->betweenIncluded($before, $after))->toBeTrue();
});

it('resolves the Clock interface to the system clock from the container', function () {
    expect(app(Clock::class))->toBeInstanceOf(SystemClock::class)
        ->and(app(Clock::class))->toBe(app(Clock::class));
});
