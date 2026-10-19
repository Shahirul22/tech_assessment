<?php

use App\Models\Allocation;
use App\Models\Correction;
use App\Models\Pool;
use App\Models\Restock;
use App\Models\User;
use App\Pool\LedgerSequence;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Every seq in a pool's ledger, across restocks, allocations and corrections, in ascending order.
 *
 * @return list<int>
 */
function ledgerSeqs(Pool $pool): array
{
    return collect([Restock::class, Allocation::class, Correction::class])
        ->flatMap(fn (string $model) => $model::where('pool_id', $pool->id)->pluck('seq'))
        ->sort()->values()->map(fn ($seq) => (int) $seq)->all();
}

it('hands out the next number and stores it as the pool version', function () {
    $pool = Pool::factory()->create(['version' => 5]);

    $numbers = DB::transaction(fn () => [app(LedgerSequence::class)->next($pool), app(LedgerSequence::class)->next($pool)]);

    expect($numbers)->toBe([6, 7])
        ->and($pool->fresh()->version)->toBe(7)
        // The model in memory is brought up to date too, so the caller never works from a stale version.
        ->and($pool->version)->toBe(7);
});

it('numbers restocks, allocations and corrections from one sequence with no repeat across tables', function () {
    $this->seed(DatabaseSeeder::class);
    $pool = Pool::findOrFail(1);
    $ana = User::find(1);
    $dana = User::find(4);
    $sequence = app(LedgerSequence::class);
    $now = now()->toImmutable();

    // What an M3 writer must do: take every number from LedgerSequence, inside the pool's transaction.
    DB::transaction(function () use ($pool, $ana, $dana, $sequence, $now) {
        $locked = Pool::query()->whereKey($pool->id)->lockForUpdate()->firstOrFail();

        $allocation = Allocation::factory()->create(['pool_id' => $pool->id, 'seq' => $sequence->next($locked), 'operator_id' => $ana->id]);
        Restock::create(['pool_id' => $pool->id, 'seq' => $sequence->next($locked), 'amount' => 20, 'actor_id' => $dana->id, 'recorded_at' => $now]);
        Correction::create([
            'pool_id' => $pool->id, 'seq' => $sequence->next($locked), 'allocation_id' => $allocation->id, 'supervisor_id' => $dana->id,
            'kind' => 'void', 'new_amount' => null, 'reason' => 'test', 'effective_at' => $now, 'recorded_at' => $now, 'idempotency_key' => 'c-1',
        ]);
    });

    expect(ledgerSeqs($pool))->toBe([1, 2, 3, 4])
        ->and($pool->fresh()->version)->toBe(4);
});

it('keeps seq unique and gapless across the three tables when requests go through the service', function () {
    $this->seed(DatabaseSeeder::class);
    $pool = Pool::findOrFail(1);

    foreach ([1, 2, 3, 1, 2] as $userId) {
        submitAllocation($pool, User::find($userId), 2)->assertCreated();
    }

    $seqs = ledgerSeqs($pool);

    expect($seqs)->toBe(range(1, 6))
        ->and($seqs)->toBe(array_values(array_unique($seqs)))
        ->and($pool->fresh()->version)->toBe(6);
});

it('rolls the number back with the transaction, so a failed write leaves no gap', function () {
    $pool = Pool::factory()->create(['version' => 1]);

    try {
        DB::transaction(function () use ($pool) {
            app(LedgerSequence::class)->next($pool);
            throw new RuntimeException('the insert failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($pool->fresh()->version)->toBe(1);
    expect(DB::transaction(fn () => app(LedgerSequence::class)->next($pool->fresh())))->toBe(2);
});

it('says so when the pool does not exist', function () {
    $ghost = (new Pool)->forceFill(['id' => 999999]);

    DB::transaction(fn () => app(LedgerSequence::class)->next($ghost));
})->throws(ModelNotFoundException::class);

it('lets nothing but LedgerSequence write pools.version in the application code', function () {
    $writes = [
        '/(update|upsert|updateOrCreate|forceFill|fill|create)\s*\(\s*\[[^\]]*[\'"]version[\'"]/s',
        '/->version\s*=(?!=)/',
        '/(increment|decrement)\s*\(\s*[\'"]version/',
        '/set\s+version\b/i',
    ];

    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => $file->getExtension() === 'php' && $file->getFilename() !== 'LedgerSequence.php')
        ->filter(fn ($file) => collect($writes)->contains(fn (string $pattern) => preg_match($pattern, $file->getContents()) === 1))
        ->map(fn ($file) => str_replace(base_path().'/', '', $file->getPathname()))
        ->values()->all();

    expect($offenders)->toBe([], 'These files write pools.version themselves. Take the number from App\Pool\LedgerSequence::next() instead, so no two ledger entries can share a seq.');
});
