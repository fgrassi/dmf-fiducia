<?php

namespace App\Actions\Financeiro;

use App\Models\FinCharges;
use App\Services\Cobranca\ChargeDetails;
use Illuminate\Support\Facades\DB;

/**
 * reports/pdfRelatorioCustosEmbarque (legado), dados de "Consulta Pagamentos Cliente": reservas do cliente com as cobranças
 * pagas no período (ou ainda abertas, de qualquer data, com status diferente de 3), os itens de cada cobrança e o total.
 * Reservas e cobranças saem na ordem do legado: pagamento, tipo, origem, destino e saída prevista.
 */
class ClientShipmentCostsReport
{
    public function __construct(private ChargeDetails $details) {}

    /**
     * @param  array{cliente_id: int|string, data_ini: string, data_fim: string, porto_origem_id?: mixed, porto_destino_id?: mixed, armador_id?: mixed}  $input
     * @return array{client: string, shipments: list<array<string, mixed>>}
     */
    public function __invoke(array $input): array
    {
        $rows = DB::table('fin_charges as fc')
            ->join('embarque as e', 'e.embarque_id', '=', 'fc.ref_booking')
            ->where('e.cliente_id', $input['cliente_id'])
            ->where(fn ($query) => $query
                ->whereBetween('fc.payment_date', [$input['data_ini'], $input['data_fim']])
                ->orWhere(fn ($open) => $open->whereNull('fc.payment_date')->where('fc.status', '<>', 3)))
            ->when(filled($input['porto_origem_id'] ?? null), fn ($query) => $query->where('e.porto_origem_id', $input['porto_origem_id']))
            ->when(filled($input['porto_destino_id'] ?? null), fn ($query) => $query->where('e.porto_destino_id', $input['porto_destino_id']))
            ->when(filled($input['armador_id'] ?? null), fn ($query) => $query->where('e.armador_id', $input['armador_id']))
            ->orderBy('fc.payment_date')
            ->orderBy('e.impor_expor')
            ->orderBy('e.porto_origem_id')
            ->orderBy('e.porto_destino_id')
            ->orderBy('e.saida_prevista')
            ->orderBy('fc.id')
            ->get(['fc.id as charge_id', 'fc.ref_booking']);

        $charges = FinCharges::with('embarque')->whereIn('id', $rows->pluck('charge_id'))->get()->keyBy('id');

        $shipments = $rows->groupBy('ref_booking')->map(fn ($group) => $this->shipment(
            $group->map(fn ($row) => $charges[$row->charge_id])->all(),
        ));

        return [
            'client' => (string) DB::table('pessoa')->where('pessoa_id', $input['cliente_id'])->value('nome'),
            'shipments' => $shipments->values()->all(),
        ];
    }

    /**
     * @param  list<FinCharges>  $charges  cobranças da mesma reserva, na ordem da consulta
     * @return array<string, mixed>
     */
    private function shipment(array $charges): array
    {
        $e = $charges[0]->embarque;
        $agent = $e->pessoa_id_representante
            ? DB::table('pessoa as p')->leftJoin('paises as c', 'c.paises_id', '=', 'p.paises_id')->where('p.pessoa_id', $e->pessoa_id_representante)->first(['p.nome', 'p.endereco', 'p.cnpj', 'c.nome as country', 'c.uncode'])
            : null;
        $containers = DB::table('mercadoria as m')
            ->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
            ->where('m.embarque_id', $e->embarque_id)
            ->orderBy('m.mercadoria_id')
            ->get(['m.quantidade', 't.nome'])
            ->map(fn ($m) => "{$m->quantidade} x {$m->nome}");

        $total = 0.0;
        $chargeRows = [];

        foreach ($charges as $charge) {
            $items = array_map(fn (array $item) => ['name' => $item['expense']] + $item['raw'], $this->details->items($charge));
            $iof = (float) $charge->valor_iof;
            $total += array_sum(array_column($items, 'value')) + max($iof, 0);
            $chargeRows[] = ['payment_date' => $charge->payment_date, 'items' => $items, 'iof' => $iof];
        }

        return [
            'type' => $e->impor_expor === 'i' ? 'Importação' : 'Exportação',
            'booking' => $e->booking,
            'origin' => $this->details->port($e->porto_origem_id),
            'destination' => $this->details->port($e->porto_destino_id),
            'etd' => $e->saida_efetiva,
            'containers' => $containers->implode(', '),
            'charges' => $chargeRows,
            'total' => $total,
            'agent' => $agent ? [
                'name' => (string) $agent->nome,
                'address' => (string) $agent->endereco,
                'country' => $agent->country === null ? '' : $agent->country.(filled($agent->uncode) ? " ({$agent->uncode})" : ''),
                'cnpj' => (string) $agent->cnpj,
            ] : null,
            'hbl' => (string) $e->house_bl,
            'invoice' => (string) $e->invoice,
        ];
    }
}
