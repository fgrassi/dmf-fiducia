<?php

namespace Tests\Feature\Actions\Booking;

use App\Actions\Booking\ImportQuotationCosts;
use App\Models\Embarque;
use App\Models\FinExpensesValues;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ImportQuotationCostsTest extends TestCase
{
    use LegacySchema;

    private const FCL_20 = 5;

    private const FCL_40 = 6;

    public function test_import_replaces_the_expenses_and_keeps_only_the_containers_of_the_booking(): void
    {
        $this->travelTo('2026-11-06 10:00');
        $booking = $this->booking(['impor_expor' => 'i', 'cotacao_impo_id' => 3]);
        DB::table('mercadoria')->insert(['embarque_id' => $booking->getKey(), 'tipo_container_id' => self::FCL_20]);
        $this->expense($booking, ['ref_expense' => 999]);
        $this->quotationItem('cotacao_impo_item', ['cotacao_impo_id' => 3, 'despesa_id' => 10, 'valor_compra' => 100, 'moeda_compra_id' => 2, 'valor_venda' => 150, 'moeda_venda_id' => 2, 'tipo_cobranca' => 'p', 'compra_minimo' => 20, 'venda_minimo' => 30]);
        $this->quotationItem('cotacao_impo_item', ['cotacao_impo_id' => 3, 'despesa_id' => 11, 'tipo_container_id' => self::FCL_20, 'valor_compra' => 50]);
        $this->quotationItem('cotacao_impo_item', ['cotacao_impo_id' => 3, 'despesa_id' => 12, 'tipo_container_id' => self::FCL_40, 'valor_compra' => 70]);

        $count = app(ImportQuotationCosts::class)($booking, 7);

        $this->assertSame(2, $count);
        $this->assertSame([10, 11], FinExpensesValues::orderBy('id')->pluck('ref_expense')->all());
        $first = FinExpensesValues::where('ref_expense', 10)->first();
        $this->assertSame([100.0, 2, 150.0, 2, 'p', 20.0, 30.0, 7, '2026-11-06', null], [$first->buying_rate, $first->ref_currency_br, $first->selling_rate, $first->ref_currency_sr, $first->billing, $first->br_min, $first->sr_min, $first->created_by, $first->created_at, $first->ref_equipment]);
        $this->assertSame(self::FCL_20, (int) FinExpensesValues::where('ref_expense', 11)->value('ref_equipment'));
    }

    public function test_air_import_brings_the_selling_percentage(): void
    {
        $booking = $this->booking(['impor_expor' => 'i', 'tipo_embarque' => 'a', 'cotacao_impo_aereo_id' => 4]);
        $this->quotationItem('cotacao_impo_aereo_item', ['cotacao_impo_aereo_id' => 4, 'despesa_id' => 73, 'valor_compra' => 10, 'perc' => 12.5]);

        app(ImportQuotationCosts::class)($booking, 7);

        $this->assertSame(12.5, FinExpensesValues::value('selling_rate_perc'));
    }

    public function test_export_updates_the_expense_with_the_same_cost_and_creates_the_others(): void
    {
        $this->travelTo('2026-11-06 10:00');
        $booking = $this->booking(['cotacao_expo_id' => 9]);
        $existing = $this->expense($booking, ['ref_expense' => 10, 'buying_rate' => 100, 'selling_rate' => 1, 'created_by' => 3]);
        $other = $this->expense($booking, ['ref_expense' => 11, 'buying_rate' => 40]);
        $this->quotationItem('cotacao_expo_item', ['cotacao_expo_id' => 9, 'despesa_id' => 10, 'valor_compra' => 100, 'valor_venda' => 180, 'all_in' => '1']);
        $this->quotationItem('cotacao_expo_item', ['cotacao_expo_id' => 9, 'despesa_id' => 11, 'valor_compra' => 55, 'valor_venda' => 60]);
        $this->quotationItem('cotacao_expo_item', ['cotacao_expo_id' => 9, 'despesa_id' => 1916, 'valor_compra' => 8, 'valor_venda' => 9]);

        $count = app(ImportQuotationCosts::class)($booking, 7);

        $this->assertSame(3, $count);
        $this->assertSame(4, FinExpensesValues::count());
        $this->assertSame([180.0, '1', 3, 1, '2026-11-06'], [$existing->fresh()->selling_rate, $existing->fresh()->all_in, $existing->fresh()->created_by, $existing->fresh()->updated_by, $existing->fresh()->updated_at]);
        $this->assertSame(40.0, $other->fresh()->buying_rate);
        $created = FinExpensesValues::where('ref_expense', 11)->where('buying_rate', 55)->first();
        $this->assertSame([7, '2026-11-06', null], [$created->created_by, $created->created_at, $created->updated_by]);
        $hidden = FinExpensesValues::where('ref_expense', 1916)->first();
        $this->assertSame(['p', null, '1'], [$hidden->billing, $hidden->selling_rate, $hidden->ctrl3]);
    }

    public function test_refuses_a_booking_without_quotation_before_deleting_anything(): void
    {
        $booking = $this->booking(['impor_expor' => 'i']);
        $this->expense($booking);

        try {
            app(ImportQuotationCosts::class)($booking, 7);
            $this->fail('A importação deveria ser recusada.');
        } catch (DomainException $e) {
            $this->assertSame('A reserva não tem cotação.', $e->getMessage());
            $this->assertSame(1, FinExpensesValues::count());
        }
    }

    public function test_refuses_an_import_type_that_has_no_quotation_import(): void
    {
        $booking = $this->booking(['impor_expor' => 'i', 'tipo_embarque' => 's', 'cotacao_impo_id' => 3]);

        $this->expectExceptionMessage('Esta reserva não importa custos de cotação.');

        app(ImportQuotationCosts::class)($booking, 7);
    }

    public function test_warns_when_documents_or_a_cover_use_the_expenses_that_the_import_deletes(): void
    {
        $booking = $this->booking(['impor_expor' => 'i']);
        $expense = $this->expense($booking);
        $this->assertNull(app(ImportQuotationCosts::class)->warning($booking));

        DB::table('fin_charges_items')->insert(['ref_charge' => 1, 'ref_currency' => 1, 'created_at' => '2026-10-01', 'ref_expense' => $expense->getKey()]);
        DB::table('fin_debit_note_items')->insert(['ref_debit_note' => 1, 'ref_expense' => $expense->getKey()]);
        DB::table('capa_processo')->insert(['embarque_id' => $booking->getKey(), 'moeda_id' => 1]);

        $warning = app(ImportQuotationCosts::class)->warning($booking);

        $this->assertStringContainsString('uma Cobrança, uma Nota de Débito, uma Capa de Contabilidade', $warning);
    }

    public function test_export_never_warns_because_it_deletes_nothing(): void
    {
        $booking = $this->booking();
        $expense = $this->expense($booking);
        DB::table('fin_charges_items')->insert(['ref_charge' => 1, 'ref_currency' => 1, 'created_at' => '2026-10-01', 'ref_expense' => $expense->getKey()]);

        $this->assertNull(app(ImportQuotationCosts::class)->warning($booking));
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId([...['booking' => 'BKG', 'impor_expor' => 'e', 'tipo_embarque' => 'm'], ...$attributes]));
    }

    /** @param  array<string, mixed>  $attributes */
    private function expense(Embarque $booking, array $attributes = []): FinExpensesValues
    {
        return FinExpensesValues::find(DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => $booking->getKey(), 'ref_expense' => 500, ...$attributes]));
    }

    /** @param  array<string, mixed>  $attributes */
    private function quotationItem(string $table, array $attributes): void
    {
        DB::table($table)->insert(['moeda_compra_id' => 1, ...$attributes]);
    }
}
