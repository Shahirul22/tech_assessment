<?php

use App\Models\Pool;
use App\Models\Restock;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('seeds the four users with fixed ids', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::orderBy('id')->get(['id', 'name', 'role', 'tier'])->map->only(['id', 'name', 'role', 'tier'])->all())
        ->toBe([
            ['id' => 1, 'name' => 'Ana', 'role' => 'operator', 'tier' => 'bronze'],
            ['id' => 2, 'name' => 'Ben', 'role' => 'operator', 'tier' => 'silver'],
            ['id' => 3, 'name' => 'Cara', 'role' => 'operator', 'tier' => 'gold'],
            ['id' => 4, 'name' => 'Dana', 'role' => 'supervisor', 'tier' => null],
        ]);
});

it('seeds one pool with 100 units as a restock event at seq 1', function () {
    $this->seed(DatabaseSeeder::class);

    $pool = Pool::findOrFail(1);
    $restock = Restock::sole();

    expect($pool->name)->toBe('Main warehouse')
        ->and($pool->version)->toBe(1)
        ->and($restock->pool_id)->toBe(1)
        ->and($restock->seq)->toBe(1)
        ->and($restock->amount)->toBe(100)
        ->and($restock->actor_id)->toBeNull()
        ->and($restock->note)->toBe('Initial stock');
});

it('changes nothing when run twice', function () {
    $this->seed(DatabaseSeeder::class);
    $first = [User::count(), Pool::count(), Restock::count(), Restock::sole()->recorded_at->toIso8601String()];

    $this->seed(DatabaseSeeder::class);

    expect([User::count(), Pool::count(), Restock::count(), Restock::sole()->recorded_at->toIso8601String()])->toBe($first);
});

it('does not rewind the pool version when seeded again after activity', function () {
    $this->seed(DatabaseSeeder::class);
    Pool::whereKey(1)->update(['version' => 7]);

    $this->seed(DatabaseSeeder::class);

    expect(Pool::findOrFail(1)->version)->toBe(7);
});

it('leaves the id sequences ready for new rows', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::factory()->operator()->create()->id)->toBe(5)
        ->and(Pool::factory()->create()->id)->toBe(2);
});
