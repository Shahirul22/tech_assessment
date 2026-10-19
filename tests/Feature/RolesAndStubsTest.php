<?php

use App\Http\ApiException;
use App\Models\Pool;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->ana = User::factory()->operator('bronze')->create(['name' => 'Ana']);
    $this->dana = User::factory()->supervisor()->create(['name' => 'Dana']);

    // Probe routes, registered here because the real endpoints arrive in later tasks.
    Route::middleware('demo.user')->prefix('api/probe')->group(function () {
        Route::get('me', fn (Request $request) => ['id' => $request->user()->id, 'role' => $request->user()->role]);
        Route::get('supervisor-only', fn () => ['ok' => true])->middleware('role:supervisor');
        Route::get('operator-only', fn () => ['ok' => true])->middleware('role:operator');
        Route::get('validate', fn (Request $request) => $request->validate(['n' => ['required', 'integer', 'min:1']]));
        Route::get('pool/{pool}', fn (Pool $pool) => ['id' => $pool->id])->middleware(SubstituteBindings::class);
        Route::get('conflict', fn () => throw new ApiException(409, 'idempotency_key_conflict', 'Key reused.', ['key' => 'k']));
        Route::get('boom', fn () => throw new RuntimeException('secret detail'));
    });
});

function asUser(User|int|string|null $user): array
{
    return $user === null ? [] : ['X-Demo-User' => (string) ($user instanceof User ? $user->id : $user)];
}

it('rejects a request without X-Demo-User with 401 unknown_user', function () {
    $this->getJson('/api/probe/me')
        ->assertStatus(401)
        ->assertExactJson(['error' => ['code' => 'unknown_user', 'message' => 'Send the id of a seeded user in the X-Demo-User header.', 'details' => []]]);
});

it('rejects an unknown or malformed X-Demo-User with 401 unknown_user', function (string $header) {
    $this->getJson('/api/probe/me', asUser($header))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unknown_user');
})->with([
    'no such id' => '999999',
    'not a number' => 'abc',
    'negative' => '-1',
    'too big for a bigint' => '99999999999999999999',
    'empty' => '',
]);

it('resolves the acting user for the request', function () {
    $this->getJson('/api/probe/me', asUser($this->ana))
        ->assertOk()
        ->assertExactJson(['id' => $this->ana->id, 'role' => 'operator']);
});

it('refuses an operator on a supervisor route with 403 forbidden_role', function () {
    $this->getJson('/api/probe/supervisor-only', asUser($this->ana))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden_role')
        ->assertJsonPath('error.details.required_role', 'supervisor');
});

it('lets a supervisor through a supervisor route', function () {
    $this->getJson('/api/probe/supervisor-only', asUser($this->dana))
        ->assertOk()
        ->assertExactJson(['ok' => true]);
});

it('refuses a supervisor on an operator route with 403 forbidden_role', function () {
    $this->getJson('/api/probe/operator-only', asUser($this->dana))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden_role');
});

it('checks the demo user before the role', function () {
    $this->getJson('/api/probe/supervisor-only')->assertStatus(401);
});

it('renders validation errors as 422 validation_failed with the fields', function () {
    $this->getJson('/api/probe/validate?n=0', asUser($this->ana))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['fields' => ['n']]]])
        ->assertJsonMissingPath('errors');
});

it('renders an unknown model and an unknown path as 404 not_found', function () {
    $this->getJson('/api/probe/pool/999999', asUser($this->ana))
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');

    $this->getJson('/api/does-not-exist')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});

it('renders a wrong method as 405 method_not_allowed', function () {
    $this->postJson('/api/probe/me', [], asUser($this->ana))
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'method_not_allowed');
});

it('renders an ApiException with its own status, code and details', function () {
    $this->getJson('/api/probe/conflict', asUser($this->ana))
        ->assertStatus(409)
        ->assertExactJson(['error' => ['code' => 'idempotency_key_conflict', 'message' => 'Key reused.', 'details' => ['key' => 'k']]]);
});

it('hides the message of an unexpected failure behind 500 server_error', function () {
    $response = $this->getJson('/api/probe/boom', asUser($this->ana))
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'server_error');

    expect($response->getContent())->not->toContain('secret detail');
});

