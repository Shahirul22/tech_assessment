<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pool;
use App\Pool\SnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PoolController extends Controller
{
    /**
     * The snapshot: initial load, gap recovery, reconnect and polling (FR2, FR3, FR11). Any role.
     */
    public function show(Request $request, Pool $pool, SnapshotService $snapshots): JsonResponse
    {
        return response()->json($snapshots->build($pool, $request->user()));
    }
}
