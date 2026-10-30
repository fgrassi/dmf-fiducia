<?php

namespace Tests\Feature\Actions\AccountingCovers;

use App\Actions\AccountingCovers\DeleteManualCover;
use App\Actions\AccountingCovers\GenerateCoverSummaries;
use App\Actions\AccountingCovers\SaveManualCover;
use App\Models\CapaProcessoArquivo;
use App\Models\CapaProcessoManual;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ManualCoverActionsTest extends TestCase
{
    use LegacySchema;

    /** @return array<string, array{0: int, 1: int, 2: float}> */
    public static function resultCases(): array
    {
        // compra 100, venda 250, cotação 5
        return [
            'compra e venda em R$' => [1, 1, 150.0],
            'compra em R$ e venda em moeda estrangeira' => [1, 2, 1150.0],
            'compra em moeda estrangeira e venda em R$ (como o legado)' => [2, 1, 1150.0],
            'compra e venda em moeda estrangeira' => [2, 2, 750.0],
        ];
    }

    #[DataProvider('resultCases')]
    public function test_result_converts_the_foreign_side_by_the_rate(int $buying, int $selling, float $expected): void
    {
        $cover = new CapaProcessoManual(['ref_currency_br' => $buying, 'ref_currency_sr' => $selling, 'buying_rate' => 100, 'selling_rate' => 250, 'valor_cotacao' => 5, 'valor_usd_eur' => 10]);

        $this->assertEquals($expected, $cover->result());
        $this->assertEquals(50, $cover->rate());
    }

    public function test_create_takes_the_next_number_among_normal_and_manual_covers_without_folder(): void
    {
        DB::table('capa_processo')->insert(['embarque_id' => 1, 'moeda_id' => 1, 'numero' => 41]);
        DB::table('capa_processo_manual')->insert(['moeda_id' => 1, 'ref_currency_br' => 1, 'ref_currency_sr' => 1, 'numero' => 45]);
        DB::table('capa_processo_manual')->insert(['moeda_id' => 1, 'ref_currency_br' => 1, 'ref_currency_sr' => 1, 'numero' => 99, 'capa_processo_arquivo_id' => 1]);

        $cover = app(SaveManualCover::class)($this->data(), userId: 7);

        $this->assertSame([46, 7], [$cover->numero, $cover->created_by]);
        $this->assertNotNull($cover->fresh()->created_at);
    }

    public function test_edit_keeps_creation_data_and_refreshes_the_summary(): void
    {
        $cover = app(SaveManualCover::class)($this->data(), userId: 7);
        DB::table('capa_processo_manual')->where('capa_processo_manual_id', $cover->getKey())->update(['created_at' => '2026-01-02 03:04:05']);
        DB::table('resumo_resultado')->insert(['capa_processo_manual_id' => $cover->getKey(), 'usd' => 0, 'rs' => 0, 'euro' => 0, 'resultado' => null]);

        app(SaveManualCover::class)(['selling_rate' => 400, 'moeda_usd_eur' => 2, 'valor_usd_eur' => 10, 'valor_cotacao' => 5], $cover->fresh(), 9);

        $fresh = $cover->fresh();
        $this->assertSame(['2026-01-02 03:04:05', 7], [$fresh->created_at->format('Y-m-d H:i:s'), $fresh->created_by]);
        $summary = DB::table('resumo_resultado')->first();
        $this->assertEquals([10, 0, 300, 350], [$summary->usd, $summary->euro, $summary->rs, $summary->resultado]);
    }

    public function test_delete_removes_the_cover_and_its_summary(): void
    {
        $cover = app(SaveManualCover::class)($this->data());
        $other = app(SaveManualCover::class)($this->data());
        DB::table('resumo_resultado')->insert([['capa_processo_manual_id' => $cover->getKey()], ['capa_processo_manual_id' => $other->getKey()]]);

        app(DeleteManualCover::class)($cover);

        $this->assertModelMissing($cover);
        $this->assertModelExists($other);
        $this->assertSame(1, DB::table('resumo_resultado')->count());
    }

    public function test_cron_generates_the_summary_of_archived_manual_covers(): void
    {
        DB::table('pessoa')->insert(['pessoa_id' => 5, 'nome' => 'Cliente SA']);
        $folder = CapaProcessoArquivo::create(['nome' => 'AGOSTO/2026', 'mes' => 8, 'ano' => 2026, 'status' => 0]);
        $archived = app(SaveManualCover::class)($this->data(['moeda_usd_eur' => 3, 'valor_usd_eur' => 10, 'valor_cotacao' => 5, 'payment_date' => '2026-08-10']));
        $archived->update(['capa_processo_arquivo_id' => $folder->getKey()]);
        app(SaveManualCover::class)($this->data());

        $this->assertSame(1, app(GenerateCoverSummaries::class)());

        $summary = DB::table('resumo_resultado')->first();
        $this->assertEquals([$archived->getKey(), null, 'AGOSTO/2026', (string) $archived->numero, 'Cliente SA', '2026-08-10', 0, 10, 150, 200], [
            $summary->capa_processo_manual_id, $summary->embarque_id, $summary->numero_capa, $summary->booking, $summary->cliente, $summary->data_pagamento, $summary->usd, $summary->euro, $summary->rs, $summary->resultado,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function data(array $attributes = []): array
    {
        return $attributes + ['cliente_id' => 5, 'moeda_id' => 1, 'ref_currency_br' => 1, 'ref_currency_sr' => 1, 'buying_rate' => 100, 'selling_rate' => 250, 'valor_cotacao' => 0, 'valor_usd_eur' => 0];
    }
}