it('serialises empty details as a JSON object, not a list', function () {
    $content = $this->getJson('/api/probe/me')->getContent();

    expect($content)->toContain('"details":{}');
});

it('leaves non-API routes with their normal error pages', function () {
    $response = $this->get('/no-such-page');

    $response->assertNotFound();
    expect($response->headers->get('content-type'))->not->toContain('json');
});

/**
 * The three endpoints M3 built. Their behaviour is tested in RestockTest, CorrectionTest and AuditTest; here only that
 * they sit behind the demo user and the supervisor role.
 */
dataset('supervisor routes', [
    'restock' => ['POST', '/api/pools/{pool}/restocks'],
    'correction' => ['POST', '/api/pools/{pool}/allocations/1/corrections'],
    'audit' => ['GET', '/api/pools/{pool}/audit'],
]);

function supervisorUri(string $template, Pool $pool): string
{
    return str_replace('{pool}', (string) $pool->id, $template);
}

function tableCounts(): array
{
    return collect(['users', 'pools', 'restocks', 'allocations', 'corrections'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
        ->all();
}

it('keeps the supervisor endpoints behind the supervisor role and the demo user', function (string $method, string $template) {
    $pool = Pool::factory()->create();

    $this->json($method, supervisorUri($template, $pool), [], asUser($this->ana))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden_role');

    $this->json($method, supervisorUri($template, $pool))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unknown_user');
})->with('supervisor routes');

it('writes nothing when an operator calls a supervisor endpoint', function (string $method, string $template) {
    $pool = Pool::factory()->create();
    DB::table('pools')->where('id', $pool->id)->update(['version' => 5]);
    $before = tableCounts();

    $this->json($method, supervisorUri($template, $pool), ['amount' => 10, 'kind' => 'void', 'reason' => 'x'], asUser($this->ana) + ['Idempotency-Key' => 'k'])->assertStatus(403);

    expect(tableCounts())->toBe($before)
        ->and(DB::table('pools')->where('id', $pool->id)->value('version'))->toBe(5);
})->with('supervisor routes');

it('registers the supervisor routes under /api, behind the demo user and the supervisor role', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->mapWithKeys(fn ($route) => [implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri() => $route->gatherMiddleware()]);

    foreach ([
        'POST api/pools/{pool}/restocks',
        'POST api/pools/{pool}/allocations/{seq}/corrections',
        'GET api/pools/{pool}/audit',
    ] as $route) {
        expect($routes)->toHaveKey($route)
            ->and($routes[$route])->toContain('demo.user', 'role:supervisor');
    }
});

it('no longer routes PUT /capacity, so capacity is raised by restocks alone', function () {
    $pool = Pool::factory()->create();

    $this->putJson("/api/pools/{$pool->id}/capacity", ['capacity' => 10], asUser($this->dana))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri());
    expect($routes)->not->toContain('api/pools/{pool}/capacity');
});

it('lists the four seeded users without any header', function () {
    // Replace the two factory users from beforeEach with the real seeded ones.
    User::query()->delete();
    $this->seed(UserSeeder::class);

    $this->getJson('/api/users')
        ->assertOk()
        ->assertExactJson(['users' => [
            ['id' => 1, 'name' => 'Ana', 'role' => 'operator', 'tier' => 'bronze'],
            ['id' => 2, 'name' => 'Ben', 'role' => 'operator', 'tier' => 'silver'],
            ['id' => 3, 'name' => 'Cara', 'role' => 'operator', 'tier' => 'gold'],
            ['id' => 4, 'name' => 'Dana', 'role' => 'supervisor', 'tier' => null],
        ]]);
});

it('orders the user list by id and shows nothing but the four fields', function () {
    User::query()->delete();
    User::factory()->operator('gold')->create(['id' => 20, 'name' => 'Zed']);
    User::factory()->operator('silver')->create(['id' => 10, 'name' => 'Amy']);

    $users = $this->getJson('/api/users')->assertOk()->json('users');

    expect(array_column($users, 'id'))->toBe([10, 20])
        ->and(array_keys($users[0]))->toBe(['id', 'name', 'role', 'tier']);
});
