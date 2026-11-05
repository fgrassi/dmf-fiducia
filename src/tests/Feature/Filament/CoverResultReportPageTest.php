<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\CoverResultReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CoverResultReportPageTest extends TestCase
{
    use LegacySchema;

    public function test_allows_director_or_financial_with_manager_or_supervisor(): void
    {
        $this->actingAs($this->user('financial'))->get(CoverResultReport::getUrl())->assertForbidden();
        $this->actingAs($this->user('manager'))->get(CoverResultReport::getUrl())->assertForbidden();
        $this->actingAs($this->user('financial', 'supervisor'))->get(CoverResultReport::getUrl())->assertOk();
        $this->actingAs($this->user('director'))->get(CoverResultReport::getUrl())->assertOk();
    }

    public function test_search_requires_a_filter_and_shows_the_report(): void
    {
        $this->actingAs($this->user('director'));
        DB::table('capa_processo_manual')->insert(['capa_processo_manual_id' => 1, 'moeda_id' => 1, 'ref_currency_br' => 1, 'ref_currency_sr' => 1, 'nome_cliente' => 'Cliente Manual']);
        DB::table('resumo_resultado')->insert(['capa_processo_manual_id' => 1, 'numero_capa' => 'AGOSTO/2026', 'booking' => '1', 'cliente' => 'Cliente Manual', 'usd' => 0, 'rs' => 50, 'euro' => 0, 'data_pagamento' => '2026-08-20']);

        Livewire::test(CoverResultReport::class)
            ->call('search')
            ->assertSet('applied', null)
            ->set('data.from', '2026-08-01')
            ->set('data.show_ranking', true)
            ->call('search')
            ->assertSee('Resumo por escritório')
            ->assertSee('Cliente Manual')
            ->assertSee('Ranking +');
    }

    public function test_exports_the_list_to_excel_only_after_a_search_with_the_list(): void
    {
        $this->actingAs($this->user('director'));
        DB::table('capa_processo_manual')->insert(['capa_processo_manual_id' => 1, 'moeda_id' => 1, 'ref_currency_br' => 1, 'ref_currency_sr' => 1, 'nome_cliente' => 'Cliente Manual']);
        DB::table('resumo_resultado')->insert(['capa_processo_manual_id' => 1, 'numero_capa' => 'AGOSTO/2026', 'booking' => '1', 'usd' => 0, 'rs' => 50, 'euro' => 0, 'data_pagamento' => '2026-08-20']);

        Livewire::test(CoverResultReport::class)
            ->assertActionHidden('export')
            ->set('data.from', '2026-08-01')
            ->call('search')
            ->assertActionVisible('export')
            ->callAction('export')
            ->assertFileDownloaded();
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
