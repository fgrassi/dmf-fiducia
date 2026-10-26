<?php

namespace App\Actions\Cobranca\Rates;

use App\Models\FinCharges;
use App\Models\FinCustomCurrencyRate;

/** RemoveCustos (legado): remove uma cotação negociada da cobrança. */
class DeleteCustomRate
{
    public function __invoke(FinCharges $charge, int $rateId): void
    {
        FinCustomCurrencyRate::where('ref_charge', $charge->id)->whereKey($rateId)->delete();
    }
}
