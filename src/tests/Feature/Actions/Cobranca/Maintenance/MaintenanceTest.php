<?php

namespace Tests\Feature\Actions\Cobranca\Maintenance;

use App\Actions\Cobranca\Items\DeleteItems;
use App\Actions\Cobranca\Items\EditIof;
use App\Actions\Cobranca\Maintenance\DeleteCharge;
use App\Actions\Cobranca\Maintenance\EditDueDate;
use App\Models\Embarque;
use App\Models\FinCharges;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use BuildsCharges;
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-27 09:30');
    }

    public function test_delete_charge_needs_manager_or_delete_permission(): void
    {
        $this->assertTrue(DeleteCharge::allowed($this->user('excluir_cobranca')));
        $this->assertTrue(DeleteCharge::allowed($this->user('manager')));
        $this->assertFalse(DeleteCharge::allowed($this->user('financial')));
        $this->assertFalse(DeleteCharge::allowed(null));
    }

    public function test_delete_charge_removes_everything_and_reopens_the_booking_expenses(): void
    {
        $charge = $this->charge();
        $this->item($charge, 100);
        DB::table('fin_custom_currency_rate')->insert(['ref_currency' => 2, 'ref_charge' => $charge->id, 'note' => 'n', 'created_at' => '2026-10-20', 'created_by' => 1, 'custom_rate' => 6]);
        DB::table('embarque')->where('embarque_id', 10)->update(['ctr_desp' => 0]);

        app(DeleteCharge::class)($charge, $this->user('manager'));

        $this->assertNull(FinCharges::find($charge->id));
        $this->assertSame([0, 0], [DB::table('fin_charges_items')->count(), DB::table('fin_custom_currency_rate')->count()]);
        $this->assertSame(1, (int) Embarque::find(10)->ctr_desp);
        $this->assertSame(['exclusao', 'Cobranca Excluída'], [DB::table('fin_charges_log')->value('acao'), DB::table('rastreamento')->value('assunto')]);
        $this->assertSame(['Cobranca Excluída'], $this->sentSubjects()->all());
    }

    public function test_delete_charge_is_blocked_by_receipts_boleto_receipts_nf_files_and_issued_nfse(): void
    {
        $charge = $this->charge();
        DB::table('fin_payment_receipt')->insert(['ref_charge' => $charge->id, 'valor' => 10]);
        DB::table('recibo_boleto')->insert(['cobranca_id' => $charge->id]);
        DB::table('fin_charges_arquivo_nota')->insert(['cobranca_id' => $charge->id, 'arquivo' => 'nf.pdf']);
        DB::table('fin_charges_nfse')->insert(['ref_charge' => $charge->id, 'id_externo' => 'x', 'status' => 'autorizada']);

        $reasons = DeleteCharge::blockingReasons($charge);

        $this->assertCount(4, $reasons);
        $this->assertStringContainsString('NFS-e emitida', $reasons[0]);
        $this->assertStringContainsString('recibo de pagamento', $reasons[1]);
        $this->expectException(DomainException::class);
        app(DeleteCharge::class)($charge, $this->user('manager'));
    }

    public function test_delete_charge_without_permission_keeps_the_charge(): void
    {
        $charge = $this->charge();

        try {
            app(DeleteCharge::class)($charge, $this->user('financial'));
            $this->fail('Esperava DomainException.');
        } catch (DomainException $e) {
            $this->assertSame('Você não tem permissão para excluir cobranças.', $e->getMessage());
        }

        $this->assertNotNull(FinCharges::find($charge->id));
    }

    public function test_edit_due_date_saves_and_logs_the_change(): void
    {
        $charge = $this->charge(['due_date' => '2026-11-01']);

        app(EditDueDate::class)($charge, '2026-11-15', $this->user()->getKey());

        $this->assertSame('2026-11-15', substr((string) DB::table('fin_charges')->value('due_date'), 0, 10));
        $this->assertSame('Vencimento alterado de 2026-11-01 para 2026-11-15', substr(DB::table('fin_charges_log')->value('historico'), 0, 56));

        $this->expectExceptionMessage('Data de vencimento inválida.');
        app(EditDueDate::class)($charge, 'não é data', null);
    }

    public function test_edit_iof_sets_the_total_to_items_plus_the_typed_iof(): void
    {
        $charge = $this->charge(['valor_iof' => 5]);
        $this->item($charge, 100, stale: 100);
        $this->item($charge, 50, expense: 501, stale: 50);

        app(EditIof::class)($charge, '1.234,50', null);

        $this->assertSame([1234.5, 1384.5], [(float) $charge->refresh()->valor_iof, (float) $charge->total_value]);
        $this->assertStringStartsWith('IOF alterado de 5', DB::table('fin_charges_log')->value('historico'));

        $this->expectExceptionMessage('Valor do IOF inválido.');
        app(EditIof::class)($charge, 'abc', null);
    }

    public function test_delete_items_removes_the_selected_expenses_and_recomputes_iof_and_total(): void
    {
        $this->usdRate();
        $charge = $this->charge(['total_value' => 999]);
        $usd = $this->item($charge, 100, currency: 2, stale: 500);
        $brl = $this->item($charge, 200, expense: 501, stale: 200);
        $removed = $this->item($charge, 30, expense: 502, stale: 30);
        DB::table('embarque')->where('embarque_id', 10)->update(['ctr_desp' => 0]);

        $message = app(DeleteItems::class)($charge, [$removed], 'item lançado em duplicidade', $this->user());

        $this->assertSame('Despesa(s) excluída(s) com sucesso.', $message);
        $charge->refresh();
        // IOF 3,5% só sobre o item em USD (500): 17,50; total = 500 + 200 + 17,50
        $this->assertSame([17.5, 717.5], [(float) $charge->valor_iof, (float) $charge->total_value]);
        $this->assertSame([$usd, $brl], DB::table('fin_charges_items')->orderBy('id')->pluck('id')->all());
        $this->assertSame(1, (int) Embarque::find(10)->ctr_desp);
        $this->assertSame('exclusao_despesa', DB::table('fin_charges_log')->value('acao'));
        $this->assertSame(['DMF Logistics: Exclusão de despesas na IC #'.$charge->id.' — BKG10'], $this->sentSubjects()->all());
    }

    public function test_delete_items_rules(): void
    {
        $charge = $this->charge();
        $a = $this->item($charge, 100, stale: 100);
        $b = $this->item($charge, 50, expense: 501, stale: 50);
        $delete = fn (array $ids, string $why = 'justificativa longa o bastante', ?FinCharges $c = null) => fn () => app(DeleteItems::class)($c ?? $charge, $ids, $why, $this->user());

        $this->assertRefused($delete([$a], 'curta'), 'A justificativa deve ter no mínimo 15 caracteres.');
        $this->assertRefused($delete([]), 'Selecione ao menos uma despesa para excluir.');
        $this->assertRefused($delete([$a, $b]), 'Não é permitido excluir todas as despesas da cobrança.');
        $this->assertRefused($delete([999]), 'Despesa #999 não pertence a esta cobrança.');

        $single = $this->charge(booking: ['embarque_id' => 11]);
        $only = $this->item($single, 10);
        $this->assertRefused($delete([$only], c: $single), 'A cobrança precisa ter mais de uma despesa para permitir exclusão parcial.');

        DB::table('fin_charges_nfse')->insert(['ref_charge' => $charge->id, 'id_externo' => 'x', 'status' => 'enfileirada']);
        $this->assertRefused($delete([$a]), 'Não é possível excluir despesas enquanto houver NFS-e em processamento.');

        DB::table('fin_charges')->where('id', $charge->id)->update(['status' => 1]);
        $charge->refresh();
        $this->assertRefused($delete([$a]), 'Somente cobranças em aberto permitem exclusão de despesas.');
        $this->assertSame(2, DB::table('fin_charges_items')->where('ref_charge', $charge->id)->count());
    }

    private function assertRefused(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail('Esperava DomainException.');
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
