<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\RestockResource;
use App\Models\Pool;
use App\Pool\AddRestock;
use App\Pool\Exceptions\IdempotencyKeyConflict;
use App\Pool\Exceptions\PoolBusy;
use App\Pool\RestockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RestockController extends Controller
{
    /**
     * Add units to the pool (FR10). Supervisor only, which the route's role middleware enforces.
     */
    public function store(Request $request, Pool $pool, RestockService $restocks): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            throw new ApiException(400, 'missing_idempotency_key', 'Send an Idempotency-Key header (a UUID) with this request.');
        }

        $data = Validator::make(
            ['amount' => $request->input('amount'), 'note' => $request->input('note'), 'idempotency_key' => $key],
            [
                // Strict: a JSON number only. 1.5, "abc" and even "50" are refused. The cap is the database integer.
                'amount' => ['required', 'integer:strict', 'min:1', 'max:2147483647'],
                'note' => ['nullable', 'string', 'max:255'],
                'idempotency_key' => ['required', 'string', 'max:255'],
            ],
        )->validate();

        try {
            $result = $restocks->add($pool, $request->user(), new AddRestock($data['amount'], $data['note'] ?? null, $data['idempotency_key']));
        } catch (IdempotencyKeyConflict) {
            throw new ApiException(409, 'idempotency_key_conflict', 'This Idempotency-Key was already used for a different request. Nothing was recorded.');
        } catch (PoolBusy) {
            throw new ApiException(503, 'pool_busy', 'The pool is busy. Try again.', headers: ['Retry-After' => '1']);
        }

        $response = RestockResource::make($result->restock)
            ->additional(['pool' => ['version' => $result->pool->version, 'remaining' => $result->pool->remaining]])
            ->response()
            ->setStatusCode($result->replayed ? 200 : 201);

        return $result->replayed ? $response->header('Idempotent-Replayed', 'true') : $response;
    }
}
