<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AllocationResource;
use App\Models\Pool;
use App\Pool\AllocationService;
use App\Pool\Exceptions\IdempotencyKeyConflict;
use App\Pool\Exceptions\PoolBusy;
use App\Pool\SubmitAllocation;
use App\Pool\ViewerView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AllocationController extends Controller
{
    /**
     * Submit a request (FR1). Any client-sent time field is ignored: only `requested` and `type` are read.
     */
    public function store(Request $request, Pool $pool, AllocationService $allocations, ViewerView $viewers): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            throw new ApiException(400, 'missing_idempotency_key', 'Send an Idempotency-Key header (a UUID) with this request.');
        }

        $data = Validator::make(
            ['requested' => $request->input('requested'), 'type' => $request->input('type'), 'idempotency_key' => $key],
            [
                // Strict: a JSON number only. 1.5, "abc" and even "3" are refused. The cap is the database integer.
                'requested' => ['required', 'integer:strict', 'min:1', 'max:2147483647'],
                'type' => ['nullable', 'string', Rule::in(['consumed', 'temporary'])],
                'idempotency_key' => ['required', 'string', 'max:255'],
            ],
        )->validate();

        try {
            $result = $allocations->submit(
                $pool,
                $request->user(),
                new SubmitAllocation($data['requested'], $data['type'] ?? 'consumed', $data['idempotency_key']),
            );
        } catch (IdempotencyKeyConflict) {
            throw new ApiException(409, 'idempotency_key_conflict', 'This Idempotency-Key was already used for a different request. Nothing was recorded.');
        } catch (PoolBusy) {
            throw new ApiException(503, 'pool_busy', 'The pool is busy. Try again.', headers: ['Retry-After' => '1']);
        }

        $response = AllocationResource::make($result->allocation)
            ->additional([
                'pool' => ['version' => $result->pool->version, 'remaining' => $result->pool->remaining],
                'viewer' => $viewers->for($result->pool, $request->user()),
            ])
            ->response()
            ->setStatusCode($result->replayed ? 200 : 201);

        return $result->replayed ? $response->header('Idempotent-Replayed', 'true') : $response;
    }
}
