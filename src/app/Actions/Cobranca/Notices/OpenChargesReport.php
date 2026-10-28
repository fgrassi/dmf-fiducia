<?php

namespace App\Actions\Cobranca\Notices;

use App\Enums\Cobranca\ChargeStatus;
use App\Models\FinCharges;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * cron/relatorioCobrancaAberta (legado): relatório interno das ICs marítimas/aéreas em aberto há 7 dias ou mais, com os dias
 * desde a saída (EXPO) ou chegada (IMPO) efetiva e desde a geração. Como no legado, a coluna de data mostra sempre a saída
 * efetiva, mesmo em IMPO (onde a diferença usa a chegada).
 */
class OpenChargesReport
{
    /** @return int ICs no relatório */
    public function __invoke(): int
    {
        $charges = FinCharges::with('embarque', 'bl', 'type')
            ->where('status', ChargeStatus::Open)
            ->whereDate('created_at', '<=', today()->subDays(7))
            ->whereHas('embarque', fn ($q) => $q->whereIn('tipo_embarque', ['m', 'a']))
            ->orderBy('id')
            ->get();

        if ($charges->isEmpty()) {
            return 0;
        }

        $rows = $charges->map(function (FinCharges $c) {
            $e = $c->embarque;
            $vessel = $e->impor_expor === 'e' ? $e->saida_efetiva : $e->chegada_efetiva;
            $vesselDate = filled($vessel) && ! str_starts_with($vessel, '0000') ? Carbon::parse($vessel)->startOfDay() : null;
            $exit = filled($e->saida_efetiva) && ! str_starts_with($e->saida_efetiva, '0000') ? Carbon::parse($e->saida_efetiva)->format('d/m/Y') : '';

            return '<tr><td>'.e($c->type?->name).'</td>'
                .'<td>'.e(DB::table('pessoa')->where('pessoa_id', $e->cliente_id)->value('nome')).'</td>'
                .'<td>'.e(DB::table('armador')->where('armador_id', $e->armador_id)->value('nome')).'</td>'
                .'<td>'.e($e->booking).'</td>'
                .'<td>'.e($c->bl?->bl ?: ($c->bl ? 'nameless BL' : '')).'</td>'
                .'<td>'.$c->created_at->format('d/m/Y').'</td>'
                .'<td>'.$exit.'</td>'
                .'<td>'.(int) $vesselDate?->diffInDays(today()).'</td>'
                .'<td>'.(int) $c->created_at->diffInDays(today()).'</td></tr>';
        })->implode('');

        $html = '<p>Segue abaixo a relação de Cobranças em aberto a 7 dias+</p><table border=1><tr><th>Tipo</th><th>Cliente</th><th>Armador</th>'
            ."<th>Booking</th><th>HBL</th><th>Data da Cobrança</th><th>Saída/Chegada Efetiva</th><th>Diferença Navio</th><th>Diferença Cobrança</th></tr>{$rows}</table>";

        Mail::html($html, fn ($mail) => $mail
            ->to(['cobranca@dmflog.com.br', 'chamado@dmflog.com.br'])
            ->subject('[RELATÓRIO] Cobranças em aberto 7 dias+'));

        return $charges->count();
    }
}
