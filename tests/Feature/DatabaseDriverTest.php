<?php

use Illuminate\Support\Facades\DB;

it('runs on PostgreSQL against the app_test database', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('app_test');
});
