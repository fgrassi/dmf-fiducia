<?php

namespace App\Actions\Reports;

use Illuminate\Support\Facades\DB;

/** Dias entre duas colunas de data, como o DATEDIFF do MySQL (o SQLite dos testes não tem DATEDIFF). */
class SqlDays
{
    public static function between(string $end, string $start): string
    {
        return DB::getDriverName() === 'sqlite' ? "(julianday({$end}) - julianday({$start}))" : "DATEDIFF({$end}, {$start})";
    }
}
