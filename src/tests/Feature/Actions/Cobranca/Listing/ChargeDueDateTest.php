<?php

namespace Tests\Feature\Actions\Cobranca\Listing;

use App\Actions\Cobranca\Listing\ChargeDueDate;
use App\Models\FinCharges;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ChargeDueDateTest extends TestCase
{
    use LegacySchema;

    public function test_counts_client_term_from_creation_day_as_day_one(): void
    {
        $charge = $this->charge(term: ['prazo_exp' => 10]);

        $this->assertSame('2026-10-10', app(ChargeDueDate::class)->final($charge)->toDateString());
    }

    public function test_reads_days_from_generic_term_text_when_operation_term_is_null(): void
    {
        $charge = $this->charge(term: ['prazo' => '10 dias', 'prazo_exp' => null]);

        $this->assertSame('2026-10-10', app(ChargeDueDate::class)->final($charge)->toDateString());
    }

    /** Paridade com resolverPrazoDiasCliente: prazo_exp/prazo_imp têm default 0, e 0 não cai no prazo genérico. */
    public function test_zero_operation_term_ignores_generic_term(): void
    {
        $charge = $this->charge(term: ['prazo' => '10', 'prazo_exp' => 0]);

        $this->assertSame('2026-10-01', app(ChargeDueDate::class)->final($charge)->toDateString());
    }

    public function test_operation_specific_term_overrides_generic_term(): void
    {
        $charge = $this->charge(term: ['prazo' => '10', 'prazo_imp' => '5'], operation: 'i');

        $this->assertSame('2026-10-05', app(ChargeDueDate::class)->final($charge)->toDateString());
    }

    public function test_keeps_due_date_edited_away_from_creation_day(): void
    {
        $charge = $this->charge(term: ['prazo_exp' => 10], due: '2026-11-20');

        $this->assertSame('2026-11-20', app(ChargeDueDate::class)->final($charge)->toDateString());
    }

    public function test_without_client_term_uses_stored_due_date(): void
    {
        $charge = $this->charge(term: null);

        $this->assertSame('2026-10-01', app(ChargeDueDate::class)->final($charge)->toDateString());
    }

    public function test_displays_real_due_date_even_when_past(): void
    {
        $this->travelTo('2026-10-20');

        $this->assertSame('2026-10-10', app(ChargeDueDate::class)->display($this->charge(term: ['prazo_exp' => 10]))->toDateString());
    }

    public function test_pdf_shows_on_presentation_when_due_today_or_past(): void
    {
        $this->travelTo('2026-10-10');

        $this->assertSame('Na apresentação', app(ChargeDueDate::class)->forPdf($this->charge(term: ['prazo_exp' => 10])));
    }

    public function test_pdf_shows_the_date_when_due_in_the_future(): void
    {
        $this->travelTo('2026-10-09');

        $this->assertSame('10/10/2026', app(ChargeDueDate::class)->forPdf($this->charge(term: ['prazo_exp' => 10])));
    }

    private function charge(?array $term, string $operation = 'e', string $due = '2026-10-01'): FinCharges
    {
        DB::table('pessoa')->insert(['pessoa_id' => 100, 'nome' => 'ACME']);
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG1', 'impor_expor' => $operation, 'cliente_id' => 100]);

        if ($term !== null) {
            DB::table('fin_clients_boleto')->insert(['ref_client' => 100, ...$term]);
        }

        $id = DB::table('fin_charges')->insertGetId(['ref_type' => 1, 'ref_booking' => 10, 'created_at' => '2026-10-01', 'due_date' => $due]);

        return FinCharges::find($id);
    }
}
