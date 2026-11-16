<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AccountingReports;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class AccountingReportsPageTest extends TestCase
{
    use LegacySchema;

    public function test_only_financial_opens_the_page(): void
    {
        $this->actingAs($this->user('manager'))->get(AccountingReports::getUrl())->assertForbidden();
        $this->actingAs($this->user('financial'))->get(AccountingReports::getUrl())->assertOk();
    }

    public function test_it_switches_between_the_three_reports_and_validates_the_period(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'Cliente A']);
        DB::table('embarque')->insert(['embarque_id' => 1, 'booking' => 'BKG-1', 'cliente_id' => 1, 'data_topo' => '2026-09-20']);
        DB::table('fin_charges')->insert(['id' => 1, 'ref_type' => 1, 'ref_booking' => 1, 'status' => 0, 'total_value' => 1234.5, 'created_at' => '2026-10-01']);
        DB::table('fin_charges_nfse')->insert(['ref_charge' => 1, 'id_externo' => 'x', 'status' => 'autorizada', 'payload_json' => json_encode(['data_emissao' => '2026-10-10']), 'created_at' => '2026-10-10 10:00:00']);
        $period = ['data_ini' => '2026-10-01', 'data_fim' => '2026-10-31'];

        $page = Livewire::test(AccountingReports::class)
            ->set('tableDeferredFilters.periodo', ['relatorio' => 'receber', ...$period])
            ->call('applyTableFilters')
            ->assertSee(['Cliente A', 'BKG-1', '10/10/2026', 'R$ a Receber', '1.234,50', 'Período: 01/10/2026 a 31/10/2026']);

        $page->set('tableDeferredFilters.periodo', ['relatorio' => 'notas', ...$period])
            ->call('applyTableFilters')
            ->assertSee(['Data NF', 'Número NF', 'Euro'])
            ->assertDontSee('R$ a Receber');

        $page->set('tableDeferredFilters.periodo', ['relatorio' => 'apropriar', ...$period])
            ->call('applyTableFilters')
            ->assertSee(['R$ Pago', '(=) Saldo'])
            ->assertSee('Nenhum registro encontrado para o período.');

        $page->set('tableDeferredFilters.periodo', ['relatorio' => 'receber', 'data_ini' => null, 'data_fim' => '2026-10-31'])
            ->call('applyTableFilters')
            ->assertSee('Informe a data inicial e a data final.');

        $page->set('tableDeferredFilters.periodo', ['relatorio' => 'receber', 'data_ini' => '2026-11-01', 'data_fim' => '2026-10-31'])
            ->call('applyTableFilters')
            ->assertSee('A data inicial não pode ser maior que a data final.');
    }

    public function test_it_exports_the_report_to_excel(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(AccountingReports::class)
            ->set('tableDeferredFilters.periodo', ['relatorio' => 'receber', 'data_ini' => '2026-10-01', 'data_fim' => '2026-10-31'])
            ->call('applyTableFilters')
            ->callAction('export')
            ->assertFileDownloaded();
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
