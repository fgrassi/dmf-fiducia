<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ShippingCommissions;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ShippingCommissionsPageTest extends TestCase
{
    use LegacySchema;

    public function test_only_the_financial_credential_opens_the_page(): void
    {
        $this->actingAs($this->user('manager'))->get(ShippingCommissions::getUrl())->assertForbidden();
        $this->actingAs($this->user('financial'))->get(ShippingCommissions::getUrl())->assertOk();
    }

    public function test_lists_commissions_and_the_filter_narrows_them(): void
    {
        $this->actingAs($this->user('financial'));
        $this->commission('BKG-1', 'Ana Comissionada');
        $this->commission('BKG-2', 'Ana Comissionada');

        Livewire::test(ShippingCommissions::class)
            ->assertSee(['Ana Comissionada', 'BKG-1', 'BKG-2', '1.234,50', 'Real (R$)'])
            ->set('tableDeferredFilters.busca.booking', 'BKG-2')
            ->call('applyTableFilters')
            ->assertSee('BKG-2')
            ->assertDontSee('BKG-1')
            ->set('tableDeferredFilters.busca.booking', 'nenhum')
            ->call('applyTableFilters')
            ->assertSee('Nenhum registro encontrado');
    }

    public function test_pay_action_registers_the_payment_and_the_types_can_be_changed(): void
    {
        $this->actingAs($this->user('financial'));
        $id = $this->commission('BKG-1', 'Ana');

        Livewire::test(ShippingCommissions::class)
            ->callAction(TestAction::make('pay')->table((string) $id), ['valor_total' => 40, 'taxa' => null, 'data_pagamento' => '2026-03-01', 'observacao' => null])
            ->assertHasNoFormErrors()
            ->assertSee('40,00')
            ->callAction(TestAction::make('changeType')->table((string) $id), ['tipo' => 1, 'tipo_comissao' => 2])
            ->assertHasNoFormErrors();

        $row = DB::table('comissao_embarque')->first();
        $this->assertSame([1, 1, 2], [(int) $row->status, (int) $row->tipo, (int) $row->tipo_comissao]);
    }

    public function test_excel_downloads_the_filtered_rows(): void
    {
        $this->actingAs($this->user('financial'));
        $this->commission('BKG-1', 'Ana');

        Livewire::test(ShippingCommissions::class)
            ->callAction(TestAction::make('excel')->table())
            ->assertFileDownloaded();
    }

    public function test_receipt_date_column_sorts_both_ways(): void
    {
        $this->actingAs($this->user('financial'));
        foreach (['2026-01-10' => 'OLD-BKG', '2026-03-10' => 'NEW-BKG'] as $date => $booking) {
            $id = $this->commission($booking, 'Ana');
            $embarque = DB::table('comissao_embarque')->where('comissao_embarque_id', $id)->value('embarque_id');
            DB::table('fin_charges')->insert(['ref_type' => 1, 'ref_booking' => $embarque, 'status' => 1, 'created_at' => $date, 'payment_date' => $date]);
        }

        Livewire::test(ShippingCommissions::class)
            ->sortTable('data_recibo', 'desc')
            ->assertSeeInOrder(['NEW-BKG', 'OLD-BKG'])
            ->sortTable('data_recibo', 'asc')
            ->assertSeeInOrder(['OLD-BKG', 'NEW-BKG']);
    }

    private function commission(string $booking, string $person): int
    {
        DB::table('cmn_currencies')->insertOrIgnore(['id' => 1, 'name' => 'Real', 'symbol' => 'R$']);
        DB::table('comissao_embarque_responsavel')->insertOrIgnore(['comissao_embarque_responsavel_id' => 1, 'nome' => $person]);

        return DB::table('comissao_embarque')->insertGetId([
            'comissao_embarque_responsavel_id' => 1, 'embarque_id' => DB::table('embarque')->insertGetId(['booking' => $booking]), 'moeda_id' => 1,
            'valor_unitario' => 1234.5, 'created_at' => '2026-01-01 00:00:00', 'created_by' => 1,
        ]);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
