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
        // Empty in M1. M3 adds the behaviour.
        Schema::create('corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained('pools');
            $table->bigInteger('seq');
            $table->foreignId('allocation_id')->constrained('allocations');
            $table->foreignId('supervisor_id')->constrained('users');
            $table->text('kind');
            // amend: the new granted units (>= 1). void: NULL.
            $table->integer('new_amount')->nullable();
            $table->text('reason');
            $table->timestampTz('effective_at', 6);
            $table->timestampTz('recorded_at', 6);
            $table->text('idempotency_key');

            $table->unique(['pool_id', 'seq']);
            $table->unique(['pool_id', 'idempotency_key']);
        });

        DB::statement("ALTER TABLE corrections ADD CONSTRAINT corrections_kind_check CHECK (kind IN ('amend', 'void'))");
        // Plan §5 writes this without IS NOT NULL on the amend branch, but then an amend with a NULL new_amount
        // evaluates to NULL, which a CHECK treats as passing. The extra test makes it mean what the plan says.
        DB::statement("ALTER TABLE corrections ADD CONSTRAINT corrections_kind_new_amount_check CHECK ((kind = 'amend' AND new_amount IS NOT NULL AND new_amount >= 1) OR (kind = 'void' AND new_amount IS NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corrections');
    }
};
