<?php

namespace App\Filament\Support;

use Filament\Support\ArrayRecord;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Ordena e pagina em memória as linhas de um relatório que devolve arrays, no formato que o `Table::records()` espera.
 */
class ReportRecords
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function paginate(array $rows, int $page, int|string $recordsPerPage, ?string $sortColumn = null, ?string $sortDirection = 'asc'): LengthAwarePaginator
    {
        $rows = array_map(fn (array $row, int $position) => $row + [ArrayRecord::getKeyName() => (string) $position], array_values($rows), array_keys(array_values($rows)));

        if ($sortColumn) {
            $direction = $sortDirection === 'desc' ? -1 : 1;
            usort($rows, fn (array $a, array $b) => ($a[$sortColumn] <=> $b[$sortColumn]) * $direction);
        }

        $perPage = (int) $recordsPerPage;

        return new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page);
    }
}
