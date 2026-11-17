<?php

namespace App\Actions\Booking\Bls;

use App\Models\Bl;
use App\Models\Container;
use App\Models\Embarque;
use Illuminate\Support\Facades\DB;

/** Os avisos que o legado mostra ao usuário depois de lançar containers no BL (updateContainer + o swal da tela). */
class BlWarnings
{
    public function __construct(private ReeferTemperature $reefer) {}

    /** @return list<string> */
    public function __invoke(Embarque $booking, Bl $bl): array
    {
        $warnings = [];

        if ($this->lclVolumeDiverges($booking, $bl)) {
            $warnings[] = "Volume do container LCL DIFERENTE do que o informado na reserva.\nUma mensagem de e-mail foi enviada para a Exportação.";
        }

        if ($booking->impor_expor === 'e' && Container::where('bl_id', $bl->getKey())->get()->contains(fn (Container $c) => $this->reefer->diverges($c, $booking->getKey()))) {
            $warnings[] = 'A temperatura informada para este container REEFER diverge da temperatura informada na reserva.';
        }

        return $warnings;
    }

    /** Exportação com LCL: o volume dos LCL do BL tem que somar o cubagem das mercadorias LCL da reserva. */
    public function lclVolumeDiverges(Embarque $booking, Bl $bl): bool
    {
        $lcl = Container::where('bl_id', $bl->getKey())->where('tipo_container_id', Container::LCL)->pluck('volume');

        if ($booking->impor_expor !== 'e' || $lcl->isEmpty()) {
            return false;
        }

        $sum = fn ($values) => collect($values)->sum(fn ($value) => (float) str_replace(',', '.', (string) $value));
        $cubature = DB::table('mercadoria')->where('embarque_id', $booking->getKey())->where('tipo_container_id', Container::LCL)->pluck('cubature');

        return abs($sum($lcl) - $sum($cubature)) > 0.00001;
    }
}
