<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Capacity is the sum of restocks (D8). The seed is the restock at seq 1.
        Schema::create('restocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained('pools');
            $table->bigInteger('seq');
            $table->integer('amount');
            // NULL for the seeded initial stock.
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->text('note')->nullable();
            $table->timestampTz('recorded_at', 6);

            $table->unique(['pool_id', 'seq']);
        });

        DB::statement('ALTER TABLE restocks ADD CONSTRAINT restocks_amount_check CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restocks');
    }
};
