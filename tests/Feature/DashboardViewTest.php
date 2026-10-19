<?php

use App\Models\Pool;
use App\Models\Restock;
use Database\Seeders\DatabaseSeeder;

/**
 * The JSON the page embeds for the client (DD14).
 */
function embeddedSnapshot(string $html): array
{
    preg_match('#<script type="application/json" id="initial-snapshot">(.*?)</script>#s', $html, $match);

    expect($match)->not->toBeEmpty('the page must embed #initial-snapshot');

    return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
}

it('renders the dashboard with the first pool\'s snapshot embedded', function () {
    $this->seed(DatabaseSeeder::class);

    $response = $this->get('/')->assertOk();
    $snapshot = embeddedSnapshot($response->getContent());

    expect($snapshot['remaining'])->toBe(100)
        ->and($snapshot['version'])->toBe(1)
        ->and($snapshot['pool'])->toBe(['id' => 1, 'name' => 'Main warehouse'])
        ->and($snapshot['log'])->toHaveCount(1)
        ->and($snapshot['log'][0])->toMatchArray(['seq' => 1, 'kind' => 'restock', 'amount' => 100, 'note' => 'Initial stock'])
        ->and($snapshot['log_has_more'])->toBeFalse()
        // The page is not for one viewer: the client picks who is looking.
        ->and($snapshot['viewer']['operator_id'])->toBeNull();
});

it('shows the first pool by id when there are several', function () {
    $first = Pool::factory()->create(['name' => 'First']);
    Restock::factory()->create(['pool_id' => $first->id, 'seq' => 1, 'amount' => 7]);
    Pool::factory()->create(['name' => 'Second']);

    $snapshot = embeddedSnapshot($this->get('/')->getContent());

    expect($snapshot['pool']['name'])->toBe('First')
        ->and($snapshot['remaining'])->toBe(7);
});

it('has a request form with a Consumed and Temporary choice and a request field the supervisor panel does not reuse', function () {
    $this->seed(DatabaseSeeder::class);

    $html = $this->get('/')->assertOk()->getContent();

    // M1 AC-US4.7 ("no selector") is superseded by PQ1: the form now offers the type as two radio options.
    expect(substr_count($html, 'name="type"'))->toBe(2)
        ->and($html)->toMatch('/<input[^>]*id="type-consumed"[^>]*value="consumed"[^>]*checked/')
        ->and($html)->toMatch('/<input[^>]*id="type-temporary"[^>]*value="temporary"/')
        ->and($html)->not->toMatch('/<input[^>]*id="type-temporary"[^>]*checked/')
        ->and($html)->toContain('<span>Consumed</span>')
        ->and($html)->toContain('<span>Temporary</span>')
        ->and($html)->not->toMatch('/<select[^>]*(type|kind)/i')
        ->and($html)->toContain('id="request-form"')
        ->and($html)->toContain('name="requested"')
        // Nothing sets or lowers capacity: restocks only add.
        ->and(strtolower($html))->not->toContain('capacity');
});

it('replaces the supervisor placeholder with the restock form, the correction form and the audit', function () {
    $this->seed(DatabaseSeeder::class);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('Placeholder — arrives in M3')
        ->and($html)->toContain('id="supervisor-view"')
        // Three forms in all: request, restock, correct or void.
        ->and(substr_count($html, '<form'))->toBe(3)
        ->and($html)->toContain('id="restock-form"')
        ->and($html)->toContain('name="amount"')
        ->and($html)->toContain('name="note"')
        ->and($html)->toContain('id="correction-form"')
        ->and($html)->toMatch('/<input[^>]*id="correction-amend"[^>]*value="amend"/')
        ->and($html)->toMatch('/<input[^>]*id="correction-void"[^>]*value="void"/')
        ->and($html)->toContain('name="reason"')
        ->and($html)->toContain('id="correction-seq"')
        ->and($html)->toContain('id="correction-result"')
        ->and($html)->toContain('id="restock-result"')
        ->and($html)->toContain('id="audit"')
        ->and($html)->toContain('id="audit-list"')
        ->and($html)->toContain('No corrections yet');
});

it('keeps every supervisor control behind the supervisor gate, and the request form behind the operator gate', function () {
    $this->seed(DatabaseSeeder::class);

    $html = $this->get('/')->assertOk()->getContent();

    // The supervisor section is hidden unless a supervisor has opened that tab; the Correct button needs a supervisor.
    expect($html)->toMatch('/<section[^>]*id="supervisor-view"[^>]*x-show="showSupervisor"[^>]*x-cloak/')
        ->and($html)->toMatch('/<button[^>]*x-show="isSupervisor && row\.correctable"/')
        ->and($html)->toMatch('/<section[^>]*id="request-panel"[^>]*x-show="isOperator"/');
});

it('has the status line and the temporary holdings panel with its empty state', function () {
    $this->seed(DatabaseSeeder::class);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('id="status"')
        ->and($html)->toContain('Status:')
        ->and($html)->toContain('id="holdings"')
        ->and($html)->toContain('Your temporary holdings')
        ->and($html)->toContain('No temporary holdings')
        ->and($html)->toContain('id="type-hint"');
});

it('has the user picker and the supervisor tab', function () {
    $this->seed(DatabaseSeeder::class);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('id="user-picker"')
        ->and($html)->toContain('id="tab-supervisor"')
        ->and($html)->toContain('Supervisor')
        ->and($html)->toContain('Pool');
});

it('shows the remaining number in the first paint, before any script runs', function () {
    $this->seed(DatabaseSeeder::class);

    $this->get('/')->assertOk()->assertSee('100');
});

it('loads the built assets through Vite and sets the viewport', function () {
    $this->seed(DatabaseSeeder::class);

    $this->get('/')->assertOk()
        ->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1">', false)
        ->assertSee('build/assets/app-', false);
});

it('cannot be broken out of by a hostile pool name', function () {
    $pool = Pool::factory()->create(['name' => '</script><script>alert(1)</script>']);
    Restock::factory()->create(['pool_id' => $pool->id, 'seq' => 1, 'amount' => 5]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('</script><script>alert(1)')
        ->and(embeddedSnapshot($html)['pool']['name'])->toBe('</script><script>alert(1)</script>');
});

it('says so plainly when no pool has been seeded', function () {
    $this->get('/')->assertNotFound();
});
