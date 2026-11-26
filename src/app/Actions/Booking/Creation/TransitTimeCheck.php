<?php

namespace App\Actions\Booking\Creation;

use Illuminate\Support\Facades\DB;

/** verifica_tt e checa_tt (legado, tela da importação marítima): o que se sabe sobre o transit time do trecho. */
class TransitTimeCheck
{
    /** @return list<string> o transit time combinado com o consignee e o desvio do digitado em relação à média efetiva */
    public function __invoke(mixed $originId, mixed $destinationId, mixed $carrierId, mixed $consigneeId, mixed $days): array
    {
        if (! $originId || ! $destinationId || ! $carrierId) {
            return [];
        }

        $messages = [];

        $agreed = $consigneeId ? DB::table('transit_time')
            ->where(['armador_id' => $carrierId, 'pessoa_id' => $consigneeId, 'porto_origem_id' => $originId, 'porto_destino_id' => $destinationId])
            ->orderBy('transit_time_id')
            ->first() : null;

        if ($agreed) {
            $messages[] = "Transit time cadastrado para o consignee neste trajeto: médio {$agreed->transit_time}, de segurança {$agreed->transit_time_seguranca}.";
        }

        if (blank($days)) {
            return $messages;
        }

        $average = (float) DB::table('vw_transit_time')
            ->where(['origem_id' => $originId, 'destino_id' => $destinationId, 'armador_id' => $carrierId])
            ->whereNotNull('saida_prevista')
            ->whereNotNull('chegada_prevista')
            ->whereNotNull('saida_efetiva')
            ->whereNotNull('chegada_efetiva')
            ->avg('tt_efetivo');

        if ($average > 0) {
            $ratio = (float) $days / $average * 100;
            $direction = match (true) {
                $ratio > 110 => 'MAIOR',
                $ratio < 90 => 'MENOR',
                default => null,
            };

            if ($direction) {
                $messages[] = "O Transit Time é {$direction} que a média de TT para esse trajeto! TT médio: ".number_format($average, 1, ',', '.');
            }
        }

        return $messages;
    }
}
