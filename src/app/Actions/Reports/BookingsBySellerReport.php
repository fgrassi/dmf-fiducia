<?php

namespace App\Actions\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * relReservaVendedor (legado), "Reserva/Vendedor": por vendedor (ou todos) e período, as reservas fechadas (data de
 * cadastro), as importações com chegada confirmada, as exportações com embarque confirmado (pela chegada efetiva, como o
 * legado) e as reservas com cobrança recebida no período (pela data de pagamento). Cada reserva sai uma vez por lista.
 */
class BookingsBySellerReport
{
    /**
     * @return array{closed: Collection<int, object>, imports: Collection<int, object>, exports: Collection<int, object>, payments: Collection<int, object>}
     */
    public function __invoke(?int $sellerId, ?string $from, ?string $to): array
    {
        $base = fn () => DB::table('embarque as e')
            ->join('pessoa as c', 'c.pessoa_id', '=', 'e.cliente_id')
            ->leftJoin('vendedor as v', 'v.vendedor_id', '=', 'e.vendedor_id')
            ->where('e.finalizado', '<>', 'c')
            ->when($sellerId, fn ($q) => $q->where('e.vendedor_id', $sellerId))
            ->select('e.embarque_id', 'e.data_topo', 'e.chegada_efetiva', 'e.tipo_embarque', 'e.booking', 'c.nome as client', 'v.nome as seller')
            ->distinct();
        $between = fn ($query, string $column) => $query
            ->when($from, fn ($q) => $q->whereDate($column, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate($column, '<=', $to));
        $paidIn = fn ($query) => $query->whereColumn('fin_charges.ref_booking', 'e.embarque_id')
            ->when($from, fn ($q) => $q->whereDate('fin_charges.payment_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('fin_charges.payment_date', '<=', $to));

        return [
            'closed' => $between($base(), 'e.data_topo')->orderBy('e.data_topo')->orderBy('e.embarque_id')->get(),
            'imports' => $between($base()->where('e.impor_expor', 'i')->whereExists(fn ($q) => $q->from('arrival_confirmation')->whereColumn('arrival_confirmation.ref_embarque', 'e.embarque_id')), 'e.chegada_efetiva')->orderBy('e.chegada_efetiva')->orderBy('e.booking')->get(),
            'exports' => $between($base()->where('e.impor_expor', 'e')->whereExists(fn ($q) => $q->from('departure_confirmation')->whereColumn('departure_confirmation.ref_embarque', 'e.embarque_id')), 'e.chegada_efetiva')->orderBy('e.chegada_efetiva')->orderBy('e.booking')->get(),
            'payments' => $base()
                ->whereExists(fn ($q) => $paidIn($q->from('fin_charges')))
                ->selectSub($paidIn(DB::table('fin_charges'))->selectRaw('min(payment_date)'), 'paid_at')
                ->orderBy('paid_at')
                ->orderBy('e.booking')
                ->get(),
        ];
    }
}
