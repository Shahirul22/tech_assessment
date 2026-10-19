<?php

namespace Database\Seeders;

use App\Models\Pool;
use App\Models\Restock;
use App\Support\Clock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PoolSeeder extends Seeder
{
    /**
     * Seed the main pool with its 100 units of capacity as a restock event at seq 1 (FR10).
     */
    public function run(Clock $clock): void
    {
        Pool::unguarded(fn () => Pool::firstOrCreate(['id' => 1], ['name' => 'Main warehouse', 'version' => 0]));

        Restock::firstOrCreate(
            ['pool_id' => 1, 'seq' => 1],
            ['amount' => 100, 'actor_id' => null, 'note' => 'Initial stock', 'recorded_at' => $clock->now()],
        );

        // Raise the version to cover seq 1, but never rewind it when the seeder runs on a pool that has activity.
        Pool::whereKey(1)->where('version', '<', 1)->update(['version' => 1]);

        DB::statement("SELECT setval(pg_get_serial_sequence('pools', 'id'), (SELECT MAX(id) FROM pools))");
    }
}
