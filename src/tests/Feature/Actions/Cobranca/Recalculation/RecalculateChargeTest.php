<?php

namespace Tests\Feature\Actions\Cobranca\Recalculation;

use App\Actions\Cobranca\Recalculation\AfterRecalculation;
use App\Actions\Cobranca\Recalculation\RecalculateCharge;
use App\Models\FinCharges;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class RecalculateChargeTest extends TestCase
{
    use BuildsCharges;
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-27 10:00');
        $this->usdRate();
        $this->after = $this->mock(AfterRecalculation::class);
        $this->after->shouldReceive('__invoke')->byDefault();
    }

    private $after;

    private function recalculate(FinCharges $charge, string $mode = RecalculateCharge::SINGLE, bool $withoutIof = false, bool $keepIof = false)
    {
        return app(RecalculateCharge::class)($charge, $mode, $withoutIof, $keepIof, $this->user());
    }

    /** USD 100 (cotação 5,1 = 510) + BRL 200; IOF de 3,5% só sobre o item em moeda estrangeira. */
    private function chargeWithItems(array $attributes = []): FinCharges
    {
        $charge = $this->charge($attributes);
        $this->item($charge, 100, currency: 2);
        $this->item($charge, 200, currency: 1, expense: 501);

        return $charge;
    }

    public function test_recalculates_items_with_the_current_rate_and_adds_iof_to_the_total(): void
    {
        $charge = $this->chargeWithItems();

        $this->after->shouldReceive('__invoke')->once();
        $this->recalculate($charge);

        $this->assertSame([510.0, 200.0], $this->itemValues($charge));
        $charge->refresh();
        $this->assertSame([17.85, 727.85], [(float) $charge->valor_iof, (float) $charge->total_value]);
        $this->assertSame(5.1, (float) DB::table('fin_charges_items')->orderBy('id')->value('current_rate_value'));
        $this->assertSame('recalculo', DB::table('fin_charges_log')->value('acao'));
        $this->assertSame('Cobranca Recalculada', DB::table('rastreamento')->value('assunto'));
    }

    public function test_without_iof_zeroes_it_and_keep_iof_preserves_the_current_one(): void
    {
        $charge = $this->chargeWithItems(['valor_iof' => 9.99]);

        $this->recalculate($charge, keepIof: true);
        $this->assertSame([9.99, 719.99], [(float) $charge->refresh()->valor_iof, (float) $charge->total_value]);

        $this->recalculate($charge, withoutIof: true);
        $this->assertSame([0.0, 710.0], [(float) $charge->refresh()->valor_iof, (float) $charge->total_value]);
    }

    public function test_discount_expenses_stay_negative(): void
    {
        $charge = $this->charge();
        $this->item($charge, 80, expense: 892);
        $this->item($charge, 200, expense: 501);

        $this->recalculate($charge);

        $this->assertSame([-80.0, 200.0], $this->itemValues($charge));
    }

    public function test_negotiated_rate_of_the_charge_wins_over_the_table(): void
    {
        $charge = $this->chargeWithItems();
        DB::table('fin_custom_currency_rate')->insert(['ref_currency' => 2, 'ref_charge' => $charge->id, 'note' => 'acordo', 'created_at' => '2026-10-20', 'created_by' => 1, 'custom_rate' => 6]);

        $this->recalculate($charge);

        $this->assertSame([600.0, 200.0], $this->itemValues($charge));
    }

    public function test_paid_charge_and_nfse_in_progress_are_refused(): void
    {
        $paid = $this->chargeWithItems(['status' => 1]);
        $this->assertRefused($paid, 'Não é possível recalcular cobranças pagas.');

        $processing = $this->charge(booking: ['embarque_id' => 11]);
        $this->item($processing, 100);
        DB::table('fin_charges_nfse')->insert(['ref_charge' => $processing->id, 'id_externo' => 'x', 'status' => 'processando']);
        $this->assertRefused($processing, 'Não é possível recalcular enquanto houver NFS-e em processamento. Aguarde a finalização e tente novamente.');
    }

    public function test_outdated_rate_refuses_and_warns_finance_without_touching_the_items(): void
    {
        $charge = $this->chargeWithItems();
        DB::table('fin_currencies_rates_values')->delete();

        $this->assertRefused($charge, 'Não foi possível atualizar a cobrança. Taxas desatualizadas.');

        $this->assertSame([1.0, 1.0], $this->itemValues($charge));
        $this->assertContains('DMF Logistics: AVISO: Cotacao padrão desatualizada para a moeda USD', $this->sentSubjects()->all());
    }

    public function test_merge_moves_items_and_negotiated_rates_to_the_oldest_charge_and_deletes_the_others(): void
    {
        $first = $this->charge();
        $this->item($first, 100, currency: 2);
        $second = $this->charge();
        $this->item($second, 200, expense: 501);
        DB::table('fin_custom_currency_rate')->insert(['ref_currency' => 2, 'ref_charge' => $second->id, 'note' => 'n', 'created_at' => '2026-10-20', 'created_by' => 1, 'custom_rate' => 6]);

        $target = $this->recalculate($second, RecalculateCharge::MERGE);

        $this->assertTrue($target->is($first));
        $this->assertNull(FinCharges::find($second->id));
        $this->assertSame([600.0, 200.0], $this->itemValues($first));
        $this->assertSame($first->id, (int) DB::table('fin_custom_currency_rate')->value('ref_charge'));
    }

    public function test_all_mode_recalculates_every_open_charge_of_the_booking_with_the_negotiated_rates(): void
    {
        $first = $this->charge();
        $this->item($first, 100, currency: 2);
        $second = $this->charge();
        $this->item($second, 100, currency: 2, expense: 501);
        $paid = $this->charge(['status' => 1]);
        $this->item($paid, 100, currency: 2, expense: 502);
        DB::table('fin_custom_currency_rate')->insert(['ref_currency' => 2, 'ref_charge' => $first->id, 'note' => 'n', 'created_at' => '2026-10-20', 'created_by' => 1, 'custom_rate' => 6]);

        $this->after->shouldReceive('__invoke')->twice();
        $this->recalculate($first, RecalculateCharge::ALL);

        $this->assertSame([600.0], $this->itemValues($first));
        $this->assertSame([600.0], $this->itemValues($second));
        $this->assertSame([1.0], $this->itemValues($paid));
    }

    public function test_all_mode_skips_pdf_email_and_nfse_for_a_charge_with_outdated_rates(): void
    {
        $outdated = $this->charge();
        $this->item($outdated, 100, currency: 2);
        $brl = $this->charge();
        $this->item($brl, 200, currency: 1, expense: 501);
        DB::table('fin_currencies_rates_values')->delete();

        $notified = [];
        $this->after->shouldReceive('__invoke')->andReturnUsing(function (FinCharges $c) use (&$notified) {
            $notified[] = $c->id;
        });
        $this->recalculate($outdated, RecalculateCharge::ALL);

        $this->assertSame([1.0], $this->itemValues($outdated));
        $this->assertSame([$brl->id], $notified);
    }

    public function test_after_recalculation_runs_for_each_recalculated_charge_with_the_nfse_reason(): void
    {
        $charge = $this->chargeWithItems();

        $this->after->shouldReceive('__invoke')->once()->withArgs(fn (FinCharges $c, $userId, $reason, $justification) => $c->is($charge) && $reason === '01' && $justification === 'preço errado');
        app(RecalculateCharge::class)($charge, RecalculateCharge::SINGLE, false, false, $this->user(), ['reason' => '1', 'justification' => 'preço errado']);

        $this->assertStringContainsString('Motivo subst. NFS-e: 01', DB::table('fin_charges_log')->value('historico'));
    }

    private function assertRefused(FinCharges $charge, string $message): void
    {
        try {
            $this->recalculate($charge);
            $this->fail('Esperava DomainException.');
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
