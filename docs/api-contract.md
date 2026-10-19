# API contract: Shared pool

The contract every milestone (M1–M4) builds against. Source of truth: [plan.md](../workflow/slices/m1-shared-pool/plan.md) §6. Endpoints marked **501** are declared now and answer `501 not_implemented` until their slice arrives, so the shape of the API does not change later.

## Conventions

- JSON under `/api`. All times are ISO-8601 UTC with microseconds, for example `2026-10-17T10:00:00.123456Z`.
- **Acting user:** header `X-Demo-User: <user id>` (a seeded user; no password, this is a role switch and not security). Missing, malformed or unknown → `401 unknown_user`. `GET /api/users` needs no header.
- **Roles:** `operator` and `supervisor`. A wrong role → `403 forbidden_role` with `details.required_role`. Supervisors cannot submit allocations.
- **Idempotency:** requests that create a ledger entry carry `Idempotency-Key: <uuid>`; the key is unique per pool.
- **Order:** every stored ledger entry takes the next `seq` of its pool, and the pool's `version` is the highest `seq`. Clients use it to order live updates. Restocks, allocations and corrections share one sequence per pool, so a writer must take its number from `App\Pool\LedgerSequence::next()` inside the pool's locked transaction and never compute `version + 1` itself.

### Error body

Every error on an `/api/*` route has this shape. `details` is always a JSON object (`{}` when empty).

```json
{"error": {"code": "<snake_case>", "message": "<text>", "details": {}}}
```

| Status | `code` | When | `details` |
|---|---|---|---|
| 400 | `missing_idempotency_key` | `POST …/allocations` without `Idempotency-Key` | `{}` |
| 401 | `unknown_user` | `X-Demo-User` missing, malformed or not a seeded user | `{}` |
| 403 | `forbidden_role` | the user's role may not do this | `{"required_role": "supervisor"}` |
| 404 | `not_found` | unknown pool, or unknown path | `{}` |
| 405 | `method_not_allowed` | wrong HTTP method for the path | `{}` |
| 409 | `idempotency_key_conflict` | same key, different operator, amount or type; nothing recorded | `{}` |
| 422 | `validation_failed` | bad input | `{"fields": {"requested": ["…"]}}` |
| 501 | `not_implemented` | a stub endpoint | `{"milestone": "M3"}` |
| 503 | `pool_busy` | the pool lock wait exceeded 5 s; header `Retry-After: 1` | `{}` |
| 500 | `server_error` | unexpected failure; no internals are returned | `{}` |

## Log entry shapes

