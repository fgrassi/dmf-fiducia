<?php

namespace App\Actions\Cobranca\Notices;

use App\Actions\Cobranca\Nfse\PendingTakers;
use App\Actions\Notices\RenderNoticeEmail;
use App\Actions\Notices\SendNoticeMail;
use App\Enums\Notice;
use App\Filament\Pages\TakerCheck;

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
        if (! Notice::PendingTakers->enabled()) {
            return 0;
        }

        $from = today()->toDateString();
        $to = today()->addDays(5)->toDateString();
        $rows = ($this->pending)($from, $to);

        if ($rows === []) {
            return 0;
        }

        $count = count($rows);
        $lines = collect($rows)->map(fn (array $r) => '<tr><td>'.e($r['nome']).'</td><td>'.e($r['cnpj']).'</td><td>'
            .collect($r['pendencias'])->map(fn ($p) => e($p))->implode('<br>').'</td></tr>')->implode('');

        $list = '<table border="1" cellpadding="6" cellspacing="0"><tr bgcolor="#7e0001"><td><font face="Arial" color="white">Nome</font></td>'
            .'<td><font face="Arial" color="white">CNPJ</font></td><td><font face="Arial" color="white">Pendências</font></td></tr>'
            .$lines.'</table>';

        [$subject, $html] = app(RenderNoticeEmail::class)(Notice::PendingTakers, [
            'data' => today()->format('d/m/Y'),
            'total' => $count,
            'tomadores' => $count === 1 ? 'tomador' : 'tomadores',
            'de' => today()->format('d/m/Y'),
            'ate' => today()->addDays(5)->format('d/m/Y'),
            'link' => TakerCheck::getUrl(['data_inicio' => $from, 'data_fim' => $to]),
            'lista' => $list,
        ]);

        app(SendNoticeMail::class)($subject, $html, Notice::PendingTakers->recipients());

        return $count;
    }
}
