<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ChargedPaidExpenses;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ChargedPaidExpensesTest extends TestCase
{
    use LegacySchema;

    public function test_only_the_financial_credential_opens_the_page(): void
    {
        $this->actingAs($this->user('manager'))->get(ChargedPaidExpenses::getUrl())->assertForbidden();
        $this->actingAs($this->user('financial'))->get(ChargedPaidExpenses::getUrl())->assertOk();
    }

    public function test_requires_a_period_or_a_cover_file(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(ChargedPaidExpenses::class)
            ->call('send')
            ->assertNotified('Informe o período ou o arquivo de capa.');
    }

    public function test_sends_the_report_and_shows_totals_and_the_booking_rows(): void
    {
        $this->actingAs($this->user('financial'));
        $this->charge();

        Livewire::test(ChargedPaidExpenses::class)
            ->fillForm(['data_ini' => '2026-10-01', 'data_fim' => '2026-10-31', 'tipo_data' => 'cobranca'])
            ->call('send')
            ->assertSee(['THC', 'BKG-10', '300,00', '120,00', '180,00', 'THC / 01/10/2026 até 31/10/2026']);
    }

    public function test_no_rows_say_so_and_clear_resets_the_report(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(ChargedPaidExpenses::class)
            ->fillForm(['data_ini' => '2026-10-01', 'data_fim' => '2026-10-31'])
            ->call('send')
            ->assertSee('Nenhum registro encontrado para os filtros informados.')
            ->call('clear')
            ->assertDontSee('Nenhum registro encontrado');
    }

    public function test_excel_downloads_the_current_filters(): void
    {
        $this->actingAs($this->user('financial'));
        $this->charge();

        Livewire::test(ChargedPaidExpenses::class)
            ->fillForm(['data_ini' => '2026-10-01', 'data_fim' => '2026-10-31'])
            ->call('excel')
            ->assertFileDownloaded();
    }

    private function charge(): void
    {
        DB::table('fin_expenses')->insert(['id' => 1, 'name' => 'THC']);
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG-10']);
        DB::table('fin_expenses_values')->insert(['id' => 100, 'ref_expense' => 1, 'ref_embarque' => 10]);
        $charge = DB::table('fin_charges')->insertGetId(['ref_type' => 1, 'ref_booking' => 10, 'created_at' => '2026-10-05 10:00:00']);
        DB::table('fin_charges_items')->insert(['ref_charge' => $charge, 'ref_expense' => 100, 'ref_currency' => 1, 'item_value' => 300, 'created_at' => '2026-10-05 10:00:00']);
        $order = DB::table('fin_payment_orders')->insertGetId(['ref_armador' => 1, 'created_at' => '2026-10-08', 'status' => 0, 'payment_date' => '2026-10-08', 'ref_type' => 1, 'ref_embarque' => 10]);
        DB::table('fin_payment_order_items')->insert(['ref_payment_order' => $order, 'ref_expense' => 100, 'ref_currency' => 1, 'item_value' => 120, 'created_at' => '2026-10-08', 'ref_bl' => 1]);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