The log is one list of all three ledger tables (and M2's derived expiries), newest first. All four kinds are part of the contract now; M1 emits `allocation` and `restock`.

```json
{"seq":12,"kind":"allocation","at":"2026-10-17T10:00:00.123456Z",
 "operator":{"id":1,"name":"Ana"},"type":"consumed","requested":3,"granted":3,"outcome":"full"}

{"seq":1,"kind":"restock","at":"2026-10-17T09:00:00.000000Z","actor":null,"amount":100,"note":"Initial stock"}

{"seq":20,"kind":"correction","at":"2026-10-17T11:00:00.000000Z","supervisor":{"id":4,"name":"Dana"},
 "allocation_seq":12,"change":{"from":3,"to":2},"reason":"…"}

{"seq":null,"kind":"expiry","at":"2026-10-18T10:00:00.123456Z","allocation_seq":12,"units":3}
```

| Kind | Emitted by | Notes |
|---|---|---|
| `allocation` | M1 | `outcome` is `full` (granted = requested), `partial` (0 < granted < requested) or `none` (granted = 0). A zero grant is stored and shown. |
| `restock` | M1 (seed), M3 | `actor` is `null` for the seeded initial stock. |
| `correction` | M3 | `change.to` is `null` for a void. |
| `expiry` | M2 | Derived, never stored, so `seq` is `null`. |

## Endpoints

### POST /api/pools/{pool}/allocations

Submit a request. Role: `operator`.

**Request**

- Headers: `X-Demo-User`, `Idempotency-Key: <uuid>` (required).
- Body: `{"requested": 3, "type": "consumed"}`. `requested` is a whole number ≥ 1. `type` is `consumed` or `temporary` and is optional (default `consumed`). Any client-sent time field is ignored: `effective_at` is the server's receipt time.

**Response `201`** for a new entry, or **`200` with header `Idempotent-Replayed: true`** when the same key and payload were already stored (the stored result is returned):

```json
{"allocation":{"id":57,"seq":12,"operator":{"id":1,"name":"Ana"},"type":"consumed",
  "requested":3,"granted":3,"outcome":"full","tier_at_log":"bronze",
  "effective_at":"2026-10-17T10:00:00.123456Z","recorded_at":"2026-10-17T10:00:00.130000Z"},
 "pool":{"version":12,"remaining":88}}
```

The grant is `min(requested, remaining)`, so a partial grant or a zero grant is still a `201` with `outcome` `partial` or `none`.

**Errors:** `400 missing_idempotency_key` · `401 unknown_user` · `403 forbidden_role` · `404 not_found` (pool) · `409 idempotency_key_conflict` · `422 validation_failed` (`requested` not an integer ≥ 1, or unknown `type`; `details.fields`) · `503 pool_busy`.

### GET /api/pools/{pool}

The snapshot: initial load, gap recovery, reconnect and polling. Any role. Reads one consistent view and takes no lock.

**Response `200`**

```json
{"pool":{"id":1,"name":"Main warehouse"},"version":12,"remaining":88,
 "log":[ /* newest 100 entries, newest first */ ],"log_has_more":false,
 "viewer":{"operator_id":1,"status":null,"holdings":[]}}
```

`viewer.status` is `null` and `viewer.holdings` is `[]` in M1; M2 fills them in (additive change).

**Errors:** `401 unknown_user` · `404 not_found`.

### GET /api/pools/{pool}/log?before={seq}&limit={n}

Older log pages ("Show older"). Any role.

- `before`: return entries with a `seq` below this value (omit for the newest).
- `limit`: 1–100, default 50.

**Response `200`:** `{"entries":[ … ],"has_more":true}`, newest first.

**Errors:** `401 unknown_user` · `404 not_found` · `422 validation_failed` (bad `before` or `limit`).

### GET /api/users

The seeded users, for the role switch. No `X-Demo-User` needed.

**Response `200`**

```json
{"users":[{"id":1,"name":"Ana","role":"operator","tier":"bronze"},
          {"id":2,"name":"Ben","role":"operator","tier":"silver"},
          {"id":3,"name":"Cara","role":"operator","tier":"gold"},
          {"id":4,"name":"Dana","role":"supervisor","tier":null}]}
```

### POST /api/pools/{pool}/restocks (501, implemented in M3)

Add capacity. Role: `supervisor`. The request and response shapes are fixed by M3; until then the endpoint changes nothing and writes no rows.

**Response `501`**

```json
{"error":{"code":"not_implemented","message":"Available in M3.","details":{"milestone":"M3"}}}
```

**Errors:** `401 unknown_user` · `403 forbidden_role` · `501 not_implemented`.

### PUT /api/pools/{pool}/capacity (501, implemented in M3)

Set capacity. Role: `supervisor`. Capacity is the sum of restock events (D8), so M3 may merge this with `POST …/restocks`; both are declared because the PRD lists both. Until M3 the endpoint changes nothing and writes no rows.

**Response `501`:** same body as above, `milestone` `M3`.

**Errors:** `401 unknown_user` · `403 forbidden_role` · `501 not_implemented`.

### POST /api/pools/{pool}/allocations/{allocation}/corrections (501, implemented in M3)

Amend or void an allocation. Role: `supervisor`. Until M3 the endpoint changes nothing and writes no rows.

**Response `501`:** same body as above, `milestone` `M3`.

**Errors:** `401 unknown_user` · `403 forbidden_role` · `501 not_implemented`.

### GET /api/pools/{pool}/audit (501, implemented in M3)

The audit view of corrections and restocks. Role: `supervisor`. Until M3 the endpoint changes nothing and writes no rows.

**Response `501`:** same body as above, `milestone` `M3`.

**Errors:** `401 unknown_user` · `403 forbidden_role` · `501 not_implemented`.

### Broadcast event: channel `pool.{id}`, name `pool.updated`

A public channel (no authentication; see the README). Sent through Laravel Reverb.

**Payload**

```json
{"version":12,"remaining":88,"entry":{ /* a log entry, see "Log entry shapes" */ }}
```

**Rules**

- Sent only after the transaction commits, and for every stored entry, including a zero grant.
- Failing to broadcast is logged and never fails the request.
- Expiry sends nothing (M2 derives it).
- Clients apply an event only when `version` is the current version + 1, ignore an older or equal one, hold a gap for 500 ms and then fetch one snapshot, and fetch a snapshot on reconnect.

## M2 (additive)

Everything below only adds to the M1 contract above; no M1 field was renamed or removed. Where this section and the M1 text above differ (for example "`viewer.status` is `null` in M1"), this section is the current behaviour.

**Snapshot `GET /api/pools/{pool}`**: new keys

```json
{"server_time":"2026-10-19T10:00:00.123456Z","next_expiry_at":"2026-10-20T10:00:00.000000Z",
 "viewer":{"operator_id":1,"status":"elevated","tier":"bronze","window_seconds":86400,
  "holdings":[{"allocation_seq":12,"units":4,"expires_at":"2026-10-20T10:00:00.000000Z"}]}}
```

- `server_time`: the server's clock when the snapshot was built. Screens use it to correct for a wrong device clock.
- `next_expiry_at`: the earliest `expires_at` among temporary units still held by anyone in the pool, or `null` when none are held. Screens refresh at that moment.
- `viewer.status`: the viewer's own status name (`standard`, `elevated` or `high`), `null` for a supervisor. An operator with no allocations has the lowest status. A status is never sent about anyone else.
- `viewer.tier` and `viewer.window_seconds`: the viewer's current tier and the length of its window in seconds. `null` for a supervisor.
- `viewer.holdings`: the viewer's own temporary units still held, soonest `expires_at` first. Consumed units are never listed. `[]` for a supervisor.

**Submit `POST /api/pools/{pool}/allocations`**: new keys

- `allocation.expires_at`: when the units return (`effective_at` plus the window of `tier_at_log`) for a temporary grant with `granted > 0`; `null` for consumed or a zero grant.
- `viewer`: the same block as in the snapshot, for the submitting operator, as of right after this entry. A replay returns the block of the first reply.

**Log entries**: new keys

- Every entry has `cursor`: `"<seq>"` for stored entries and `"<anchor>x<allocation_seq>"` for an expiry.
- `allocation` entries have `expires_at` (as above, `null` when nothing is held).
- `expiry` entries have `operator` (`{id, name}`) and `at` is the moment the units returned. They are derived on read, never stored, and appear once for each temporary grant with `granted > 0` whose window has ended. An expiry sits directly above the newest stored entry received at or before the expiry and below any later one; the entries are sorted newest first by (anchor, 1, `allocation_seq`), where stored entries sort as (`seq`, 0, 0).

```json
{"seq":null,"cursor":"15x12","kind":"expiry","at":"2026-10-18T10:00:00.123456Z",
 "operator":{"id":1,"name":"Ana"},"allocation_seq":12,"units":3}
```

**Log `GET /api/pools/{pool}/log?before={cursor}&limit={n}`**: `before` also accepts a cursor string (`12x7`) and returns only entries strictly older than that position. A bare integer still works as in M1. Anything else is `422 validation_failed`.

**Expiry broadcasts nothing.** No event is sent when a window ends and nothing is written. Screens act on `next_expiry_at` and the 10 s poll. The broadcast entry of a new temporary allocation carries `expires_at`.

**Evaluation instant.** A write is evaluated at its receipt time, but never earlier than the newest `effective_at` already in the pool's ledger (a request received earlier can commit after one received later). The stored `effective_at` stays the receipt time. A replay is evaluated at the original instant, so it returns the original body.

**Rules config.** `window_scale` (env `POOL_WINDOW_SCALE`, default `1`) multiplies every window and exists only so a demo can show an expiry in minutes. It must be above 0 and at most 1; a bad value stops startup with the key `window_scale`.

## Calculation contract

Used by M2 and M3. Written in PHP; all classes live under `App\Pool\Calculation`.

```php
interface Clock { public function now(): CarbonImmutable; }   // App\Support

final class Ledger { // immutable
  /** @param list<RestockEntry> $restocks  @param list<AllocationEntry> $allocations  @param list<CorrectionEntry> $corrections */
  public function __construct(public array $restocks, public array $allocations, public array $corrections = []) {}
}
// AllocationEntry: id, seq, operatorId, type, units (granted after corrections), tierAtLog, effectiveAt,
//                  active (false once expired; defaults to true)

final class PoolState { public function __construct(public int $remaining, public int $version, public array $statuses = []) {} }

final class PoolCalculator {
  public function calculate(Ledger $ledger): PoolState;           // corrections → expiry → sum; statuses from ComputeStatus
  public function grantFor(PoolState $state, int $requested): int; // max(0, min($requested, $state->remaining))
}
// remaining = Σ restock.amount − Σ units(type = consumed) − Σ units(type = temporary AND active)
// version   = the highest seq in the ledger as loaded
// ApplyCorrections::apply(Ledger): Ledger                                   (M3)
// ApplyExpiry::apply(Ledger, CarbonImmutable $now, RulesConfig): Ledger     (M2)
// ComputeStatus::compute(Ledger, CarbonImmutable $now, RulesConfig): array<int,string>  (M2)
```

## Rules config

`config/pool_rules.php`. Validated at application start; an invalid file stops startup with an error that names the bad key (for example `tiers.silver.window_hours`).

```php
return [
  'tiers' => ['bronze' => ['window_hours' => 24], 'silver' => ['window_hours' => 48], 'gold' => ['window_hours' => 72]],
  'status_levels' => [ // exactly 3; the first starts at 0; min_units strictly ascending
    ['name' => 'standard', 'min_units' => 0], ['name' => 'elevated', 'min_units' => 10], ['name' => 'high', 'min_units' => 30]],
];
```

| Rule | Error key |
|---|---|
| Every tier has a positive whole `window_hours` | `tiers.<name>.window_hours` |
| At least one tier | `tiers` |
| Exactly 3 status levels | `status_levels` |
| Each level has a non-empty `name` and a whole `min_units` ≥ 0 | `status_levels.<n>.name`, `status_levels.<n>.min_units` |
| The first level starts at 0 | `status_levels.0.min_units` |
| Thresholds are strictly ascending | `status_levels.<n>.min_units` |
