<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserSeeder extends Seeder
{
    /**
     * Seed the four demo users (A10): three operators, one per tier, and a supervisor.
     */
    public function run(): void
    {
        $users = [
            ['id' => 1, 'name' => 'Ana', 'role' => 'operator', 'tier' => 'bronze'],
            ['id' => 2, 'name' => 'Ben', 'role' => 'operator', 'tier' => 'silver'],
            ['id' => 3, 'name' => 'Cara', 'role' => 'operator', 'tier' => 'gold'],
            ['id' => 4, 'name' => 'Dana', 'role' => 'supervisor', 'tier' => null],
        ];

        // `id` is not mass assignable, and the fixed ids are the point of this seeder.
        User::unguarded(function () use ($users) {
            foreach ($users as $user) {
                User::updateOrCreate(['id' => $user['id']], $user);
            }
        });

        // Explicit ids do not advance the sequence; without this the next insert would try id 1.
        DB::statement("SELECT setval(pg_get_serial_sequence('users', 'id'), (SELECT MAX(id) FROM users))");
    }
}
