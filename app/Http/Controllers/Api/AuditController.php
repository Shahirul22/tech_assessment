<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pool;
use App\Pool\Audit\AuditService;
use Illuminate\Http\JsonResponse;

class AuditController extends Controller
{
    /**
     * The supervisor's audit view (FR16). Supervisor only, which the route's role middleware enforces.
     */
    public function index(Pool $pool, AuditService $audit): JsonResponse
    {
        return response()->json($audit->build($pool));
    }
}
