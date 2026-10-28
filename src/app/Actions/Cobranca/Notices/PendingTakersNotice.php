<?php

namespace App\Actions\Cobranca\Notices;

use App\Actions\Cobranca\Nfse\PendingTakers;
use App\Filament\Pages\TakerCheck;
use Illuminate\Support\Facades\Mail;

/**
 * cron/avisoPendenciaTomador (legado): avisa o TI dos tomadores com pendência de cadastro nos BLs dos próximos 5 dias
 * (saída prevista na exportação, chegada prevista na importação), com o link da tela para corrigir.
 */
class PendingTakersNotice
{
    public function __construct(private PendingTakers $pending) {}

    /** @return int tomadores listados */
    public function __invoke(): int
    {
        $from = today()->toDateString();
        $to = today()->addDays(5)->toDateString();
        $rows = ($this->pending)($from, $to);

        if ($rows === []) {
            return 0;
        }

        $count = count($rows);
        $lines = collect($rows)->map(fn (array $r) => '<tr><td>'.e($r['nome']).'</td><td>'.e($r['cnpj']).'</td><td>'
            .collect($r['pendencias'])->map(fn ($p) => e($p))->implode('<br>').'</td></tr>')->implode('');

        $html = "<p>Há <strong>{$count}</strong> ".($count === 1 ? 'tomador' : 'tomadores').' com pendência de cadastro entre '
            .today()->format('d/m/Y').' e '.today()->addDays(5)->format('d/m/Y').'.</p>'
            .'<p><a href="'.e(TakerCheck::getUrl(['data_inicio' => $from, 'data_fim' => $to])).'">Abrir a lista de tomadores</a></p>'
            .'<table border="1" cellpadding="6" cellspacing="0"><tr bgcolor="#7e0001"><td><font face="Arial" color="white">Nome</font></td>'
            .'<td><font face="Arial" color="white">CNPJ</font></td><td><font face="Arial" color="white">Pendências</font></td></tr>'
            .$lines.'</table>';

        Mail::html($html, fn ($mail) => $mail->to('it@dmflog.com.br', 'TI')->subject('DMF Logistics: tomadores com pendência de cadastro - '.today()->format('d/m/Y')));

        return $count;
    }
}
