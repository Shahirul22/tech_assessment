<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CorrectionResource;
use App\Models\Pool;
use App\Pool\ApplyCorrection;
use App\Pool\CorrectionService;
use App\Pool\Exceptions\AllocationVoided;
use App\Pool\Exceptions\CorrectionRefused;
use App\Pool\Exceptions\IdempotencyKeyConflict;
use App\Pool\Exceptions\PoolBusy;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CorrectionController extends Controller
{
    /**
     * Amend or void a past allocation (FR6). Supervisor only, which the route's role middleware enforces.
     * `{seq}` is the entry number shown in the log: the allocation's seq in this pool (M3 DD10).
     */
    public function store(Request $request, Pool $pool, int $seq, CorrectionService $corrections): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            throw new ApiException(400, 'missing_idempotency_key', 'Send an Idempotency-Key header (a UUID) with this request.');
        }

        $data = Validator::make(
            // Only the fields that were sent: an absent amount must not be checked as an integer on a void.
            $request->only(['kind', 'amount', 'reason']) + ['idempotency_key' => $key],
            [
                'kind' => ['required', 'string', Rule::in(['amend', 'void'])],
                // Strict: a JSON number only. 0 is not an amount (use a void), and a void takes none.
                'amount' => ['nullable', 'required_if:kind,amend', 'prohibited_if:kind,void', 'integer:strict', 'min:1', 'max:2147483647'],
                'reason' => ['required', 'string', 'max:500', function (string $attribute, mixed $value, Closure $fail) {
                    if (trim((string) $value) === '') {
                        $fail('The reason must not be blank.');
                    }
                }],
                'idempotency_key' => ['required', 'string', 'max:255'],
            ],
        )->validate();

        try {
            $result = $corrections->apply($pool, $request->user(), new ApplyCorrection(
                $seq,
                $data['kind'],
                $data['kind'] === 'void' ? null : $data['amount'],
                trim($data['reason']),
                $data['idempotency_key'],
            ));
        } catch (CorrectionRefused $e) {
            throw new ApiException(
                422,
                'insufficient_free_units',
                $e->getMessage(),
                ['needed' => $e->needed, 'free' => $e->free, 'allocation_seq' => $e->allocationSeq],
            );
        } catch (AllocationVoided $e) {
            throw new ApiException(422, 'allocation_voided', $e->getMessage(), ['allocation_seq' => $e->allocationSeq]);
        } catch (IdempotencyKeyConflict) {
            throw new ApiException(409, 'idempotency_key_conflict', 'This Idempotency-Key was already used for a different request. Nothing was recorded.');
        } catch (PoolBusy) {
            throw new ApiException(503, 'pool_busy', 'The pool is busy. Try again.', headers: ['Retry-After' => '1']);
        }

        $response = CorrectionResource::make($result->correction)
            ->additional(['pool' => ['version' => $result->pool->version, 'remaining' => $result->pool->remaining]])
            ->response()
            ->setStatusCode($result->replayed ? 200 : 201);

        return $result->replayed ? $response->header('Idempotent-Replayed', 'true') : $response;
    }
}
