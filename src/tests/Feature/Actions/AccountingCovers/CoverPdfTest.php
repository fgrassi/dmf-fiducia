<?php

namespace Tests\Feature\Actions\AccountingCovers;

use App\Actions\AccountingCovers\CoverPdf;
use App\Actions\AccountingCovers\CoverSheet;
use App\Models\CapaProcesso;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CoverPdfTest extends TestCase
{
    use LegacySchema;

    public function test_sheet_lists_items_extras_totals_and_profitability_of_an_export_cover(): void
    {
        $cover = $this->cover(['impor_expor' => 'e'], [
            'moeda_id' => 2, 'receitas' => 100, 'comissoes' => 20, 'valor_cotacao' => 5, 'custo_financeiro' => -12.5,
            'valor_fiducia' => 30, 'valor_iof_compra' => 4, 'valor_iof' => 10, 'desconto' => 5, 'valor_multa' => 3, 'payment_date' => '2026-03-10',
        ]);
        DB::table('capa_processo_despesa_item')->insert(['capa_processo_id' => $cover->getKey(), 'fin_expenses_values_id' => 1, 'nome' => 'Frete/UN', 'valor' => 200]);
        DB::table('capa_processo_receita_item')->insert(['capa_processo_id' => $cover->getKey(), 'fin_expenses_values_id' => 2, 'nome' => 'THC/UN', 'valor' => 1000]);
        DB::table('fin_credit_notes')->insert(['ref_embarque' => 1, 'ref_agente' => 1, 'created_at' => '2026-10-01', 'created_by' => 1, 'name' => 'CN-1']);
        DB::table('fin_debit_notes')->insert(['ref_embarque' => 1, 'ref_agente' => 1, 'created_at' => '2026-10-01', 'created_by' => 1, 'name' => 'DN-1']);

        $sheet = app(CoverSheet::class)($cover);

        $this->assertSame(['MARÍTIMO', 'Exportador:', 'Cliente SA', 'Porto Alegre', 'Ana', 'EXPO', 'MARÇO/2026', '7', 'CN-1 - DN-1'], [
            $sheet['reminder'], $sheet['party_label'], $sheet['party'], $sheet['office'], $sheet['seller'], $sheet['operation'], $sheet['month_year'], $sheet['number'], $sheet['reference'],
        ]);
        $this->assertSame([['Frete/UN', 'R$ 200,00'], ['DESCONTO FIDUCIA', 'R$ 30,00'], ['IOF', 'R$ 4,00']], $sheet['expenses']);
        $this->assertSame([['THC/UN', 'R$ 1.000,00'], ['IOF', 'R$ 10,00'], ['Desconto', '(R$ 5,00)'], ['Multa', 'R$ 3,00']], $sheet['revenues']);
        $this->assertSame(['R$ 234,00', 'R$ 1.008,00', 'R$ 774,00'], [$sheet['total_expenses'], $sheet['total_revenues'], $sheet['difference']]);
        $this->assertSame(['Receitas recebidas do exterior', 'US$ 100,00', 'US$ 20,00', '- R$ 12,50', 'US$ 120,00', 'R$ 600,00', 'R$ 1.361,50'], [
            $sheet['abroad_label'], $sheet['abroad'], $sheet['commission'], $sheet['financial_cost'], $sheet['foreign_total'], $sheet['conversion'], $sheet['result'],
        ]);
        $this->assertEquals($cover->coverResult(), 1361.5);
    }

    public function test_import_cover_shows_the_booking_and_a_negative_foreign_amount_is_payable(): void
    {
        $cover = $this->cover(['impor_expor' => 'i', 'house_bl' => 'BL DIRETO'], ['moeda_id' => 2, 'receitas' => -40]);

        $sheet = app(CoverSheet::class)($cover);

        $this->assertSame(['BL DIRETO', 'Importador', 'Importadora SA', 'IMPO', 'B1', '', 'Receitas a serem pagas ao exterior'], [
            $sheet['reminder'], $sheet['party_label'], $sheet['party'], $sheet['operation'], $sheet['reference'], $sheet['month_year'], $sheet['abroad_label'],
        ]);
    }

    public function test_renders_a_pdf_that_continues_on_a_second_page_when_there_are_many_items(): void
    {
        $cover = $this->cover(['impor_expor' => 'e'], ['moeda_id' => 2, 'payment_date' => '2026-03-10']);
        DB::table('capa_processo_despesa_item')->insert(array_map(fn ($i) => ['capa_processo_id' => $cover->getKey(), 'fin_expenses_values_id' => $i, 'nome' => "Despesa {$i}", 'valor' => $i], range(1, 40)));

        $pdf = app(CoverPdf::class)->render($cover);

        $this->assertSame(2, $pdf->getNumPages());
        $this->assertStringStartsWith('%PDF', $pdf->Output('', 'S'));
    }

    /**
     * @param  array<string, mixed>  $booking
     * @param  array<string, mixed>  $cover
     */
    private function cover(array $booking, array $cover): CapaProcesso
    {
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$']);
        DB::table('pessoa')->insert([['pessoa_id' => 1, 'nome' => 'Cliente SA'], ['pessoa_id' => 2, 'nome' => 'Importadora SA']]);
        DB::table('sedes')->insert(['id' => 1, 'nome' => 'Porto Alegre']);
        DB::table('vendedor')->insert(['vendedor_id' => 1, 'nome' => 'Ana', 'sede_id' => 1]);
        DB::table('embarque')->insert(['embarque_id' => 1, 'booking' => 'B1', 'tipo_embarque' => 'm', 'cliente_id' => 1, 'pessoa_id_importador' => 2, 'vendedor_id' => 1] + $booking);

        return CapaProcesso::create($cover + ['embarque_id' => 1, 'numero' => 7, 'moeda_id' => 1]);
    }
}
