<?php

namespace Tests\Feature\Actions\Booking;

use App\Actions\Booking\SellCost;
use App\Models\FinExpensesValues;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class SellCostTest extends TestCase
{
    use LegacySchema;

    public function test_sets_the_selling_value_and_currency_to_the_buying_ones_only(): void
    {
        $id = DB::table('fin_expenses_values')->insertGetId([
            'ref_embarque' => 10, 'buying_rate' => 80.5, 'ref_currency_br' => 2, 'selling_rate' => 120, 'ref_currency_sr' => 1, 'updated_by' => null,
        ]);
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG', 'ctr_desp' => 0]);

        $expense = app(SellCost::class)(FinExpensesValues::find($id));

        $this->assertSame([80.5, 2], [$expense->fresh()->selling_rate, $expense->fresh()->ref_currency_sr]);
        $this->assertSame([80.5, 2, null], [$expense->fresh()->buying_rate, $expense->fresh()->ref_currency_br, $expense->fresh()->updated_by]);
        $this->assertSame(0, DB::table('rastreamento')->count());
        $this->assertSame(0, (int) DB::table('embarque')->value('ctr_desp'));
    }
}
