<?php

use App\Pool\Calculation\AllocationEntry;
use App\Pool\Calculation\CorrectionEntry;
use App\Pool\Calculation\Ledger;
use App\Pool\Calculation\PoolCalculator;
use App\Pool\Calculation\PoolState;
use App\Pool\Calculation\RestockEntry;
use App\Pool\Calculation\Steps\ApplyCorrections;
use App\Pool\Calculation\Steps\ApplyExpiry;
use App\Pool\Calculation\Steps\ComputeStatus;
use App\Pool\Config\RulesConfig;
use App\Support\FrozenClock;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

const CALC_NOW = '2026-10-17 10:00:00.000000 UTC';

function calcClock(): FrozenClock
{
    return new FrozenClock(CarbonImmutable::parse(CALC_NOW));
}

function calculator(
    ?ApplyCorrections $corrections = null,
    ?ApplyExpiry $expiry = null,
    ?ComputeStatus $status = null,
    ?FrozenClock $clock = null,
): PoolCalculator {
    return new PoolCalculator(
        $clock ?? calcClock(),
        app(RulesConfig::class),
        $corrections ?? new ApplyCorrections,
        $expiry ?? new ApplyExpiry,
        $status ?? new ComputeStatus,
    );
}

function restock(int $seq, int $amount): RestockEntry
{
    return new RestockEntry(id: $seq, seq: $seq, amount: $amount);
}

function grant(int $seq, int $units, string $type = 'consumed', bool $active = true, int $operatorId = 1): AllocationEntry
{
    return new AllocationEntry(
        id: $seq,
        seq: $seq,
        operatorId: $operatorId,
        type: $type,
        units: $units,
        tierAtLog: 'bronze',
        effectiveAt: CarbonImmutable::parse(CALC_NOW),
        active: $active,
    );
}

it('computes remaining as restocks minus granted units', function () {
    $ledger = new Ledger([restock(1, 100)], [grant(2, 3), grant(3, 3), grant(4, 4)]);

    $state = calculator()->calculate($ledger);

    expect($state)->toBeInstanceOf(PoolState::class)
        ->and($state->remaining)->toBe(90)
        ->and($state->version)->toBe(4);
});

it('sums every restock', function () {
    $ledger = new Ledger([restock(1, 100), restock(3, 50)], [grant(2, 30)]);

    expect(calculator()->calculate($ledger)->remaining)->toBe(120);
});

it('counts temporary entries as held while they are active', function () {
    $ledger = new Ledger([restock(1, 100)], [grant(2, 10, 'temporary'), grant(3, 5, 'consumed')]);

    expect(calculator()->calculate($ledger)->remaining)->toBe(85);
});

it('gives back the units of an expired temporary entry but never a consumed one', function () {
    $ledger = new Ledger([restock(1, 100)], [
        grant(2, 10, 'temporary'),
        grant(3, 5, 'consumed'),
    ]);

    expect(calculator()->calculateAt($ledger, CarbonImmutable::parse(CALC_NOW)->addHours(24))->remaining)->toBe(95);
});

it('counts a zero grant as nothing held, but it still takes its place in the version', function () {
    $ledger = new Ledger([restock(1, 10)], [grant(2, 10), grant(3, 0)]);

    $state = calculator()->calculate($ledger);

    expect($state->remaining)->toBe(0)
        ->and($state->version)->toBe(3);
});

it('takes the version from the highest seq across restocks, allocations and corrections', function () {
    $ledger = new Ledger(
        [restock(1, 100)],
        [grant(3, 2), grant(2, 2)],
        [new CorrectionEntry(id: 1, seq: 4, allocationId: 3, kind: 'void', newAmount: null)],
    );

    expect(calculator()->calculate($ledger)->version)->toBe(4);
});

it('reads an empty ledger as nothing remaining at version 0', function () {
    $state = calculator()->calculate(new Ledger([], []));

    expect($state->remaining)->toBe(0)
        ->and($state->version)->toBe(0)
        ->and($state->statuses)->toBe([]);
});

