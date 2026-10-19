<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pool;
use App\Pool\SnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LogController extends Controller
{
    private const DEFAULT_LIMIT = 50;

    /**
     * Older log pages for "Show older" (FR3). Newest first; `before` is the cursor of the oldest entry the screen
     * already has: a bare seq ("12") or an expiry position ("12x7").
     */
    public function index(Request $request, Pool $pool, SnapshotService $snapshots): JsonResponse
    {
        $query = Validator::make($request->query(), [
            'before' => ['nullable', 'string', 'regex:/^(\d+x\d+|[1-9]\d*)$/'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])->validate();

        return response()->json($snapshots->logPage(
            $pool,
            (int) ($query['limit'] ?? self::DEFAULT_LIMIT),
            $query['before'] ?? null,
        ));
    }
}
