<?php

namespace Tests\Feature\Actions\Cobranca\Generation;

use App\Actions\Cobranca\Generation\BookingChecks;
use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BookingChecksTest extends TestCase
{
    use LegacySchema;

    public function test_pending_ignores_commissions_not_charged_and_other_billing_side(): void
    {
        $b = $this->booking(['impor_expor' => 'e']);
        $this->expense($b, ['ref_expense' => 33]);
        $this->expense($b, ['ref_due_to' => 10]);
        $this->expense($b, ['billing' => 'c']);
        $this->expense($b, ['selling_rate' => 0]);

        $this->assertFalse(app(BookingChecks::class)->hasPending($b));

        $this->expense($b, ['billing' => 'p']);

        $this->assertTrue(app(BookingChecks::class)->hasPending($b));
    }

    public function test_pending_ignores_expenses_already_in_a_charge(): void
    {
        $b = $this->booking(['impor_expor' => 'e']);
        $id = $this->expense($b, ['billing' => 'p']);
        DB::table('fin_charges_items')->insert(['ref_charge' => 1, 'ref_expense' => $id, 'ref_currency' => 1, 'created_at' => now()]);

        $this->assertFalse(app(BookingChecks::class)->hasPending($b));
    }

    public function test_first_release_record_decides_and_no_record_means_released(): void
    {
        $b = $this->booking();
        $checks = app(BookingChecks::class);

        $this->assertTrue($checks->released($b));

        DB::table('liberacao_embarque')->insert(['embarque_id' => $b->embarque_id, 'status' => 0]);
        DB::table('liberacao_embarque')->insert(['embarque_id' => $b->embarque_id, 'status' => 1]);

        $this->assertFalse($checks->released($b));
    }

    public function test_rejects_booking_without_expenses_and_notifies_finance(): void
    {
        $b = $this->booking(['booking' => 'BKG9']);

        $this->assertFalse(app(BookingChecks::class)->expensesConsistent($b));
        $this->assertSame(['DMF Logistics: Booking sem despesas cadastradas: BKG9'], $this->sentSubjects()->all());
    }

    public function test_rejects_maritime_booking_without_bls(): void
    {
        $b = $this->booking(['booking' => 'BKG9', 'tipo_embarque' => 'm']);
        $this->expense($b, ['billing' => 'p']);

        $this->assertFalse(app(BookingChecks::class)->expensesConsistent($b));
        $this->assertSame(['DMF Logistics: Booking sem BLs cadastrados:BKG9'], $this->sentSubjects()->all());
    }

    public function test_rejects_maritime_import_missing_mandatory_expense_rule(): void
    {
        $b = $this->booking(['booking' => 'BKG9', 'tipo_embarque' => 'm', 'impor_expor' => 'i', 'revisao_despesas' => 's']);
        $this->expense($b, ['billing' => 'c']);
        DB::table('bl')->insert(['bl_id' => 5, 'embarque_id' => $b->embarque_id, 'bl' => 'HBL']);
        DB::table('container')->insert(['bl_id' => 5]);
        DB::table('regra_despesa_cobranca')->insert(['regra_despesa_cobranca_id' => 1, 'tipo_embarque' => 'm', 'impor_expor' => 'i', 'nome' => 'Frete', 'obrigatoria' => 1]);

        $this->assertFalse(app(BookingChecks::class)->expensesConsistent($b));
        $this->assertSame(['DMF Logistics: Despesas obrigatória não cadastrada: BKG9'], $this->sentSubjects()->all());
    }

    public function test_accepts_consistent_air_booking(): void
    {
        $b = $this->booking(['tipo_embarque' => 'a', 'qtd_bl' => 1]);
        $this->expense($b, ['billing' => 'p']);

        $this->assertTrue(app(BookingChecks::class)->expensesConsistent($b));
        $this->assertSame([], $this->sentSubjects()->all());
    }

    private function booking(array $attributes = []): Embarque
    {
        $id = DB::table('embarque')->insertGetId([...['booking' => 'BKG1', 'tipo_embarque' => 'a', 'impor_expor' => 'e'], ...$attributes]);

        return Embarque::find($id);
    }

    private function expense(Embarque $b, array $attributes): int
    {
        DB::table('fin_expenses')->insertOrIgnore(['id' => 500, 'name' => 'TAXA', 'ref_unity' => 5]);

        return DB::table('fin_expenses_values')->insertGetId([
            'ref_embarque' => $b->embarque_id,
            'ref_expense' => 500,
            'selling_rate' => 100,
            'ref_currency_sr' => 1,
            'billing' => 'p',
            ...$attributes,
        ]);
    }
}
