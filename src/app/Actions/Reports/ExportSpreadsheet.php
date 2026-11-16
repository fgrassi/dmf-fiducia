<?php

namespace App\Actions\Reports;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Planilha de uma aba só: cabeçalho em negrito e as linhas como vieram (números continuam números). */
class ExportSpreadsheet
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<scalar|null>>  $rows
     */
    public function __invoke(string $title, array $headers, array $rows): StreamedResponse
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $title), 0, 31));

        foreach ([$headers, ...$rows] as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $type = match (true) {
                    is_int($value), is_float($value), is_string($value) && preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/', $value) => DataType::TYPE_NUMERIC, // decimais do banco chegam como string
                    is_bool($value) => DataType::TYPE_BOOL,
                    $value === null => DataType::TYPE_NULL,
                    default => DataType::TYPE_STRING, // texto nunca vira fórmula (=, +, -, @)
                };
                $sheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 1], $value, $type);
            }
        }

        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);

        foreach (range(1, count($headers)) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), str($title)->ascii()->slug('_').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
