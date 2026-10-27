<?php

namespace Tests\Feature\Actions\Cobranca\Items;

use App\Actions\Cobranca\Items\ChargeIof;
use App\Models\FinCharges;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ChargeIofTest extends TestCase
{
    use LegacySchema;

    public function test_calculates_iof_over_foreign_currency_items_only(): void
    {
        $charge = $this->charge();
        $this->item($charge, currency: 2, value: 1000);
        $this->item($charge, currency: 1, value: 500);
        $this->item($charge, currency: 2, value: 200, expense: 900);
        DB::table('regra_despesa_cobranca_item')->insert(['despesa_id' => 900, 'regra_despesa_cobranca_id' => 10]);

        $this->assertSame(35.0, app(ChargeIof::class)->amount($charge));
    }

    public function test_exempt_client_pays_no_iof(): void
    {
        $charge = $this->charge(client: ['isento_iof' => 1]);
        $this->item($charge, currency: 2, value: 1000);

        $this->assertSame(0.0, app(ChargeIof::class)->amount($charge));
    }

    public function test_vipal_export_pays_no_iof(): void
    {
        $charge = $this->charge(client: ['pessoa_id' => 4392]);
        $this->item($charge, currency: 2, value: 1000);

        $this->assertSame(0.0, app(ChargeIof::class)->amount($charge));
    }

    public function test_manual_iof_value_wins_over_calculation_but_not_on_recalculation(): void
    {
        $charge = $this->charge(attributes: ['ctr_valor_iof' => 1, 'valor_iof' => 12.34]);
        $this->item($charge, currency: 2, value: 1000);

        $this->assertSame(12.34, app(ChargeIof::class)->amount($charge));
        $this->assertSame(35.0, app(ChargeIof::class)->calculated($charge));
    }

    private function charge(array $client = [], array $attributes = []): FinCharges
    {
        $clientId = DB::table('pessoa')->insertGetId(['nome' => 'ACME', ...$client]);
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG1', 'impor_expor' => 'e', 'cliente_id' => $clientId]);
        $id = DB::table('fin_charges')->insertGetId(['ref_type' => 1, 'ref_booking' => 10, 'created_at' => now(), ...$attributes]);

        return FinCharges::find($id);
    }

    private function item(FinCharges $charge, int $currency, float $value, int $expense = 500): void
    {
        $valueId = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 10, 'ref_expense' => $expense]);
        DB::table('fin_charges_items')->insert([
            'ref_charge' => $charge->id,
            'ref_expense' => $valueId,
            'ref_currency' => $currency,
            'item_value' => $value,
            'created_at' => now(),
        ]);
    }
}
