<?php

namespace App\Actions\Booking\Bls;

use App\Actions\Booking\BookingLog;
use App\Models\Bl;
use App\Models\Embarque;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * executeUpdateAprovacaoBl (legado): cria ou altera o BL, registra o que ficou no rastreamento
 * e mantém na reserva a lista de BLs, MBLs e invoices. O RNC do BL completa o do importador se ele não tiver.
 * Um BL já aprovado que é alterado ganha um PDF novo; "salvar e finalizar" aprova o BL e manda a cópia (FinalizeBl).
 */
class SaveBl
{
    /** Freight payable at do BL => tipo_frete da reserva (p, c, a). */
    private const FREIGHT_TYPE = ['ORIGIN' => 'p', 'DESTINATION' => 'c', 'ABROAD' => 'a'];

    public function __construct(
        private BookingLog $log,
        private SaveBlContainers $containers,
        private ReeferTemperature $reefer,
        private FinalizeBl $finalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $data  campos do BL; `containers` (lista), `addShipper` e `addCnee` não são colunas
     */
    public function __invoke(Embarque $booking, ?Bl $bl, array $data, int $userId, bool $finalize = false): Bl
    {
        $containers = Arr::pull($data, 'containers');
        $saveShipper = (bool) Arr::pull($data, 'addShipper');
        $saveConsignee = (bool) Arr::pull($data, 'addCnee');

        $regenerate = $bl?->status === Bl::APPROVED;

        $bl = DB::transaction(function () use ($booking, $bl, $data, $containers, $saveShipper, $saveConsignee, $userId) {
            $isNew = ! $bl;
            $bl ??= new Bl(['embarque_id' => $booking->getKey(), 'status' => Bl::OPEN, 'usuario_id' => $userId]);
            $bl->fill($data);
            $bl->numImpressao ??= Bl::DEFAULT_PRINTS;

            $this->alertFreightMismatch($booking, $bl, $userId);

            $bl->save();

            $this->log->track(
                $booking->getKey(),
                ($isNew ? 'Cadastro' : 'Atualizado').': HBL',
                $isNew ? 'BL Cadastrado no Sistema' : $bl->trackingHistory(),
                $userId,
                $isNew ? 'i' : 'u',
            );

            $this->saveParties($booking, $bl, $saveShipper, $saveConsignee);

            if (is_array($containers)) {
                ($this->containers)($booking, $bl, $containers, $userId);
            }

            $this->reefer->notify($booking);
            $this->syncBooking($booking);
            $this->completeImporterRnc($booking, $bl);

            return $bl;
        });

        if ($finalize) {
            ($this->finalizer)($booking, $bl);
        } elseif ($regenerate) {
            $this->finalizer->store($booking, $bl);
        }

        return $bl;
    }

    /** executeDeleteAprovacaoBl (legado): o que foi apagado fica no rastreamento. */
    public function delete(Embarque $booking, Bl $bl, int $userId): void
    {
        DB::transaction(function () use ($booking, $bl, $userId) {
            $history = $bl->trackingHistory();
            $bl->delete();

            $this->log->track($booking->getKey(), 'Apagado: BL', $history, $userId, 'd');
            $this->syncBooking($booking);
        });
    }

    /** BlForm::validateForm (legado): o frete do BL difere do local de pagamento da reserva, e Impo/Doc ou Expo/Draft ficam sabendo. */
    private function alertFreightMismatch(Embarque $booking, Bl $bl, int $userId): void
    {
        $freightAt = (string) $bl->freight_at;

        if ($freightAt === '' || (self::FREIGHT_TYPE[$freightAt] ?? null) === $booking->tipo_frete) {
            return;
        }

        $client = (string) DB::table('pessoa')->where('pessoa_id', $booking->cliente_id)->value('nome');
        $bookingFreight = match ($booking->tipo_frete) {
            'p' => 'PP',
            'c' => 'CC',
            'a' => 'ABROAD',
            default => 'N/D',
        };

        Mail::html(
            '<p>Gentileza notar que há inconsistência entre a informação de local de pagamento do frete e as depesas lançadas no sistema. Gentileza verificar.</p><ul>'
                .'<li>Reserva: '.e($booking->booking).'</li>'
                .'<li>BL: '.e($bl->bl).'</li>'
                .'<li>Cliente: '.e($client).'</li>'
                .'<li>Local de Pagamento do Frete (BL): '.e($freightAt).'</li>'
                ."<li>Local de Pagamento do Frete (Reserva): {$bookingFreight}</li></ul>",
            fn ($mail) => $mail
                ->to($booking->impor_expor === 'i' ? ['importacao@dmflog.com.br', 'docimportacao@dmflog.com.br'] : ['exportacao@dmflog.com.br', 'draft@dmflog.com.br'])
                ->subject('[ALERTA] Inconsistência de Local de Pagamento de Frete: '.$booking->booking),
        );

        $this->log->track($booking->getKey(), 'Inconsistência de Local de Pagamento de Frete', 'Inconsistência de Local de Pagamento de Frete', $userId, 'u');
    }

    /** "Salvar dados" (cmn_dados_bl): guarda o shipper/consignee digitado para ser reaproveitado nos próximos BLs do cliente/importador. */
    private function saveParties(Embarque $booking, Bl $bl, bool $shipper, bool $consignee): void
    {
        if ($shipper) {
            DB::table('cmn_dados_bl')->insert(['dados' => (string) $bl->shipper, 'ref_pessoa' => $booking->pessoa_id_cliente]);
        }

        if ($consignee) {
            DB::table('cmn_dados_bl')->insert(['dados' => (string) $bl->consignee, 'ref_pessoa' => $booking->pessoa_id_importador]);
        }
    }

    /** House BL, MBL e invoices da reserva são a lista de BLs, sem repetir. Sem nenhum invoice nos BLs o da reserva fica como está. */
    private function syncBooking(Embarque $booking): void
    {
        $bls = $booking->bls()->orderBy('bl')->get();
        $invoices = $bls->pluck('invoice')->filter()->unique()->implode('/');

        $booking->forceFill([
            'qtd_bl' => $bls->count(),
            'house_bl' => $bls->pluck('bl')->implode(' / '),
            'master_bl' => $bls->pluck('mbl_number')->filter()->unique()->implode('/'),
            ...($invoices !== '' ? ['invoice' => $invoices] : []),
        ])->save();
    }

    private function completeImporterRnc(Embarque $booking, Bl $bl): void
    {
        if (blank($bl->rnc) || ! $booking->pessoa_id_importador) {
            return;
        }

        DB::table('pessoa')
            ->where('pessoa_id', $booking->pessoa_id_importador)
            ->where(fn ($query) => $query->whereNull('rnc')->orWhere('rnc', '')->orWhere('rnc', 'N/A'))
            ->update(['rnc' => $bl->rnc]);
    }
}
