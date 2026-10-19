<?php

use App\Http\Controllers\Api\AllocationController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\CorrectionController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\PoolController;
use App\Http\Controllers\Api\RestockController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Loaded under /api with the stateless `api` middleware group (see bootstrap/app.php).

// Outside the demo.user group: the role switch needs this list before anyone is chosen.
Route::get('users', [UserController::class, 'index']);

Route::middleware('demo.user')->group(function () {
    // Any role may read the pool.
    Route::get('pools/{pool}', [PoolController::class, 'show']);
    Route::get('pools/{pool}/log', [LogController::class, 'index']);

    Route::middleware('role:operator')->group(function () {
        Route::post('pools/{pool}/allocations', [AllocationController::class, 'store']);
    });

    // Supervisors only. Capacity is raised by restocks alone; there is no way to set or lower it (M3 PQ3).
    Route::middleware('role:supervisor')->group(function () {
        Route::post('pools/{pool}/restocks', [RestockController::class, 'store']);
        // {seq} is the entry number the log shows (M3 DD10); anything that is not a plain positive number is unknown.
        Route::post('pools/{pool}/allocations/{seq}/corrections', [CorrectionController::class, 'store'])->where('seq', '[1-9][0-9]{0,17}');
        Route::get('pools/{pool}/audit', [AuditController::class, 'index']);
    });
});
