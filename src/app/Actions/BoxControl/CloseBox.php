<?php

namespace App\Actions\BoxControl;

use App\Models\ControleCaixa;

/** darBaixa (legado): fecha a caixa mesmo antes do limite. */
class CloseBox
{
    public function __invoke(ControleCaixa $box): void
    {
        $box->update(['status' => ControleCaixa::CLOSED]);
    }
}
