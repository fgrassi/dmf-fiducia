<?php

namespace App\Actions\Booking\Bls;

use App\Models\Bl;
use App\Models\Container;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * importarDraftOnline (legado): cada draft online pendente escolhido vira um BL aberto da reserva, com status de BL online
 * "f" (fechado). Os containers vêm dos equipamentos do draft (ou, nos drafts antigos, de container_online) e o peso bruto,
 * o peso líquido e o volume do BL passam a ser a soma dos equipamentos. Cada draft é independente: o que falha não derruba os outros.
 */
class ImportOnlineDrafts
{
    /**
     * @param  list<int>  $draftIds
     * @return array{imported: int, skipped: int, errors: int}
     *
     * @throws DomainException
     */
    public function __invoke(Embarque $booking, array $draftIds): array
    {
        $draftIds = collect($draftIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->unique()->values();

        if ($draftIds->isEmpty()) {
            throw new DomainException('Selecione ao menos um draft válido para importar.');
        }

        $pending = DB::table('bl_online as o')
            ->leftJoin('bl as b', 'b.bl_online_id', '=', 'o.bl_online_id')
            ->where('o.embarque_id', $booking->getKey())
            ->whereNull('b.bl_id')
            ->whereIn('o.bl_online_id', $draftIds)
            ->get(['o.*']);

        if ($pending->isEmpty()) {
            throw new DomainException('Nenhum draft pendente encontrado para os itens selecionados.');
        }

        $result = ['imported' => 0, 'skipped' => max(0, $draftIds->count() - $pending->count()), 'errors' => 0];

        foreach ($pending as $draft) {
            try {
                DB::transaction(fn () => $this->import($draft));
                $result['imported']++;
            } catch (Throwable $e) {
                report($e);
                $result['errors']++;
            }
        }

        return $result;
    }

    private function import(object $draft): void
    {
        $bl = Bl::create([
            'embarque_id' => $draft->embarque_id,
            'bl_online_id' => $draft->bl_online_id,
            'status_bl_online' => 'f',
            'status' => Bl::OPEN,
            'shipper' => $draft->shipper,
            'notify' => $draft->notify,
            'consignee' => $draft->consignee,
            'freight_at' => $draft->freight_at,
            'rnc' => $draft->rnc,
            'vessel' => $draft->vessel,
            'port_trans' => $draft->port_trans,
            'place_delivery' => $draft->place_delivery,
            'port_lading' => $draft->port_lading,
            'port_disc' => $draft->port_disc,
            'mark_nos' => $draft->mark_nos,
            'desc_pkgs' => $draft->desc_pkgs,
            'num_original' => $draft->num_original,
            'gross_weight' => $draft->gross_weight,
            'net_weight' => $draft->net_weight,
            'volume' => $draft->volume,
        ]);

        $equipments = DB::table('bl_online_equipments')->where('bl_id', $draft->bl_online_id)->get();

        if ($equipments->isEmpty()) {
            foreach (DB::table('container_online')->where('bl_online_id', $draft->bl_online_id)->get() as $legacy) {
                Container::create([
                    'bl_id' => $bl->getKey(),
                    'tipo_container_id' => $legacy->tipo_container_id,
                    'numero' => $legacy->numero,
                    'tara' => $legacy->tara,
                    'seals_n1' => $legacy->seals_n1,
                    'seals_n2' => $legacy->seals_n2,
                    'seals_n3' => $legacy->seals_n3,
                    'qty_pkgs' => $legacy->qty_pkgs,
                    'gross_weight' => $legacy->gross_weight,
                    'net_weight' => $legacy->net_weight,
                    'volume' => $legacy->volume,
                ]);
            }

            return;
        }

        foreach ($equipments as $equipment) {
            Container::create([
                'bl_id' => $bl->getKey(),
                'tipo_container_id' => $equipment->equipment_id,
                'numero' => $equipment->container_number,
                'tara' => $equipment->tare,
                'seals_n1' => $equipment->seal_1,
                'seals_n2' => $equipment->seal_2,
                'seals_n3' => $equipment->seal_3,
                'qty_pkgs' => $equipment->quantity,
                'gross_weight' => $equipment->gross_weight,
                'net_weight' => $equipment->net_weight,
                'volume' => $equipment->cubic_meters,
            ]);
        }

        $bl->forceFill([
            'gross_weight' => $equipments->sum(fn (object $e) => (float) $e->gross_weight),
            'net_weight' => $equipments->sum(fn (object $e) => (float) $e->net_weight),
            'volume' => $equipments->sum(fn (object $e) => (float) $e->cubic_meters),
        ])->save();
    }
}
