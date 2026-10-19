<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * M3 plan §5: a correction records the units the entry held right before it, and a restock gets the same
     * idempotency key the other two writers have.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE corrections ADD COLUMN from_amount integer NULL');
        // The table is empty when M3 ships; the backfill keeps the migration safe on any database.
        DB::statement('UPDATE corrections c SET from_amount = a.granted FROM allocations a WHERE a.id = c.allocation_id');
        DB::statement('ALTER TABLE corrections ALTER COLUMN from_amount SET NOT NULL');
        DB::statement('ALTER TABLE corrections ADD CONSTRAINT corrections_from_amount_check CHECK (from_amount >= 0)');

        // NULL for the seeded restock; several NULLs are allowed under a unique index.
        DB::statement('ALTER TABLE restocks ADD COLUMN idempotency_key text NULL');
        DB::statement('CREATE UNIQUE INDEX restocks_pool_id_idempotency_key_unique ON restocks (pool_id, idempotency_key)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX restocks_pool_id_idempotency_key_unique');
        DB::statement('ALTER TABLE restocks DROP COLUMN idempotency_key');

        DB::statement('ALTER TABLE corrections DROP CONSTRAINT corrections_from_amount_check');
        DB::statement('ALTER TABLE corrections DROP COLUMN from_amount');
    }
};
