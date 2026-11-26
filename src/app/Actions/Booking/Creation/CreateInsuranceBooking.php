<?php

namespace App\Actions\Booking\Creation;

use App\Models\Embarque;
use Illuminate\Support\Facades\DB;

/** novoSeguro (legado): a reserva de seguro que nasce junto com a importação marítima com "Seguro Contratado = SIM". */
class CreateInsuranceBooking
{
    private const COPIED = [
        'impor_expor', 'vendedor_id', 'finalizado', 'data_topo', 'de', 'de_fone', 'porto_origem_id', 'porto_destino_id', 'incoterm_id',
        'cliente_id', 'assessoria_id', 'trading_id', 'despachante_id', 'ctr_despachante', 'despachante_dmf_id', 'pessoa_id_importador', 'pessoa_id_cliente',
    ];

    public function __construct(private HistorySnapshot $history) {}

    public function __invoke(Embarque $booking, int $userId): Embarque
    {
        return DB::transaction(function () use ($booking, $userId) {
            $sequence = (int) DB::table('seguro_booking')->lockForUpdate()->max('sequencia') + 1;
            DB::table('seguro_booking')->insert(['sequencia' => $sequence]);

            $insurance = (new Embarque)->forceFill([
                ...array_filter($booking->only(self::COPIED), fn ($value) => $value !== null),
                'intIdUser' => $userId,
                'dteCadastro' => today()->toDateString(),
                'tipo_embarque' => 's',
                'booking' => 'SEG'.$sequence.'-'.now()->format('y'),
                'invoice' => filled($booking->invoice) ? "{$booking->booking} / {$booking->invoice}" : $booking->booking,
                'reserva_vinculada_id' => $booking->getKey(),
            ]);
            $insurance->save();

            DB::table('insurance_confirmation')->insert([
                'ref_embarque' => $insurance->getKey(),
                'control' => 0,
                'created_at' => today()->toDateString(),
                'created_by' => $userId,
                'despesas' => 0,
                'ref_currency_despesa' => 1,
                'valor_fatura' => 0,
                'ref_currency_fatura' => 1,
                'valor_frete' => 0,
                'ref_currency_frete' => 1,
            ]);

            ($this->history)($insurance->getKey(), $userId);

            return $insurance;
        });
    }
}
