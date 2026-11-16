<?php

namespace App\Actions\Contabilidade;

use Carbon\Carbon;
use DomainException;

/** Período dos relatórios de contabilidade: as duas datas são obrigatórias e a inicial não passa da final (mensagens do legado). */
class ReportPeriod
{
    /**
     * @param  array{data_ini?: ?string, data_fim?: ?string}  $input
     * @return array{0: string, 1: string} inicial e final em Y-m-d
     *
     * @throws DomainException
     */
    public static function parse(array $input): array
    {
        $start = NfseInvoice::date($input['data_ini'] ?? null);
        $end = NfseInvoice::date($input['data_fim'] ?? null);

        if ($start === null || $end === null || ! checkdate((int) substr($start, 5, 2), (int) substr($start, 8, 2), (int) substr($start, 0, 4)) || ! checkdate((int) substr($end, 5, 2), (int) substr($end, 8, 2), (int) substr($end, 0, 4))) {
            throw new DomainException('Informe a data inicial e a data final.');
        }

        if ($start > $end) {
            throw new DomainException('A data inicial não pode ser maior que a data final.');
        }

        return [$start, $end];
    }

    /** "dd/mm/aaaa a dd/mm/aaaa". */
    public static function label(string $start, string $end): string
    {
        return Carbon::parse($start)->format('d/m/Y').' a '.Carbon::parse($end)->format('d/m/Y');
    }
}
