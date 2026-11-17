<?php

namespace App\Actions\Booking\Bls;

use App\Actions\Booking\BookingLog;
use App\Models\Bl;
use App\Models\Container;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * executeUpdateContainer e executeRemoveContainer (legado): os equipamentos do BL.
 * O que sai do BL leva o demurrage junto; o que entra abre o controle de demurrage (impo) ou de detention (expo).
 */
class SaveBlContainers
{
    private const COLUMNS = ['tipo_container_id', 'numero', 'tara', 'seals_n1', 'seals_n2', 'seals_n3', 'qty_pkgs', 'gross_weight', 'net_weight', 'volume', 'temperatura'];

    public function __construct(private BookingLog $log, private BlWarnings $warnings) {}

    /** @param list<array<string, mixed>> $rows */
    public function __invoke(Embarque $booking, Bl $bl, array $rows, int $userId): void
    {
        $export = $booking->impor_expor === 'e';
        $kept = [];

        foreach ($rows as $row) {
            $data = collect($row)->only(self::COLUMNS)->map(fn ($value) => $value === '' ? null : $value)->all();

            if ($export && Container::isReefer($data['tipo_container_id'] ?? null) && ($data['temperatura'] ?? null) === null) {
                throw new DomainException('É preciso informar a Temperatura para container REEFER.');
            }

            if (($validator = BlContainerRules::validator($data, $export))->fails()) {
                throw new DomainException($validator->errors()->first());
            }

            $container = filled($row['container_id'] ?? null) ? Container::where('bl_id', $bl->getKey())->find($row['container_id']) : null;
            $container ??= new Container(['bl_id' => $bl->getKey()]);
            $container->fill($data)->save();

            $kept[] = $container->getKey();
        }

        Container::where('bl_id', $bl->getKey())->whereNotIn('container_id', $kept)->get()
            ->each(fn (Container $container) => $this->remove($booking, $container, $userId));

        if ($kept === []) {
            return;
        }

        if ($this->warnings->lclVolumeDiverges($booking, $bl)) {
            $this->alertLclDivergence($booking, $bl);
        }

        $this->alertFinanceLcl($booking);

        foreach (Container::whereIn('container_id', $kept)->get() as $container) {
            $export ? $this->openDetention($booking, $container, $userId) : $this->openDemurrage($booking, $container, $userId);
        }

        if (! $export) {
            $this->dropOrphanDemurrages($booking, $userId);
        }
    }

    /** executeRemoveContainer: tira o equipamento do BL e do demurrage da reserva, e deixa no rastreamento. */
    private function remove(Embarque $booking, Container $container, int $userId): void
    {
        $type = (string) DB::table('tipo_container')->where('tipo_container_id', $container->tipo_container_id)->value('nome');

        DB::table('fin_demurrage')->where('container', $container->numero)->where('ref_embarque', $booking->getKey())->delete();
        $this->log->track(
            $booking->getKey(),
            'Equipamento removido da aba Demurrage',
            "O Equipamento {$container->numero}, tipo {$type} foi removido da aba demurrage pois ele foi excluído do BL.",
            $userId,
        );

        $container->delete();
    }

    private function openDemurrage(Embarque $booking, Container $container, int $userId): void
    {
        if (DB::table('fin_demurrage')->where('ref_embarque', $booking->getKey())->where('container', $container->numero)->exists()) {
            return;
        }

        $freeTime = DB::table('mercadoria')
            ->where('tipo_container_id', $container->tipo_container_id)
            ->where('embarque_id', $booking->getKey())
            ->where('free_time', '<>', '')
            ->value('free_time');

        DB::table('fin_demurrage')->insert([
            'container' => $container->numero,
            'ref_embarque' => $booking->getKey(),
            'ref_tipo_container' => $container->tipo_container_id,
            'free_time' => $freeTime ?? $booking->free_time,
            'created_by' => $userId,
            'created_at' => now()->toDateString(),
        ]);
    }

    private function openDetention(Embarque $booking, Container $container, int $userId): void
    {
        if (DB::table('fin_detention')->where('ref_embarque', $booking->getKey())->where('container', $container->numero)->exists()) {
            return;
        }

        DB::table('fin_detention')->insert([
            'container' => $container->numero,
            'ref_embarque' => $booking->getKey(),
            'ref_tipo_container' => $container->tipo_container_id,
            'created_by' => $userId,
            'created_at' => now()->toDateString(),
        ]);
    }

    /** "Verifica demurrage órfãos": o equipamento que não consta mais em nenhum BL da reserva sai do demurrage. */
    private function dropOrphanDemurrages(Embarque $booking, int $userId): void
    {
        $numbers = DB::table('container as c')->join('bl as b', 'b.bl_id', '=', 'c.bl_id')->where('b.embarque_id', $booking->getKey())->pluck('c.numero')->all();

        DB::table('fin_demurrage as d')
            ->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'd.ref_tipo_container')
            ->where('d.ref_embarque', $booking->getKey())
            ->whereNotIn('d.container', $numbers)
            ->get(['d.id', 'd.container', 't.nome as type'])
            ->each(function (object $demurrage) use ($booking, $userId): void {
                $this->log->track(
                    $booking->getKey(),
                    'Equipamento removido da aba Demurrage',
                    "O Equipamento {$demurrage->container}, tipo {$demurrage->type} foi removido da aba demurrage pois ele não constava mais no BL.",
                    $userId,
                );
                DB::table('fin_demurrage')->where('id', $demurrage->id)->delete();
            });
    }

    /** executeEnviaEmailLcl: o volume LCL do BL não bate com a reserva. */
    private function alertLclDivergence(Embarque $booking, Bl $bl): void
    {
        $list = fn ($items) => '<ul>'.collect($items)->map(fn (string $item) => '<li>'.e($item).'</li>')->implode('').'</ul>';
        $client = (string) DB::table('pessoa')->where('pessoa_id', $booking->pessoa_id_cliente)->value('nome');

        $drafts = Container::where('bl_id', $bl->getKey())->where('tipo_container_id', Container::LCL)->get()->map(fn (Container $c) => "{$c->numero} - {$c->volume}");
        $goods = DB::table('mercadoria')->where('embarque_id', $booking->getKey())->where('tipo_container_id', Container::LCL)->get()->map(fn (object $m) => "{$m->nome} - ".number_format((float) $m->cubature, 2, ',', '.'));

        Mail::html(
            '<p>O volume do equipamento LCL no BL '.e($bl->bl).' está divergente do que foi informado na reserva. Gentileza verificar!</p>'
                .'<p>Equipamentos no Draft '.e($bl->bl).'</p>'.$list($drafts)
                .'<p>Equipamentos na Reserva '.e($booking->booking).'</p>'.$list($goods),
            fn ($mail) => $mail->to('exportacao@dmflog.com.br')->subject("Volume de LCL divergente entre Reserva e Draft: {$booking->booking} - {$bl->bl} - {$client}"),
        );
    }

    /** emitirAlertaLCL: importação com notas já cadastradas e equipamento do BL alterado. */
    private function alertFinanceLcl(Embarque $booking): void
    {
        if ($booking->impor_expor !== 'i' || (! $booking->debitNotes()->exists() && ! $booking->creditNotes()->exists())) {
            return;
        }

        Mail::html(
            '<p>O embarque '.e($booking->booking).' possui Notas Cadastradas e os dados do Container foram atualizados</p>',
            fn ($mail) => $mail->to('financeiro@dmflog.com.br')->subject('[FINANCEIRO] Alerta de Alterações - LCL: '.$booking->booking),
        );
    }
}
