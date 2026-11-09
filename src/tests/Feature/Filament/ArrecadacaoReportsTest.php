<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\CertificateCorrections;
use App\Filament\Pages\IofRevenue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ArrecadacaoReportsTest extends TestCase
{
    use LegacySchema;

    public function test_only_financial_opens_the_pages(): void
    {
        foreach ([CertificateCorrections::class, IofRevenue::class] as $page) {
            $this->actingAs($this->user('manager'))->get($page::getUrl())->assertForbidden();
            $this->actingAs($this->user('financial'))->get($page::getUrl())->assertOk();
        }
    }

    public function test_certificates_report_sums_charged_and_paid_per_expense_and_filters_by_date(): void
    {
        $this->actingAs($this->user('financial'));
        $view = ['embarque_id' => 1, 'booking' => 'BKG-1', 'nome_despesa' => 'Certificado', 'despesa_id' => 50, 'moeda_venda' => 'R$', 'moeda_compra' => 'US$', 'receita' => 300, 'despesa' => 80, 'data_despesa' => '2026-10-10'];
        DB::table('vw_resultado_certificado_correcoes')->insert([$view, [...$view, 'booking' => 'BKG-OLD', 'despesa_id' => 51, 'data_despesa' => '2026-01-10']]);

        DB::table('fin_charges')->insert([
            ['id' => 1, 'ref_type' => 1, 'ref_booking' => 1, 'status' => 1, 'created_at' => '2026-10-11'],
            ['id' => 2, 'ref_type' => 1, 'ref_booking' => 1, 'status' => 0, 'created_at' => '2026-10-11'],
        ]);
        DB::table('fin_charges_items')->insert([
            ['ref_charge' => 1, 'ref_expense' => 50, 'ref_currency' => 1, 'item_value' => 300, 'created_at' => '2026-10-11'],
            ['ref_charge' => 2, 'ref_expense' => 50, 'ref_currency' => 1, 'item_value' => 999, 'created_at' => '2026-10-11'],
        ]);
        DB::table('fin_payment_orders')->insert(['id' => 1, 'ref_armador' => 1, 'ref_type' => 1, 'ref_embarque' => 1, 'created_at' => '2026-10-11']);
        DB::table('fin_payment_order_items')->insert(['ref_payment_order' => 1, 'ref_expense' => 50, 'ref_currency' => 1, 'item_value' => 120, 'ref_bl' => 0, 'created_at' => '2026-10-11']);
        DB::table('ordem_pagamento')->insert(['ordem_pagamento_id' => 1, 'ref_embarque' => 1, 'ref_type' => 1, 'created_at' => '2026-10-11']);
        DB::table('ordem_pagamento_item')->insert(['ordem_pagamento_id' => 1, 'ref_expense' => 50, 'ref_currency' => 1, 'item_value' => 30, 'created_at' => '2026-10-11']);

        Livewire::test(CertificateCorrections::class)
            ->set('tableDeferredFilters.periodo', ['data_ini' => '2026-10-01', 'data_fim' => '2026-10-31'])
            ->call('applyTableFilters')
            ->assertSee(['BKG-1', 'US$ 80,00', 'R$ 300,00', 'R$ 150,00', 'Resultado: R$ 150,00'])
            ->assertDontSee('BKG-OLD')
            ->assertDontSee('999');
    }

    public function test_iof_report_lists_paid_charges_with_iof_and_totals_them(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('embarque')->insert(['embarque_id' => 1, 'booking' => 'BKG-1']);
        DB::table('bl')->insert(['bl_id' => 1, 'embarque_id' => 1, 'bl' => 'HBL-77']);
        $charge = ['ref_type' => 1, 'ref_booking' => 1, 'ref_bl' => 1, 'status' => 1, 'created_at' => '2026-10-01', 'payment_date' => '2026-10-15'];
        DB::table('fin_charges')->insert([
            [...$charge, 'valor_iof' => 10.5],
            [...$charge, 'valor_iof' => 4.5, 'payment_date' => '2026-10-31'],
            [...$charge, 'valor_iof' => 0],
            [...$charge, 'valor_iof' => 99, 'status' => 0],
            [...$charge, 'valor_iof' => 77, 'payment_date' => '2026-09-30'],
        ]);

        Livewire::test(IofRevenue::class)
            ->set('tableDeferredFilters.periodo', ['data_ini' => '2026-10-01', 'data_fim' => '2026-10-31'])
            ->call('applyTableFilters')
            ->assertSee(['BKG-1', 'HBL-77', '10,50', '15/10/2026', '4,50', 'Total de IOF: R$ 15,00'])
            ->assertDontSee(['99,00', '77,00'])
            ->sortTable('valor_iof', 'asc')
            ->assertSeeInOrder(['4,50', '10,50'])
            ->sortTable('valor_iof', 'desc')
            ->assertSeeInOrder(['10,50', '4,50']);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
