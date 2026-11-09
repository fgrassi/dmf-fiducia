<?php

namespace App\Actions\ControlePa;

use App\Models\ControlePa;
use App\Models\EmbEmbarqueFile;
use Illuminate\Support\Facades\DB;

/**
 * removeDocs + mudaFlagFI (legado): desliga a Freight Invoice da reserva e apaga o controle PA dela, para poder gerar outra.
 * O arquivo e o cadastro dele ficam, como no legado. Apagar o controle leva junto datas e flags já preenchidos.
 */
class DeleteFreightInvoice
{
    public function __invoke(EmbEmbarqueFile $link): void
    {
        abort_unless((int) $link->ref_file_type === EmbEmbarqueFile::DMF_INVOICE, 404);

        DB::transaction(function () use ($link) {
            ControlePa::where('embarque_id', $link->ref_embarque)->delete();
            $link->delete();
        });
    }
}
