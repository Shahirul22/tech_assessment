<?php

use App\Models\Correction;
use App\Pool\AllocationService;
use App\Pool\Exceptions\CorrectionRefused;
use App\Pool\SubmitAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

it('changes history only when an expired Temporary entry is lowered', function () {
    $world = expiryWorld(100);
    $world['clock']->set(CarbonImmutable::parse(EXPIRY_T)->addHours(30));
    $before = expiryStateAt($world, '+30 hours');

    $result = expiryCorrect($world, 'amend', 2);

    expect($before->remaining)->toBe(100)
        ->and($result->pool->remaining)->toBe(100)
        ->and($result->pool->statuses[$world['ana']->id])->toBe($before->statuses[$world['ana']->id])
        ->and($result->correction->from_amount)->toBe(4)
        ->and(Correction::count())->toBe(1)
        // Inside the window the corrected history holds 2 units, not 4.
        ->and(expiryStateAt($world, '+10 hours')->remaining)->toBe(98)
        ->and(expiryStateAt($world, '+10 hours')->effective->allocations[0]->correctedFrom)->toBe(4);
});

it('accepts a raise of an expired Temporary entry with no free units, as history only', function () {
    $world = expiryWorld(4);
    $ben = operatorNamed('Ben');
    $world['clock']->set(CarbonImmutable::parse(EXPIRY_T)->addHours(25));
    // Ana's 4 are back; Ben takes them, so 0 are free.
    app(AllocationService::class)->submit($world['pool'], $ben, new SubmitAllocation(4, 'consumed', (string) Str::uuid()));
    $world['clock']->set(CarbonImmutable::parse(EXPIRY_T)->addHours(30));

    $result = expiryCorrect($world, 'amend', 9);

    expect($result->pool->remaining)->toBe(0)
        ->and($result->correction->new_amount)->toBe(9)
        ->and(expiryStateAt($world, '+30 hours')->remaining)->toBe(0);
});

it('returns exactly the corrected units at expiry when an active entry is lowered', function () {
    $world = expiryWorld(100);
    $world['clock']->set(CarbonImmutable::parse(EXPIRY_T)->addHour());

    $result = expiryCorrect($world, 'amend', 2);

    expect($result->pool->remaining)->toBe(98)
        ->and(expiryStateAt($world, '+24 hours -1 millisecond')->remaining)->toBe(98)
        // Exactly 2 more, not 4.
        ->and(expiryStateAt($world, '+24 hours')->remaining)->toBe(100)
        ->and(expiryStateAt($world, '+24 hours')->effective->allocations[0]->expiresAt)->toEqual(CarbonImmutable::parse(EXPIRY_T)->addDay());
});

it('returns the raised units at expiry when an active entry is raised into the free units', function () {
    $world = expiryWorld(6);
    $world['clock']->set(CarbonImmutable::parse(EXPIRY_T)->addHour());

    $result = expiryCorrect($world, 'amend', 6);

    expect($result->pool->remaining)->toBe(0)
        ->and(expiryStateAt($world, '+24 hours -1 millisecond')->remaining)->toBe(0)
        ->and(expiryStateAt($world, '+24 hours')->remaining)->toBe(6)
        ->and(expiryStateAt($world, '+24 hours')->effective->allocations[0]->expiresAt)->toEqual(CarbonImmutable::parse(EXPIRY_T)->addDay());
});

it('refuses to raise an active entry beyond the free units', function () {
    $world = expiryWorld(6);
    $world['clock']->set(CarbonImmutable::parse(EXPIRY_T)->addHour());

    expect(fn () => expiryCorrect($world, 'amend', 7))->toThrow(CorrectionRefused::class);
});

it('frees the units of a voided active entry at once and returns nothing at expiry', function () {
    $world = expiryWorld(100);
    $world['clock']->set(CarbonImmutable::parse(EXPIRY_T)->addHour());

    $result = expiryCorrect($world, 'void');

    expect($result->pool->remaining)->toBe(100)
        ->and(expiryStateAt($world, '+23 hours')->remaining)->toBe(100)
        ->and(expiryStateAt($world, '+24 hours')->remaining)->toBe(100)
        ->and(expiryStateAt($world, '+24 hours')->effective->allocations[0]->expiresAt)->toBeNull();
});

it('treats a correction made exactly at expires_at as expired and one millisecond earlier as active', function () {
    $world = expiryWorld(4);
    $expiry = CarbonImmutable::parse(EXPIRY_T)->addDay();

    // One millisecond early the entry still holds its 4 units, so a raise needs free units that are not there.
    $world['clock']->set($expiry->subMillisecond());
    expect(fn () => expiryCorrect($world, 'amend', 5))->toThrow(CorrectionRefused::class);

    // At the expiry instant the units are back and the raise is history only.
    $world['clock']->set($expiry);
    $result = expiryCorrect($world, 'amend', 9);

    expect($result->correction->new_amount)->toBe(9)
        ->and($result->pool->remaining)->toBe(4);
});
