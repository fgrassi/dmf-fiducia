<?php

namespace App\Actions\ControlePa;

use App\Enums\Notice;
use App\Models\ControlePa;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * cron/controlePaAviso* (legado): cinco e-mails ao financeiro com as reservas prepaid abroad paradas em cada etapa da Freight
 * Invoice. Cada método devolve quantas reservas foram avisadas (0 = nada enviado).
 *
 * Como no legado, as quatro primeiras só olham reservas que não estão em nenhuma caixa (controle_caixa_embarque) e não filtram
 * reserva cancelada; só `confirmedWithoutInvoice` filtra por finalizado.
 */
class ControlePaNotices
{
    private const FROM = 'financeiro@dmflog.com.br';

    /** controlePaAvisoEnvioAgente: FI da DMF ainda não enviada ao agente, com a saída confirmada. */
    public function readyToSendToAgent(): int
    {
        return $this->send(
            Notice::ReadyToSendToAgent,
            'PREPAID ABROAD - Freight Invoices disponíveis para envio ao agente',
            'As reservas abaixo possuem Freight Invoice disponíveis para envio ao agente',
            $this->controls()
                ->whereExists(fn (Builder $q) => $q->from('departure_confirmation as dc')->whereColumn('dc.ref_embarque', 'e.embarque_id'))
                ->where('cp.flag_fi_dmf', '>=', 0)
                ->whereNull('cp.data_envio_fi_agente')
                ->where('cp.flag_fi_armador', 0),
            ['Saida Prevista', 'Chegada Prevista', 'Importador', 'Exportador', 'Agente', 'Status'],
            fn ($r) => [$this->date($r->saida_prevista), $this->date($r->chegada_prevista), $r->importador, $r->exportador, $r->agente, ControlePa::DMF_STATUSES[(int) $r->flag_fi_dmf] ?? ''],
        );
    }

    /** controlePaAvisoEnvioArmador: FI enviada ao agente, falta a do armador. */
    public function readyToSendToCarrier(): int
    {
        return $this->send(
            Notice::ReadyToSendToCarrier,
            'PREPAID ABROAD - Freight Invoices disponíveis para envio ao Armador',
            'As reservas abaixo possuem Freight Invoice disponíveis para envio ao Armador',
            $this->controls()
                ->where('cp.flag_fi_dmf', '>', 0)
                ->whereNotNull('cp.data_envio_fi_agente')
                ->where('cp.flag_fi_armador', 0),
            ['Saida Prevista', 'Chegada Prevista', 'Importador', 'Exportador', 'Agente', 'Armador', 'Status'],
            fn ($r) => [$this->date($r->saida_prevista), $this->date($r->chegada_prevista), $r->importador, $r->exportador, $r->agente, $r->armador, ControlePa::CARRIER_STATUSES[(int) $r->flag_fi_armador] ?? ''],
        );
    }

    /** controlePaAvisoPendenciaConfirmacao: frete pago ao armador, recibo dele ainda não chegou. */
    public function pendingReceipt(): int
    {
        return $this->send(
            Notice::PendingReceipt,
            'PREPAID ABROAD - Pendências de Confirmação da Liberação',
            'As reservas abaixo possuem Pendências de Confirmação da Liberação',
            $this->controls()
                ->where('cp.flag_fi_dmf', '>', 0)
                ->whereNotNull('cp.data_envio_fi_agente')
                ->where('cp.flag_fi_armador', '>', 0)
                ->whereNotNull('cp.data_pgto_armador')
                ->whereNull('cp.data_recibo_armador'),
            ['Saida Prevista', 'Chegada Prevista', 'Importador', 'Exportador', 'Agente', 'Armador', 'Data Pagamento Armador'],
            fn ($r) => [$this->date($r->saida_prevista), $this->date($r->chegada_prevista), $r->importador, $r->exportador, $r->agente, $r->armador, $this->date($r->data_pgto_armador)],
        );
    }

