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
        Schema::create('allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained('pools');
            $table->bigInteger('seq');
            $table->foreignId('operator_id')->constrained('users');
            $table->text('type')->default('consumed');
            $table->integer('requested');
            // 0 is recorded on purpose: a stale request still leaves a log entry.
            $table->integer('granted');
            $table->text('tier_at_log');
            // Server receipt time, never client-supplied.
            $table->timestampTz('effective_at', 6);
            $table->timestampTz('recorded_at', 6);
            $table->text('idempotency_key');

            $table->unique(['pool_id', 'seq']);
            $table->unique(['pool_id', 'idempotency_key']);
            $table->index(['pool_id', 'effective_at']);
            $table->index('operator_id');
        });

        DB::statement("ALTER TABLE allocations ADD CONSTRAINT allocations_type_check CHECK (type IN ('consumed', 'temporary'))");
        DB::statement('ALTER TABLE allocations ADD CONSTRAINT allocations_requested_check CHECK (requested >= 1)');
        DB::statement('ALTER TABLE allocations ADD CONSTRAINT allocations_granted_check CHECK (granted >= 0 AND granted <= requested)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('allocations');
    }
};