it('runs corrections, then expiry, then sums, and passes the clock and rules to the steps', function () {
    $order = [];
    $seen = [];

    $corrections = new class($order) extends ApplyCorrections
    {
        public function __construct(private array &$order) {}

        public function apply(Ledger $ledger): Ledger
        {
            $this->order[] = 'corrections';

            // Drop allocation seq 3, as a void would.
            return new Ledger($ledger->restocks, array_values(array_filter($ledger->allocations, fn ($a) => $a->seq !== 3)));
        }
    };

    $expiry = new class($order, $seen) extends ApplyExpiry
    {
        public function __construct(private array &$order, private array &$seen) {}

        public function apply(Ledger $ledger, CarbonImmutable $now, RulesConfig $rules): Ledger
        {
            $this->order[] = 'expiry';
            $this->seen = [$now->toIso8601String(), $rules::class, count($ledger->allocations)];

            // Expire the temporary entry.
            return new Ledger($ledger->restocks, array_map(
                fn (AllocationEntry $a) => $a->type === 'temporary'
                    ? new AllocationEntry($a->id, $a->seq, $a->operatorId, $a->type, $a->units, $a->tierAtLog, $a->effectiveAt, active: false)
                    : $a,
                $ledger->allocations,
            ));
        }
    };

    $ledger = new Ledger([restock(1, 100)], [grant(2, 10, 'temporary'), grant(3, 40), grant(4, 5)]);

    $state = calculator($corrections, $expiry)->calculate($ledger);

    // 100 - 5 (seq 4 consumed). Seq 3 was dropped by corrections; seq 2 expired.
    expect($state->remaining)->toBe(95)
        ->and($order)->toBe(['corrections', 'expiry'])
        // Expiry saw the corrected ledger (2 allocations left), the clock's now, and the real rules.
        ->and($seen)->toBe(['2026-10-17T10:00:00+00:00', RulesConfig::class, 2]);
});

it('takes the version from the original ledger, not from what a step returns', function () {
    $corrections = new class extends ApplyCorrections
    {
        public function apply(Ledger $ledger): Ledger
        {
            return new Ledger($ledger->restocks, []);
        }
    };

    $ledger = new Ledger([restock(1, 100)], [grant(2, 10)]);

    expect(calculator($corrections)->calculate($ledger)->version)->toBe(2);
});

it('takes statuses from ComputeStatus', function () {
    $status = new class extends ComputeStatus
    {
        public function compute(Ledger $ledger, CarbonImmutable $now, RulesConfig $rules): array
        {
            return [1 => 'high', 2 => 'standard'];
        }
    };

    $state = calculator(status: $status)->calculate(new Ledger([restock(1, 100)], [grant(2, 40)]));

    expect($state->statuses)->toBe([1 => 'high', 2 => 'standard']);
});

it('leaves the ledger alone in the identity corrections step', function () {
    $ledger = new Ledger([restock(1, 100)], [grant(2, 3)]);

    expect((new ApplyCorrections)->apply($ledger))->toBe($ledger);
});

it('grants the smaller of requested and remaining', function (int $requested, int $remaining, int $expected) {
    expect(calculator()->grantFor(new PoolState($remaining, 1), $requested))->toBe($expected);
})->with([
    'equal to remaining' => [10, 10, 10],
    'over remaining (10 of 11)' => [11, 10, 10],
    'nothing remaining' => [3, 0, 0],
    'under remaining' => [4, 10, 4],
    'one left, one asked' => [1, 1, 1],
    'never negative for a bad request' => [-3, 10, 0],
]);

it('calculates at an explicit instant and returns the effective ledger', function () {
    $marker = new Ledger([restock(1, 100)], [grant(2, 10, 'temporary', active: false)]);
    $seen = null;

    $expiry = new class($marker, $seen) extends ApplyExpiry
    {
        public function __construct(private Ledger $result, private ?string &$seen) {}

        public function apply(Ledger $ledger, CarbonImmutable $now, RulesConfig $rules): Ledger
        {
            $this->seen = $now->toIso8601String();

            return $this->result;
        }
    };

    $ledger = new Ledger([restock(1, 100)], [grant(2, 10, 'temporary')]);
    $at = CarbonImmutable::parse('2026-12-01 08:30:00 UTC');

    $state = calculator(expiry: $expiry)->calculateAt($ledger, $at);

    expect($seen)->toBe('2026-12-01T08:30:00+00:00')
        ->and($state->effective)->toBe($marker)
        ->and($state->remaining)->toBe(100);

    $plain = new Ledger([restock(1, 100)], [grant(2, 10)]);

    expect(calculator()->calculateAt($plain, $at)->remaining)->toBe(calculator()->calculate($plain)->remaining);
});
