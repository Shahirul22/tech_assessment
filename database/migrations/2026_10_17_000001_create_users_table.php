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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->text('name');
            $table->text('role');
            // 'bronze' | 'silver' | 'gold', validated against config/pool_rules.php, not a DB enum.
            $table->text('tier')->nullable();
            $table->timestampsTz(6);
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('operator', 'supervisor'))");
        // An operator has a tier, a supervisor does not.
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_tier_check CHECK ((role = 'operator') = (tier IS NOT NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
