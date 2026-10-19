<?php

namespace App\Pool;

/**
 * What a supervisor asked for: the input to RestockService::add().
 */
final readonly class AddRestock
{
    /**
     * @param  int  $amount  Units to add, 1 or more.
     */
    public function __construct(
        public int $amount,
        public ?string $note,
        public string $idempotencyKey,
    ) {}
}
