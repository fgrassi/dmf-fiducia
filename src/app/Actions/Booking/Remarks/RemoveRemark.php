<?php

namespace App\Actions\Booking\Remarks;

use App\Models\ObservacaoEmbarque;

/** removerLembrete (legado). */
class RemoveRemark
{
    public function __invoke(ObservacaoEmbarque $remark): void
    {
        $remark->delete();
    }
}