    /** controlePaAvisoPendenciaPagamento: FI do armador recebida, frete ainda não pago. */
    public function pendingPayment(): int
    {
        return $this->send(
            Notice::PendingPayment,
            'PREPAID ABROAD - Pendências de Pagamento de Frete',
            'As reservas abaixo possuem Pendência de Pagamento de Frete ao Armador',
            $this->controls()
                ->where('cp.flag_fi_dmf', '>', 0)
                ->whereNotNull('cp.data_envio_fi_agente')
                ->where('cp.flag_fi_armador', '>', 0)
                ->whereNull('cp.data_pgto_armador'),
            ['Saida Prevista', 'Chegada Prevista', 'Importador', 'Exportador', 'Agente', 'Armador'],
            fn ($r) => [$this->date($r->saida_prevista), $this->date($r->chegada_prevista), $r->importador, $r->exportador, $r->agente, $r->armador],
        );
    }

    /** controlePaAvisoReservasConfirmadas: saída confirmada e despesa de frete (billing f), mas sem controle PA. */
    public function confirmedWithoutInvoice(): int
    {
        $bookings = $this->bookings(DB::table('embarque as e'))
            ->whereExists(fn (Builder $q) => $q->from('departure_confirmation as dc')->whereColumn('dc.ref_embarque', 'e.embarque_id'))
            ->whereExists(fn (Builder $q) => $q->from('fin_expenses_values as fev')->whereColumn('fev.ref_embarque', 'e.embarque_id')->where('fev.billing', 'f'))
            ->whereNotExists(fn (Builder $q) => $q->from('controle_pa as cp')->whereColumn('cp.embarque_id', 'e.embarque_id'))
            ->whereNotIn('e.finalizado', ['c', 'e']);

        return $this->send(
            Notice::ConfirmedWithoutInvoice,
            'PREPAID ABROAD - Embarques Confirmados',
            'As reservas abaixo tiveram seu embarque confirmado e não tem Freigth Invoice',
            $bookings,
            ['Saida Efetiva', 'Chegada Prevista', 'Importador', 'Exportador', 'Agente', 'Armador'],
            fn ($r) => [$this->date($r->saida_efetiva), $this->date($r->chegada_prevista), $r->importador, $r->exportador, $r->agente, $r->armador],
        );
    }

    /** Controle PA de reservas fora de qualquer caixa. */
    private function controls(): Builder
    {
        return $this->bookings(DB::table('controle_pa as cp')->join('embarque as e', 'e.embarque_id', '=', 'cp.embarque_id'))
            ->whereNotExists(fn (Builder $q) => $q->from('controle_caixa_embarque as cce')->whereColumn('cce.embarque_id', 'e.embarque_id'))
            ->addSelect('cp.flag_fi_dmf', 'cp.flag_fi_armador', 'cp.data_pgto_armador');
    }

    /** Reserva com os nomes que as cinco tabelas mostram. */
    private function bookings(Builder $query): Builder
    {
        return $query
            ->leftJoin('pessoa as imp', 'imp.pessoa_id', '=', 'e.pessoa_id_importador')
            ->leftJoin('pessoa as exp', 'exp.pessoa_id', '=', 'e.pessoa_id_cliente')
            ->leftJoin('pessoa as ag', 'ag.pessoa_id', '=', 'e.pessoa_id_representante')
            ->leftJoin('armador as ar', 'ar.armador_id', '=', 'e.armador_id')
            ->orderBy('e.embarque_id')
            ->select('e.booking', 'e.saida_prevista', 'e.saida_efetiva', 'e.chegada_prevista', 'imp.nome as importador', 'exp.nome as exportador', 'ag.nome as agente', 'ar.nome as armador');
    }

    /**
     * @param  list<string>  $headers  colunas depois de "Reserva"
     * @param  callable(object): list<mixed>  $cells
     */
    private function send(Notice $notice, string $subject, string $intro, Builder $query, array $headers, callable $cells): int
    {
        if (! $notice->enabled()) {
            return 0;
        }

        /** @var Collection<int, object> $rows */
        $rows = $query->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $html = $intro.'<table border="1"><tr><th>Reserva</th>'.collect($headers)->map(fn ($h) => "<th>{$h}</th>")->implode('').'</tr>'
            .$rows->map(fn ($r) => '<tr><td>'.e($r->booking).'</td>'.collect($cells($r))->map(fn ($c) => '<td>'.e($c).'</td>')->implode('').'</tr>')->implode('')
            .'</table>';

        Mail::html($html, fn ($mail) => $mail->from(self::FROM)->to($notice->recipients())->subject($subject));

        return $rows->count();
    }

    private function date(?string $value): string
    {
        return filled($value) && ! str_starts_with($value, '0000') ? Carbon::parse($value)->format('d/m/Y') : '';
    }
}
