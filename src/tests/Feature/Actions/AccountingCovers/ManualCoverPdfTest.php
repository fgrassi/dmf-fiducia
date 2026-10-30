<?php

namespace Tests\Feature\Actions\AccountingCovers;

use App\Actions\AccountingCovers\ManualCoverPdf;
use App\Actions\AccountingCovers\ManualCoverSheet;
use App\Models\CapaProcessoManual;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ManualCoverPdfTest extends TestCase
{
    use LegacySchema;

    public function test_sheet_converts_the_foreign_sides_and_shows_the_usd_eur_profit(): void
    {
        $cover = $this->cover(['ref_currency_br' => 1, 'ref_currency_sr' => 2, 'buying_rate' => 100, 'selling_rate' => 250, 'valor_cotacao' => 5, 'moeda_usd_eur' => 2, 'valor_usd_eur' => 10, 'nome_cliente' => '', 'payment_date' => '2026-03-10']);

        $sheet = app(ManualCoverSheet::class)($cover);

        $this->assertSame(
            ['Armazenagem', 'Cliente SA', 'MARÇO/2026', '7', 'R$ 100,00', 'R$ 1.250,00', 'R$ 1.150,00', 'US$ 10,00', 'R$ 50,00'],
            [$sheet['expense'], $sheet['client'], $sheet['month_year'], $sheet['number'], $sheet['cost'], $sheet['revenue'], $sheet['difference'], $sheet['foreign_profit'], $sheet['conversion']],
        );
    }

    public function test_sheet_prefers_the_free_client_name_and_zeroes_the_profit_without_a_currency(): void
    {
        $cover = $this->cover(['nome_cliente' => 'Fulano', 'ref_currency_br' => 2, 'buying_rate' => 100, 'selling_rate' => 700, 'valor_cotacao' => 5, 'valor_usd_eur' => 10]);

        $sheet = app(ManualCoverSheet::class)($cover);

        $this->assertSame(['Fulano', '', 'R$ 500,00', 'R$ 700,00', 'R$ 0,00', 'R$ 0,00'], [$sheet['client'], $sheet['month_year'], $sheet['cost'], $sheet['revenue'], $sheet['foreign_profit'], $sheet['conversion']]);
    }

    public function test_renders_a_single_page_pdf(): void
    {
        $pdf = app(ManualCoverPdf::class)->render($this->cover(['payment_date' => '2026-03-10']));

        $this->assertSame(1, $pdf->getNumPages());
        $this->assertStringStartsWith('%PDF', $pdf->Output('', 'S'));
    }

    /** @param  array<string, mixed>  $attributes */
    private function cover(array $attributes = []): CapaProcessoManual
    {
        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$']]);
        DB::table('pessoa')->insert(['pessoa_id' => 5, 'nome' => 'Cliente SA']);
        DB::table('fin_expenses')->insert(['id' => 9, 'name' => 'Armazenagem']);

        return CapaProcessoManual::create($attributes + ['numero' => 7, 'cliente_id' => 5, 'ref_expense' => 9, 'moeda_id' => 2, 'ref_currency_br' => 1, 'ref_currency_sr' => 1]);
    }
}
