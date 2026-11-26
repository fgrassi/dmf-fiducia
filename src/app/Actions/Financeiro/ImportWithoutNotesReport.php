<?php

namespace App\Actions\Financeiro;

use App\Actions\Notices\RenderNoticeEmail;
use App\Actions\Notices\SendNoticeMail;
use App\Enums\Notice;
use Illuminate\Support\Facades\DB;

/**
 * cron/enviaRelatorioImpoSemNotas (legado): no último dia do mês, lista ao TI e ao financeiro as importações com chegada confirmada
 * no mês sem nota de débito, de crédito nem arquivo de nota. Sem nenhuma, não envia.
 *
 * Diferença do legado: o mês do assunto sai em maiúsculas também no acento (o legado escrevia "MARçO").
 */
class ImportWithoutNotesReport
{
    /** emb_embarque_files.ref_file_type dos arquivos de nota. */
    private const NOTE_FILE_TYPES = [4, 5];

    /** @return int reservas listadas */
    public function __invoke(): int
    {
        if (! Notice::ImportWithoutNotes->enabled() || ! today()->isLastOfMonth()) {
            return 0;
        }

        $linked = fn (string $table) => fn ($q) => $q->from($table)->whereColumn("{$table}.ref_embarque", 'e.embarque_id');

        $bookings = DB::table('embarque as e')
            ->where('e.impor_expor', 'i')
            ->whereBetween('e.chegada_efetiva', [today()->startOfMonth()->toDateString(), today()->toDateString()])
            ->whereExists($linked('arrival_confirmation'))
            ->whereNotExists($linked('fin_debit_notes'))
            ->whereNotExists($linked('fin_credit_notes'))
            ->whereNotExists(fn ($q) => $linked('emb_embarque_files')($q)->whereIn('ref_file_type', self::NOTE_FILE_TYPES))
            ->orderBy('e.embarque_id')
            ->pluck('e.booking');

        if ($bookings->isEmpty()) {
            return 0;
        }

        [$subject, $html] = app(RenderNoticeEmail::class)(Notice::ImportWithoutNotes, [
            'mes' => mb_strtoupper(today()->locale('pt_BR')->translatedFormat('F')),
            'ano' => today()->year,
            'lista' => '<ul>'.$bookings->map(fn ($booking) => '<li>'.e($booking).'</li>')->implode('').'</ul>',
        ]);
        app(SendNoticeMail::class)($subject, $html, Notice::ImportWithoutNotes->recipients());

        return $bookings->count();
    }
}
