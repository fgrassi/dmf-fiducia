<?php

namespace App\Actions\Reports;

use Illuminate\Support\Facades\DB;

/**
 * relCustosCorreio (legado), "Custos Correio": envios por Sedex do período, pela data de envio (o legado abre no dia de hoje).
 * Há duas listas: os vinculados a reservas (o valor do envio é rateado igualmente entre as reservas) e os de formulário, sem
 * reserva. Um envio marcado como formulário que também tem reserva aparece nas duas, como no legado.
 */
class MailCostsReport
{
    /**
     * @param  array{data_ini?: ?string, data_fim?: ?string}  $input
     * @return array{rows: list<array<string, mixed>>, linked_total: float, form_total: float, total: float}
     */
    public function __invoke(array $input): array
    {
        $from = $input['data_ini'] ?? today()->toDateString();
        $to = $input['data_fim'] ?? today()->toDateString();

        $mailings = DB::table('controle_correio')
            ->whereBetween('data_envio', [$from, $to])
            ->orderByDesc('data_envio')
            ->orderBy('controle_correio_id')
            ->get();

        $bookings = DB::table('ctr_correio_embarque as c')
            ->join('embarque as e', 'e.embarque_id', '=', 'c.embarque_id')
            ->whereIn('c.controle_correio_id', $mailings->pluck('controle_correio_id'))
            ->orderBy('c.ctr_correio_embarque_id')
            ->get(['c.controle_correio_id', 'e.embarque_id', 'e.booking'])
            ->groupBy('controle_correio_id');

        $rows = $mailings
            ->map(function (object $mailing) use ($bookings) {
                $linked = $bookings->get($mailing->controle_correio_id, collect());
                $value = (float) $mailing->valor;

                return [
                    'id' => $mailing->controle_correio_id,
                    'num_sedex' => $mailing->num_sedex,
                    'data_envio' => $mailing->data_envio,
                    'destinatario' => $mailing->destinatario,
                    'bookings' => $linked->map(fn (object $booking) => ['id' => $booking->embarque_id, 'name' => $booking->booking])->all(),
                    'value_per_booking' => $linked->isEmpty() ? null : $value / $linked->count(),
                    'valor' => $value,
                    'is_linked' => $linked->isNotEmpty(),
                    'is_form' => $mailing->formulario === 's',
                ];
            })
            ->filter(fn (array $row) => $row['is_linked'] || $row['is_form'])
            ->values();

        $linkedTotal = (float) $rows->where('is_linked', true)->sum('valor');
        $formTotal = (float) $rows->where('is_form', true)->sum('valor');

        return ['rows' => $rows->all(), 'linked_total' => $linkedTotal, 'form_total' => $formTotal, 'total' => $linkedTotal + $formTotal];
    }
}
