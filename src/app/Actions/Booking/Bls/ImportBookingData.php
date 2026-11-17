<?php

namespace App\Actions\Booking\Bls;

use App\Actions\Booking\BookingLog;
use App\Models\Bl;
use App\Models\Container;
use App\Models\Embarque;
use Illuminate\Support\Facades\DB;

/**
 * gerarDummy (legado, "Importar dados da reserva" nas reservas canceladas): o BL passa a ter o que a reserva sabe
 * (shipper, consignee, booking como número de BL e de MBL, local do frete) e um container de mentira por unidade das
 * mercadorias (CTN 1, CTN 2...). A reserva fica com esse BL como único House BL e Master BL.
 */
class ImportBookingData
{
    /** Regra de cobrança que define as despesas de frete (regra_despesa_cobranca_id) e as despesas fixas de handling DMF. */
    private const FREIGHT_RULE = 4;

    private const HANDLING_EXPENSES = [703, 1612, 1613];

    private const FREIGHT_AT = ['c' => 'DESTINATION', 'p' => 'ORIGIN', 'a' => 'ABROAD'];

    public function __construct(private BookingLog $log) {}

    public function __invoke(Embarque $booking, Bl $bl, int $userId): void
    {
        DB::transaction(function () use ($booking, $bl, $userId) {
            $name = fn (?int $id): ?string => $id ? DB::table('pessoa')->where('pessoa_id', $id)->value('nome') : null;

            $bl->forceFill([
                'shipper' => $name($booking->pessoa_id_cliente),
                'consignee' => $name($booking->pessoa_id_importador),
                'bl' => $booking->booking,
                'mbl_number' => $booking->booking,
                'freight_at' => $booking->impor_expor === 'e' ? (self::FREIGHT_AT[$this->freightBilling($booking)] ?? '') : '',
            ])->save();

            Container::where('bl_id', $bl->getKey())->delete();

            $count = 0;

            foreach (DB::table('mercadoria')->where('embarque_id', $booking->getKey())->get() as $goods) {
                for ($unit = 0; $unit < (int) $goods->quantidade; $unit++) {
                    Container::create([
                        'bl_id' => $bl->getKey(),
                        'tipo_container_id' => $goods->tipo_container_id,
                        'numero' => 'CTN '.++$count,
                        'tara' => 1,
                        'seals_n1' => 1,
                        'qty_pkgs' => 1,
                        'gross_weight' => 1,
                        'net_weight' => 1,
                        'volume' => 1,
                    ]);
                }
            }

            $bl->forceFill(['gross_weight' => $count, 'net_weight' => $count, 'volume' => $count])->save();
            $booking->forceFill(['qtd_bl' => 1, 'house_bl' => $booking->booking, 'master_bl' => $booking->booking])->save();

            $this->log->track($booking->getKey(), 'Atualização de BL', 'Os dados da reserva foram importados para o BL', $userId, 'u');
        });
    }

    /** O primeiro "billing" (p, c, a) das despesas de frete da reserva. */
    private function freightBilling(Embarque $booking): ?string
    {
        $expenses = DB::table('regra_despesa_cobranca_item')->where('regra_despesa_cobranca_id', self::FREIGHT_RULE)->pluck('despesa_id')->merge(self::HANDLING_EXPENSES);

        return DB::table('fin_expenses_values')->where('ref_embarque', $booking->getKey())->whereIn('ref_expense', $expenses)->orderBy('id')->pluck('billing')->first();
    }
}
