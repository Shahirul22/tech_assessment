<?php

use Database\Seeders\DatabaseSeeder;

/**
 * The hooks the screen, the browser audit and the older checks rely on (M4: quick amounts, stepper, banners).
 * No styles and no script behaviour here; those are checked in the JS tests and the browser audit.
 */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->html = $this->get('/')->assertOk()->getContent();
});

it('has the quick-amount row and the stepper with text names', function () {
    expect($this->html)
        ->toContain('id="quick-amounts"')
        ->toContain('id="amount-decrease"')
        ->toContain('aria-label="Decrease"')
        ->toContain('id="amount-increase"')
        ->toContain('aria-label="Increase"');
});

it('keeps the request form ids the older checks use', function () {
    expect($this->html)
        ->toContain('id="requested"')
        ->toContain('id="request-submit"')
        ->toContain('id="requested-hint"');
});

it('renders three banners announced as a status, with their old ids', function () {
    foreach (['result-banner', 'restock-result', 'correction-result'] as $id) {
        expect($this->html)->toContain('<div id="'.$id.'" role="status">');
    }

    expect(substr_count($this->html, 'role="status"'))->toBeGreaterThanOrEqual(3);
});

it('gives each banner its words, a hidden shape and its Retry button', function () {
    foreach (['result-text', 'restock-result-text', 'correction-result-text', 'retry', 'restock-retry', 'correction-retry'] as $id) {
        expect($this->html)->toContain('id="'.$id.'"');
    }

    // The shape is decoration only: the words carry the meaning.
    expect(substr_count($this->html, 'class="banner__shape" aria-hidden="true"'))->toBe(3);
});

it('labels the Correct button of a log row for assistive technology', function () {
    expect($this->html)->toContain("x-bind:aria-label=\"'Correct entry #' + row.seq\"");
});
