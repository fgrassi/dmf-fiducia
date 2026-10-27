<?php

namespace App\Actions\Cobranca\Notices;

use App\Enums\Cobranca\ChargeStatus;
use App\Models\FinCharges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * cron/avisoCobrancaAbertaImpo (legado): ao financeiro, as ICs de importação geradas há exatamente 7 dias e ainda em aberto
 * (sem seguro, handling nem clientes com boleto). O legado só listava a última IC (sobrescrevia a linha); aqui vão todas.
 */
class OpenImportChargesNotice
{
    /** @return int ICs listadas */
    public function __invoke(): int
    {
        $charges = FinCharges::with('embarque')
            ->where('status', ChargeStatus::Open)
            ->whereDate('created_at', today()->subDays(7))
            ->whereHas('embarque', fn ($q) => $q
                ->where('impor_expor', 'i')
                ->whereNotIn('tipo_embarque', ['s', 'h'])
                ->whereNotIn('cliente_id', DB::table('fin_clients_boleto')->whereNotNull('ref_client')->select('ref_client')))
            ->orderBy('id')
            ->get();

        if ($charges->isEmpty()) {
            return 0;
        }

        $rows = $charges->map(fn (FinCharges $c) => '<tr><td>'.e($c->embarque->booking).'</td>'
            .'<td>'.e(DB::table('pessoa')->where('pessoa_id', $c->embarque->cliente_id)->value('nome')).'</td>'
            .'<td>'.$c->created_at->format('d/m/Y').'</td>'
            .'<td>'.e(DB::table('armador')->where('armador_id', $c->embarque->armador_id)->value('nome')).'</td></tr>')->implode('');

        $html = "<table width='926px' border=0><tr><td><font face='Arial' color='black'><h3>Aviso: Cobranças em Aberto</h3></font></td></tr>"
            .'<tr><td><p>As cobranças abaixo foram geradas ha 7 dias e ainda não foram pagas</p>'
            ."<table><tr><th>Reserva</th><th>Cliente</th><th>Data</th><th>Armador</th></tr>{$rows}</table></td></tr></table>";

        Mail::html($html, fn ($mail) => $mail->to('financeiro@dmflog.com.br', 'Financeiro')->subject('Cobranças há 7 dias sem pagamento'));

        return $charges->count();
    }
}
