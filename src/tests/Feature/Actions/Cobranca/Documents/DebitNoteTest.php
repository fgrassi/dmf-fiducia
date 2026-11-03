<?php

namespace Tests\Feature\Actions\Cobranca\Documents;

use App\Actions\Cobranca\Documents\DebitNote;
use App\Models\FinCharges;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class DebitNoteTest extends TestCase
{
    use BuildsCharges;
    use LegacySchema;

    /** @return list<string> */
    private function labels(FinCharges $charge): array
    {
        ob_start();
        app(DebitNote::class)($charge)->sendContent();
        $file = tempnam(sys_get_temp_dir(), 'nd').'.xls';
        file_put_contents($file, ob_get_clean());

        $column = IOFactory::load($file)->getActiveSheet()->rangeToArray('A1:A60', null, true, false);
        unlink($file);

        return array_column($column, 0);
    }

    public function test_iof_line_is_printed_even_when_the_charge_iof_is_zero(): void
    {
        $this->usdRate();
        $charge = $this->charge(['valor_iof' => 0]);
        $this->item($charge, 100.0);

        $this->assertContains('Despesas Financeiras/IOF', $this->labels($charge->fresh()));
    }

    public function test_iof_line_is_omitted_when_the_charge_has_no_iof_value(): void
    {
        $this->usdRate();
        $charge = $this->charge(['valor_iof' => null]);
        $this->item($charge, 100.0);

        $this->assertNotContains('Despesas Financeiras/IOF', $this->labels($charge->fresh()));
    }
}
