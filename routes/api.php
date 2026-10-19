<?php

use App\Http\Controllers\Api\AllocationController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\NotImplementedController;
use App\Http\Controllers\Api\PoolController;
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

    // Declared now so M2-M4 do not change the shape of the API. M3 implements them.
    Route::middleware('role:supervisor')->group(function () {
        Route::post('pools/{pool}/restocks', NotImplementedController::class);
        Route::put('pools/{pool}/capacity', NotImplementedController::class);
        Route::post('pools/{pool}/allocations/{allocation}/corrections', NotImplementedController::class);
        Route::get('pools/{pool}/audit', NotImplementedController::class);
    });
});
