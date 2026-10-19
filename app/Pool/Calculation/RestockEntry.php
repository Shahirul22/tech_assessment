<?php

namespace App\Pool\Calculation;

final readonly class RestockEntry
{
    public function __construct(
        public int $id,
        public int $seq,
        public int $amount,
    ) {}
}
