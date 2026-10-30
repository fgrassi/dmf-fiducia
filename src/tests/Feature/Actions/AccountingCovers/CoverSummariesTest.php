<?php

namespace Tests\Feature\Actions\AccountingCovers;

use App\Actions\AccountingCovers\GenerateCoverSummaries;
use App\Actions\AccountingCovers\RefreshCoverSummaries;
use App\Models\CapaProcesso;
use App\Models\CapaProcessoArquivo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CoverSummariesTest extends TestCase
{
    use LegacySchema;

    public function test_generates_the_summary_of_archived_covers_without_one_and_notifies_the_favorites(): void
    {
        $this->booking();
        $folder = CapaProcessoArquivo::create(['nome' => 'AGOSTO/2026', 'mes' => 8, 'ano' => 2026, 'status' => 0]);
        $archived = CapaProcesso::create(['embarque_id' => 1, 'moeda_id' => 2, 'numero' => 1, 'receitas' => 100, 'comissoes' => 20, 'valor_cotacao' => 5, 'payment_date' => '2026-08-10', 'capa_processo_arquivo_id' => $folder->getKey()]);
        CapaProcesso::create(['embarque_id' => 1, 'moeda_id' => 1, 'numero' => 2]);
        $done = CapaProcesso::create(['embarque_id' => 1, 'moeda_id' => 1, 'numero' => 3, 'capa_processo_arquivo_id' => $folder->getKey()]);
        DB::table('resumo_resultado')->insert(['capa_processo_id' => $done->getKey(), 'rs' => 99]);
        DB::table('capa_processo_receita_item')->insert(['capa_processo_id' => $archived->getKey(), 'fin_expenses_values_id' => 1, 'nome' => 'THC', 'valor' => 1000]);
        DB::table('capa_processo_despesa_item')->insert(['capa_processo_id' => $archived->getKey(), 'fin_expenses_values_id' => 2, 'nome' => 'Frete', 'valor' => 400]);
        DB::table('embarque_favorito')->insert([['embarque_id' => 1, 'usuario_id' => 7], ['embarque_id' => 1, 'usuario_id' => 8]]);

        $this->assertSame(1, app(GenerateCoverSummaries::class)());

        $summary = DB::table('resumo_resultado')->where('capa_processo_id', $archived->getKey())->first();
        $this->assertEquals(
            ['AGOSTO/2026', 'BKG1', 'Cliente SA', 'e', 'Café ,Soja', 'Santos', 'Brasil', 'Hamburgo', 'Ana', 'Porto Alegre', 100, 600, 0, 1200],
            [$summary->numero_capa, $summary->booking, $summary->cliente, $summary->impo_expo, $summary->mercadoria, $summary->porto_origem, $summary->pais_origem, $summary->porto_destino, $summary->vendedor, $summary->centro_custo, $summary->usd, $summary->rs, $summary->euro, $summary->resultado],
        );
        $this->assertSame(2, DB::table('cmn_messages')->where('ref_family', 5)->count());
        $this->assertSame([7, 8], DB::table('cmn_messages_recipients')->orderBy('recipient')->pluck('recipient')->all());

        $this->assertSame(0, app(GenerateCoverSummaries::class)());
    }

    public function test_refresh_recalculates_rs_and_result_only_within_the_period(): void
    {
        $this->booking();
        $inside = CapaProcesso::create(['embarque_id' => 1, 'moeda_id' => 1, 'numero' => 1, 'receitas' => 10, 'comissoes' => 0, 'valor_cotacao' => 5, 'custo_financeiro' => -12, 'created_at' => '2026-08-15 10:00:00']);
        $outside = CapaProcesso::create(['embarque_id' => 1, 'moeda_id' => 1, 'numero' => 2, 'created_at' => '2026-05-01 10:00:00']);
        DB::table('capa_processo_receita_item')->insert(['capa_processo_id' => $inside->getKey(), 'fin_expenses_values_id' => 1, 'nome' => 'THC', 'valor' => 100]);
        DB::table('resumo_resultado')->insert([['capa_processo_id' => $inside->getKey(), 'rs' => 0, 'resultado' => null], ['capa_processo_id' => $outside->getKey(), 'rs' => 7, 'resultado' => 7]]);

        $count = app(RefreshCoverSummaries::class)(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertSame(1, $count);
        $this->assertEquals([88, 138, 7], [
            DB::table('resumo_resultado')->where('capa_processo_id', $inside->getKey())->value('rs'),
            DB::table('resumo_resultado')->where('capa_processo_id', $inside->getKey())->value('resultado'),
            DB::table('resumo_resultado')->where('capa_processo_id', $outside->getKey())->value('rs'),
        ]);
    }

    private function booking(): void
    {
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'Cliente SA']);
        DB::table('paises')->insert(['paises_id' => 1, 'nome' => 'Brasil']);
        DB::table('porto')->insert(['porto_id' => 1, 'nome' => 'Santos', 'ref_pais' => 1]);
        DB::table('porto')->insert(['porto_id' => 2, 'nome' => 'Hamburgo', 'ref_pais' => null]);
        DB::table('sedes')->insert(['id' => 1, 'nome' => 'Porto Alegre']);
        DB::table('vendedor')->insert(['vendedor_id' => 1, 'nome' => 'Ana', 'sede_id' => 1]);
        DB::table('embarque')->insert(['embarque_id' => 1, 'booking' => 'BKG1', 'cliente_id' => 1, 'impor_expor' => 'e', 'tipo_embarque' => 'm', 'porto_origem_id' => 1, 'porto_destino_id' => 2, 'vendedor_id' => 1]);
        DB::table('mercadoria')->insert([['embarque_id' => 1, 'nome' => 'Café'], ['embarque_id' => 1, 'nome' => 'Soja'], ['embarque_id' => 1, 'nome' => 'Café']]);
    }
}
