<?php

namespace App\Actions\Comissoes;

use Illuminate\Support\Facades\DB;

/** executeAddComissao (booking, legado): comissão do embarque para um comissionado, com log no rastreamento. */
class AddCommission
{
    public function __construct(private CommissionTracking $tracking) {}

    /** @param  array{responsavel_id:int|string, tipo_comissao:int|string, tipo:int|string|null, moeda_id:int|string, valor_unitario:float|int|string}  $data */
    public function __invoke(int $bookingId, array $data, int $userId): int
    {
        return DB::transaction(function () use ($bookingId, $data, $userId) {
            $row = [
                'embarque_id' => $bookingId,
                'comissao_embarque_responsavel_id' => (int) $data['responsavel_id'],
                'tipo_comissao' => (int) $data['tipo_comissao'],
                'tipo' => (int) ($data['tipo'] ?? 0) === 1 ? 1 : 0,
                'moeda_id' => (int) $data['moeda_id'],
                'valor_unitario' => (float) $data['valor_unitario'],
                'status' => 0,
                'created_by' => $userId,
                'created_at' => now()->format('Y-m-d H:i:s'),
            ];

            $id = DB::table('comissao_embarque')->insertGetId($row);
            ($this->tracking)('Comissão adicionada', 'i', (object) $row, $userId);

            return $id;
        });
    }
}
