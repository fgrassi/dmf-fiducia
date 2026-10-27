<?php

namespace Tests\Feature\Actions\Cobranca\Items;

use App\Actions\Cobranca\Items\ItemPricing;
use App\Models\FinExpensesValues;
use DomainException;
use Illuminate\Support\Facades\DB;
use stdClass;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ItemPricingTest extends TestCase
{
    use LegacySchema;

    public function test_freight_unit_value_adds_commissions_of_the_same_equipment(): void
    {
        $freight = $this->expense(['ref_expense' => 1, 'selling_rate' => 1000, 'ref_equipment' => 3]);
        $this->expense(['ref_expense' => 33, 'selling_rate' => 50, 'ref_equipment' => 3]);
        $this->expense(['ref_expense' => 1048, 'selling_rate' => 25, 'ref_equipment' => 3]);
        $this->expense(['ref_expense' => 33, 'selling_rate' => 999, 'ref_equipment' => 4]);

        $this->assertSame(1075.0, app(ItemPricing::class)->unitValue($freight));
    }

    public function test_expense_of_another_bl_is_left_out(): void
    {
        $expense = $this->expense(['ref_bl' => 21], unity: 5);

        $this->assertNull(app(ItemPricing::class)->debit($expense, 1.0, 100.0, $this->bl(20)));
    }

    public function test_bl_unity_without_bl_multiplies_by_booking_bl_quantity(): void
    {
        $expense = $this->expense([], unity: 1);
        DB::table('embarque')->where('embarque_id', 10)->update(['qtd_bl' => 3]);

        $this->assertSame(600.0, app(ItemPricing::class)->debit($expense, 2.0, 100.0, null));
    }

    public function test_process_unity_is_split_between_the_booking_bls(): void
    {
        DB::table('bl')->insert([['embarque_id' => 10, 'bl' => 'HBL1'], ['embarque_id' => 10, 'bl' => 'HBL2'], ['embarque_id' => 10, 'bl' => '']]);
        $expense = $this->expense([], unity: 5);

        $this->assertSame(50.0, app(ItemPricing::class)->debit($expense, 1.0, 100.0, null));
    }

    public function test_container_unity_without_bl_multiplies_by_cargo_quantity_of_the_equipment(): void
    {
        DB::table('mercadoria')->insert([
            ['embarque_id' => 10, 'quantidade' => 2, 'tipo_container_id' => 3],
            ['embarque_id' => 10, 'quantidade' => 5, 'tipo_container_id' => 4],
        ]);
        $expense = $this->expense(['ref_equipment' => 3], unity: 2);

        $this->assertSame(200.0, app(ItemPricing::class)->debit($expense, 1.0, 100.0, null));
    }

    public function test_weight_measure_without_container_or_bl_is_rejected(): void
    {
        $expense = $this->expense([], unity: 3);

        $this->expectException(DomainException::class);

        app(ItemPricing::class)->debit($expense, 1.0, 100.0, null);
    }

    private function expense(array $attributes, int $unity = 5): FinExpensesValues
    {
        DB::table('embarque')->insertOrIgnore(['embarque_id' => 10, 'booking' => 'BKG1']);
        DB::table('fin_expenses')->insertOrIgnore(['id' => $attributes['ref_expense'] ?? 500, 'name' => 'TAXA', 'ref_unity' => $unity]);

        $id = DB::table('fin_expenses_values')->insertGetId([
            'ref_embarque' => 10,
            'ref_expense' => 500,
            'selling_rate' => 100,
            'ref_currency_sr' => 1,
            'billing' => 'p',
            ...$attributes,
        ]);

        return FinExpensesValues::with('despesa')->find($id);
    }

    private function bl(int $id): stdClass
    {
        DB::table('bl')->insert(['bl_id' => $id, 'embarque_id' => 10, 'bl' => "HBL{$id}"]);

        return DB::table('bl')->where('bl_id', $id)->first();
    }
}
