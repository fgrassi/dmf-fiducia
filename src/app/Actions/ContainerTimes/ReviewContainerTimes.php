<?php

namespace App\Actions\ContainerTimes;

use App\Enums\ContainerTime;
use App\Models\Embarque;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Fim do executeSaveDemurrage / executeSaveDetention (legado): com todos os containers da reserva devolvidos, avisa o
 * grupo responsável se algum tem cobrança. No demurrage também marca a reserva (`ctr_demurrage`) e, importação marítima
 * com todos os containers (fora LCL) devolvidos, chegada confirmada e caixa vinculado, encerra a reserva.
 */
class ReviewContainerTimes
{
    /** Tipo de container LCL: não tem demurrage. */
    private const LCL = 20;

    public function __invoke(ContainerTime $type, Embarque $booking): void
    {
        $rows = $this->rows($type, $booking);

        if ($rows->every(fn ($row) => $row->data_devolucao !== null)) {
            $charged = $rows->filter(fn ($row) => $row->isCharged());

            if ($type === ContainerTime::Demurrage) {
                $booking->forceFill(['ctr_demurrage' => $charged->isNotEmpty() ? 1 : 0])->save();
            }

            if ($charged->isNotEmpty()) {
                $this->notify($type, $booking, $charged);
            }
        }

        if ($type === ContainerTime::Demurrage && $this->shouldClose($booking, $rows)) {
            $booking->forceFill(['finalizado' => 'e'])->save();
        }
    }

    /** @return Collection<int, Model> */
    private function rows(ContainerTime $type, Embarque $booking): Collection
    {
        return ($type === ContainerTime::Demurrage ? $booking->demurrages() : $booking->detentions())->with(['tipoContainer', 'embarque'])->get();
    }

    /** getDemurrageFlag == 1 (legado) mais chegada confirmada e reserva em algum caixa. */
    private function shouldClose(Embarque $booking, Collection $rows): bool
    {
        if ($booking->impor_expor !== 'i' || $booking->tipo_embarque !== 'm') {
            return false;
        }

        $equipment = $rows->filter(fn ($row) => (int) $row->ref_tipo_container !== self::LCL);

        return $equipment->isNotEmpty()
            && $equipment->every(fn ($row) => $row->isClosed())
            && $booking->arrivalConfirmations()->exists()
            && DB::table('controle_caixa_embarque')->where('embarque_id', $booking->getKey())->exists();
    }

    private function notify(ContainerTime $type, Embarque $booking, Collection $charged): void
    {
        $label = $type->label();
        $client = $booking->cliente;
        $html = view('mail.booking.container-time', [
            'title' => "Equipamentos em {$label}",
            'label' => $label,
            'intro' => "O embarque {$booking->booking} possui pelo menos um container onde houve a incidência de ".strtolower($label).'.',
            'client' => $client?->nome,
            'carrier' => $booking->armador?->nome,
            'paysCarrierValues' => (bool) $client?->ctr_detention_demurrage,
            'clientNote' => $client?->observacao_detention_demurrage,
            'rows' => $charged->map(fn ($row) => [
                $row->container,
                $row->tipoContainer?->nome,
                ($row->operationDays() - $row->freeTimeDays()).' dias',
                $row->data_retirada?->format('d/m/Y'),
                $row->data_devolucao?->format('d/m/Y'),
                $row->freeTimeDays(),
            ])->all(),
        ])->render();

        [$from, $group, $name] = $type === ContainerTime::Demurrage
            ? ['system@dmflog.com.br', 'info_demurrage@dmflog.com.br', 'Grupo Demurrage']
            : ['detention@dmflog.com.br', 'detention@dmflog.com.br', 'Detention Group'];

        Mail::html($html, fn ($mail) => $mail
            ->from($from, 'DMF Logistics')
            ->to($group, $name)
            ->subject("Embarque com incidencia de {$label}"));
    }
}
