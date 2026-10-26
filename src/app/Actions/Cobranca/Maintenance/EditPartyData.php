<?php

namespace App\Actions\Cobranca\Maintenance;

use App\Models\FinCharges;
use App\Models\FinChargesLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** common/editCampo (legado) para Dados do Exportador/Importador: salva e registra em rastreabilidade_alteracao e fin_charges_log. */
class EditPartyData
{
    private const FIELDS = ['nome_exportador' => 'Dados do Exportador', 'nome_importador' => 'Dados do Importador'];

    public function __invoke(FinCharges $charge, string $field, ?string $value, ?int $userId): FinCharges
    {
        if (! isset(self::FIELDS[$field])) {
            throw new InvalidArgumentException("Campo não editável: {$field}");
        }

        $previous = $charge->getRawOriginal($field);
        $new = filled($value) ? $value : null;

        DB::transaction(function () use ($charge, $field, $previous, $new, $userId) {
            $charge->forceFill([$field => $new])->save();

            DB::table('rastreabilidade_alteracao')->insert([
                'tabela' => 'fin_charges',
                'campo' => $field,
                'tabela_id' => $charge->id,
                'valor_antigo' => $previous,
                'valor_novo' => $new ?? '',
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            FinChargesLog::forceCreate([
                'ref_charge' => $charge->id,
                'acao' => 'alteracao',
                'historico' => self::FIELDS[$field].' alterado de '.(filled($previous) ? $previous : '—').' para '.($new ?? '—'),
                'status_anterior' => $charge->status->value,
                'status_novo' => $charge->status->value,
                'created_at' => now(),
                'created_by' => $userId,
            ]);
        });

        return $charge;
    }
}
