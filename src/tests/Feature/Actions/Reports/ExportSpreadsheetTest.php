<?php

namespace Tests\Feature\Actions\Reports;

use App\Actions\Reports\ExportSpreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ExportSpreadsheetTest extends TestCase
{
    public function test_text_starting_with_formula_characters_is_written_as_text_and_numbers_stay_numeric(): void
    {
        ob_start();
        app(ExportSpreadsheet::class)('Teste', ['Cliente', 'Qtd', 'Valor', 'Fatura'], [
            ['=HYPERLINK("http://x","a")', 3, '12.50', '00123'],
            ['+1+1', 1.5, null, '@SUM(A1)'],
        ])->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, ob_get_clean());

        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        foreach (['A2', 'A3', 'D3'] as $coordinate) {
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($coordinate)->getDataType(), $coordinate);
        }
        $this->assertSame('=HYPERLINK("http://x","a")', $sheet->getCell('A2')->getValue());
        $this->assertSame('00123', $sheet->getCell('D2')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('C2')->getDataType());
    }
}
