<?php

namespace Tests\Feature\Api;

use App\Actions\Cobranca\Recalculation\AfterRecalculation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class RecalculateChargeTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cobranca.recalculation_token' => 'tok']);
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10', 'impor_expor' => 'e']);
        DB::table('fin_expenses')->insert(['id' => 500, 'name' => 'TAXA', 'ref_unity' => 5]);
        DB::table('fin_expenses_values')->insert(['id' => 7, 'ref_expense' => 500, 'ref_embarque' => 10, 'selling_rate' => 120, 'ref_currency_sr' => 1, 'billing' => 'p']);
        DB::table('fin_charges')->insert(['id' => 1, 'ref_type' => 1, 'ref_booking' => 10, 'status' => 0, 'total_value' => 50, 'valor_iof' => 3, 'created_at' => '2026-10-27']);
        DB::table('fin_charges_items')->insert(['id' => 1, 'ref_charge' => 1, 'ref_expense' => 7, 'unitary_value' => 50, 'ref_currency' => 1, 'item_value' => 50, 'created_at' => '2026-10-27']);
    }

    private function call_(array $query = ['id' => 1], string $token = 'tok')
    {
        return $this->withToken($token)->getJson('/api/cron/recalculaCobranca?'.http_build_query($query));
    }

    public function test_refuses_without_a_configured_or_valid_token(): void
    {
        $this->call_(token: 'bad')->assertUnauthorized();

        config(['services.cobranca.recalculation_token' => null]);
        $this->call_()->assertStatus(503);
        $this->assertSame(50.0, (float) DB::table('fin_charges_items')->value('item_value'));
    }

    public function test_recalculates_the_items_and_saves_the_total_without_iof(): void
    {
        $this->mock(AfterRecalculation::class)->shouldReceive('__invoke')->once();

        $this->call_()->assertOk()->assertExactJson(['message' => 'Invoice updated successfully', 'error' => '']);

        $this->assertSame(120.0, (float) DB::table('fin_charges_items')->value('item_value'));
        $charge = DB::table('fin_charges')->first();
        $this->assertSame([120.0, 3.0], [(float) $charge->total_value, (float) $charge->valor_iof]);
        $this->assertSame('recalculo', DB::table('fin_charges_log')->value('acao'));
    }

    public function test_returns_the_legacy_errors(): void
    {
        $this->call_(['id' => 99])->assertNotFound();

        DB::table('fin_charges_nfse')->insert(['ref_charge' => 1, 'id_externo' => 'BKG10', 'status' => 'processando']);
        $this->call_()->assertStatus(422)->assertJson(['error' => 'Invoices with NFSe still processing cannot be recalculated']);

        DB::table('fin_charges')->update(['status' => 1]);
        $this->call_()->assertStatus(422)->assertJson(['message' => 'Erro while updating the Invoice', 'error' => 'Payd invoices cannot be update']);
    }
}
