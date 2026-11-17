<?php

namespace App\Actions\Booking\Tracking;

use App\Models\Embarque;
use App\Models\Transborda;
use Illuminate\Support\Facades\DB;

/**
 * updateRastreamento act=2 (legado): lança (ou edita) a mensagem de rastreamento e atualiza o ETA e o navio de destino da
 * reserva e manda a mensagem por e-mail aos contatos (SendTracking), a cada reserva e também ao editar, como o legado.
 * Na criação a mesma mensagem vale para as outras reservas informadas. Na exportação para o Chile (país 43), o "Booking
 * Made" (frase 16) liga o controle do Chile. `$unsent` fica com as reservas cujo e-mail não saiu. Diferença do legado:
 * não avisa o financeiro da mudança de ETA.
 */
class SaveTracking
{
    private const BOOKING_MADE = 16;

    private const CHILE = 43;

    /** @var list<int> reservas da última chamada sem contato ou com falha no envio */
    public array $unsent = [];

    public function __construct(private SendTracking $send) {}

    /** @param array{ref_frase_transborda: int|string, descr: string, data_reg: string, eta?: ?string, navio_id_destino?: int|string|null, other_bookings?: list<int|string>} $data */
    public function __invoke(Embarque $booking, array $data, int $userId, ?Transborda $record = null): Transborda
    {
        $ids = $record ? [$booking->getKey()] : array_values(array_unique([...($data['other_bookings'] ?? []), $booking->getKey()]));
        $changes = array_filter(['chegada_prevista' => $data['eta'] ?? null, 'navio_id_destino' => $data['navio_id_destino'] ?? null], 'filled');

        $saved = [];

        $current = DB::transaction(function () use ($data, $userId, $record, $ids, $changes, &$saved) {
            foreach ($ids as $id) {
                if ($changes) {
                    DB::table('embarque')->where('embarque_id', $id)->update($changes);
                }

                $attributes = [
                    'embarque_id' => $id,
                    'descr' => $data['descr'],
                    'data_reg' => $data['data_reg'],
                    'ref_frase_transborda' => $data['ref_frase_transborda'],
                    'usuario_id' => $userId,
                    'dteEmbarque' => null,
                    'dtePrevista' => null,
                ];

                $tracking = $record ? tap($record)->update($attributes) : Transborda::create($attributes);
                $this->startChileControl((int) $id, (int) $data['ref_frase_transborda']);
                $saved[(int) $id] = $tracking;
            }

            return $tracking;
        });

        $this->unsent = [];

        foreach ($saved as $id => $tracking) {
            if (! ($this->send)(Embarque::find($id), $tracking, $userId)) {
                $this->unsent[] = $id;
            }
        }

        return $current;
    }

    private function startChileControl(int $bookingId, int $phraseId): void
    {
        if ($phraseId !== self::BOOKING_MADE) {
            return;
        }

        DB::table('embarque')
            ->where('embarque_id', $bookingId)
            ->where('impor_expor', 'e')
            ->whereIn('porto_destino_id', fn ($q) => $q->select('porto_id')->from('porto')->where('ref_pais', self::CHILE))
            ->where(fn ($q) => $q->where('ctrl_chile', 0)->orWhereNull('ctrl_chile'))
            ->update(['ctrl_chile' => 1]);
    }
}
