<?php

namespace App\Actions\Cobranca\Notices;

use App\Enums\Cobranca\ChargeStatus;
use App\Models\FinCharges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * cron/relacaoCobrancaReservaExpo (legado): relação diária de reservas liberadas para BL. Entram as ICs pagas ainda não
 * relacionadas (IMPO, ou EXPO fora de EXW/FCA; marcadas com status_envio = 1), as EXPO EXW/FCA com saída confirmada hoje e
 * as EXPO de clientes com liberação (sem BL, prazo ou boleto) confirmadas hoje, exceto BL direto. Sai mesmo vazia.
 */
class ReleaseList
{
    /** incoterm 1 EXW, 2 FCA */
    private const EXW_FCA = [1, 2];

    /** @return int linhas da relação */
    public function __invoke(): int
    {
        $rows = [];

        $charges = FinCharges::with('embarque', 'bl')
            ->where('status_envio', 0)
            ->where('status', '<>', ChargeStatus::Open)
            ->whereHas('embarque', fn ($q) => $q->where(fn ($q) => $q
                ->where('impor_expor', 'i')
                ->orWhere(fn ($q) => $q->where('impor_expor', 'e')->where(fn ($q) => $q->whereNull('incoterm_id')->orWhereNotIn('incoterm_id', self::EXW_FCA)))))
            ->get()
            ->sortBy(fn (FinCharges $c) => $c->embarque->impor_expor);

        foreach ($charges as $c) {
            $rows[] = [$c->embarque->booking, $c->bl?->bl, $c->bl?->num_original, $this->client($c->embarque->cliente_id), $this->armador($c->embarque->armador_id)];
            $c->forceFill(['status_envio' => 1])->save();
        }

        $confirmedToday = fn () => DB::table('embarque as e')
            ->join('departure_confirmation as dc', 'dc.ref_embarque', '=', 'e.embarque_id')
            ->whereDate('dc.created_at', today())
            ->where('e.impor_expor', 'e')
            ->select('e.embarque_id', 'e.booking', 'e.house_bl', 'e.cliente_id', 'e.armador_id');

        $exwFca = $confirmedToday()->whereIn('e.incoterm_id', self::EXW_FCA)->get();
        $released = $confirmedToday()
            ->join('pessoa as c', 'c.pessoa_id', '=', 'e.cliente_id')
            ->leftJoin('fin_clients_boleto as b', 'b.ref_client', '=', 'c.pessoa_id')
            ->where(fn ($q) => $q->where('c.liberar_sem_bl', 1)->orWhere('b.prazo_exp', '>', 0)->orWhere('b.boleto_exp', 1))
            ->where('e.bl_direto', 0)
            ->get();

        foreach ($exwFca->concat($released) as $e) {
            $originals = DB::table('bl')->where('embarque_id', $e->embarque_id)->orderBy('bl_id')->pluck('num_original')->implode(',');
            $rows[] = [$e->booking, $e->house_bl, $originals, $this->client($e->cliente_id), $this->armador($e->armador_id)];
        }

        $body = $rows === []
            ? 'Não há nenhuma reserva para ser liberada.'
            : '<table border=1><tr><th>Booking</th><th>BL</th><th>Originais</th><th>Cliente</th><th>Armador</th></tr>'
                .collect($rows)->map(fn (array $r) => '<tr>'.collect($r)->map(fn ($v) => '<td>'.e($v).'</td>')->implode('').'</tr>')->implode('')
                .'</table>';

        $html = "<table width='100%' border=0><tr bgcolor='#7e0001'><td></td></tr>"
            ."<tr><td><font face='Arial' color='black'><h4>Segue abaixo a relação de reservas autorizadas para liberação de BL.</h4></font></td></tr>"
            ."<tr><td>{$body}</td></tr></table>";

        Mail::html($html, fn ($mail) => $mail
            ->to(['financeiro@dmflog.com.br', 'diego@dmflog.com.br', 'docexp@dmflog.com.br'])
            ->cc('it@dmflog.com.br')
            ->subject('Relação de embarques para liberação '.today()->format('d-m-Y')));

        return count($rows);
    }

    /** Embarque::getClientName: nome (CNPJ). */
    private function client(?int $id): string
    {
        $c = DB::table('pessoa')->where('pessoa_id', $id)->first(['nome', 'cnpj']);

        return $c ? "{$c->nome} ({$c->cnpj})" : '';
    }

    private function armador(?int $id): string
    {
        return (string) DB::table('armador')->where('armador_id', $id)->value('nome');
    }
}
