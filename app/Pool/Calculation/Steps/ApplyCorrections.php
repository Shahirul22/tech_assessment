<?php

namespace App\Pool\Calculation\Steps;

use App\Pool\Calculation\Ledger;

/**
 * Owned by M3. Identity in M1: returns the ledger unchanged.
 */
class ApplyCorrections
{
    public function apply(Ledger $ledger): Ledger
    {
        return $ledger;
    }
}
