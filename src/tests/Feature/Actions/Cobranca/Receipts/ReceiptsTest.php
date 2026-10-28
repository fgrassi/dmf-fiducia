<?php

namespace Tests\Feature\Actions\Cobranca\Receipts;

use App\Actions\Cobranca\Documents\ReceiptPdf;
use App\Actions\Cobranca\Nfse\EmissionGroups;
use App\Actions\Cobranca\Receipts\CalculateReceiptValues;
use App\Actions\Cobranca\Receipts\DeleteReceipts;
use App\Actions\Cobranca\Receipts\EditReceiptValue;
use App\Actions\Cobranca\Receipts\GenerateReceipts;
use App\Actions\Cobranca\Receipts\SendReceipts;
use App\Enums\Cobranca\ChargeStatus;
use App\Models\FinCharges;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use TCPDF;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ReceiptsTest extends TestCase
{
    use BuildsCharges;
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-27 09:30');
        Storage::fake('tracking_uploads');
    }

    /** @param list<array> $groups */
    private function emissionGroups(array $groups): void
    {
        $this->mock(EmissionGroups::class)->shouldReceive('__invoke')->andReturn($groups);
    }

    private function pdf(): TCPDF
    {
        return tap(Mockery::mock(TCPDF::class), fn ($pdf) => $pdf->shouldReceive('Output')->andReturn('%PDF-fake'));
    }

    private function receipt(FinCharges $charge, ?float $value = null): int
    {
        $file = DB::table('cmn_files')->insertGetId(['name' => 'recibo_x'.uniqid()]);

        return DB::table('fin_payment_receipt')->insertGetId(['ref_charge' => $charge->id, 'ref_file' => $file, 'valor' => $value]);
    }

    public function test_single_receipt_without_groups_is_stored_and_sent(): void
    {
        $this->emissionGroups([]);
        $charge = $this->charge();
        $this->mock(ReceiptPdf::class)->shouldReceive('render')->once()->withArgs(fn ($c, $id, $items, $total, $extras) => $items === null && $total === null && $extras === true)->andReturn([$this->pdf(), 150.0]);
        $this->mock(SendReceipts::class)->shouldReceive('__invoke')->once()->withArgs(fn ($c, $files) => $files === ['recibo_dmf2026'.DB::table('fin_payment_receipt')->value('id').'.pdf']);

        $result = app(GenerateReceipts::class)($charge);

        $receipt = DB::table('fin_payment_receipt')->first();
        $file = DB::table('cmn_files')->first();
        $this->assertSame([150.0, $file->id, "recibo_dmf2026{$receipt->id}"], [(float) $receipt->valor, $receipt->ref_file, $file->name]);
        $this->assertSame(sha1((string) $file->id), $file->uid);
        Storage::disk('tracking_uploads')->assertExists("recibo/{$file->name}.pdf");
        $this->assertSame([[], null], [$result['groups'], $result['warning']]);
    }

    public function test_one_receipt_per_group_with_iof_and_extras_on_the_residual_group(): void
    {
        $groups = [
            ['operation_id' => 1, 'item_ids' => [1], 'total' => 100.0, 'residual' => false],
            ['operation_id' => 2, 'item_ids' => [2], 'total' => 50.0, 'residual' => true],
        ];
        $this->emissionGroups($groups);
        $charge = $this->charge();
        $this->mock(ReceiptPdf::class)->shouldReceive('render')->twice()->andReturnUsing(
            fn ($c, $id, $items, $total, $extras) => [$this->pdf(), $extras ? $total + 10 : $total],
        );
        $this->mock(SendReceipts::class)->shouldReceive('__invoke')->once()->withArgs(fn ($c, $files) => count($files) === 2);

        app(GenerateReceipts::class)($charge);

        $this->assertSame([100.0, 60.0], DB::table('fin_payment_receipt')->orderBy('id')->pluck('valor')->map(fn ($v) => (float) $v)->all());
    }

    public function test_without_residual_the_last_group_carries_the_extras(): void
    {
        $this->emissionGroups([
            ['operation_id' => 1, 'item_ids' => [1], 'total' => 100.0, 'residual' => false],
            ['operation_id' => 2, 'item_ids' => [2], 'total' => 50.0, 'residual' => false],
        ]);
        $seen = [];
        $this->mock(ReceiptPdf::class)->shouldReceive('render')->twice()->andReturnUsing(function ($c, $id, $items, $total, $extras) use (&$seen) {
            $seen[] = $extras;

            return [$this->pdf(), $total];
        });
        $this->mock(SendReceipts::class)->shouldReceive('__invoke')->once();

        app(GenerateReceipts::class)($this->charge());

        $this->assertSame([false, true], $seen);
    }

    public function test_group_failure_still_issues_a_single_receipt_and_warns(): void
    {
        $this->mock(EmissionGroups::class)->shouldReceive('__invoke')->andThrow(new DomainException('sem despesa'));
        $this->mock(ReceiptPdf::class)->shouldReceive('render')->once()->andReturn([$this->pdf(), 10.0]);
        $this->mock(SendReceipts::class)->shouldReceive('__invoke')->once();

        $result = app(GenerateReceipts::class)($this->charge());

        $this->assertSame('Baixa concluída, mas falhou ao fatiar recibos/NFSe: sem despesa', $result['warning']);
        $this->assertSame(1, DB::table('fin_payment_receipt')->count());
    }

    public function test_replace_redraws_the_same_file_with_all_items_and_does_not_email(): void
    {
        $charge = $this->charge();
        $id = $this->receipt($charge, 1.0);
        $this->mock(ReceiptPdf::class)->shouldReceive('render')->once()->withArgs(fn ($c, $rid, $items, $total, $extras) => $rid === $id && $items === null && $total === null)->andReturn([$this->pdf(), 99.0]);
        $this->mock(SendReceipts::class)->shouldNotReceive('__invoke');

        app(GenerateReceipts::class)->replace($charge, $id);

        $this->assertSame([99.0, 1], [(float) DB::table('fin_payment_receipt')->value('valor'), DB::table('cmn_files')->count()]);
    }

    public function test_calculate_values_for_a_single_receipt_sums_received_interest_fine_minus_discount(): void
    {
        $charge = $this->charge(['received_value' => 1000, 'interest' => 10, 'fine' => 20, 'discount' => 5]);
        $id = $this->receipt($charge);

        $this->assertSame([$id => 1025.0], app(CalculateReceiptValues::class)($charge));
        $this->assertSame(1025.0, (float) DB::table('fin_payment_receipt')->value('valor'));
    }

    public function test_calculate_values_for_several_receipts_follows_the_groups(): void
    {
        $charge = $this->charge(['interest' => 10, 'fine' => 20]);
        [$a, $b] = [$this->receipt($charge), $this->receipt($charge)];
        $this->emissionGroups([
            ['operation_id' => 1, 'item_ids' => [1], 'total' => 100.0, 'residual' => false],
            ['operation_id' => 2, 'item_ids' => [2], 'total' => 50.0, 'residual' => false],
        ]);

        $this->assertSame([$a => 100.0, $b => 80.0], app(CalculateReceiptValues::class)($charge));
    }

    public function test_calculate_values_refuses_when_receipts_and_groups_differ(): void
    {
        $charge = $this->charge();
        $this->receipt($charge);
        $this->receipt($charge);
        $this->emissionGroups([['operation_id' => 1, 'item_ids' => [1], 'total' => 100.0, 'residual' => false]]);

        $this->expectExceptionMessage('quantidade de recibos (2) difere dos grupos de NFS-e (1)');

        app(CalculateReceiptValues::class)($charge);
    }

    public function test_edit_receipt_value_parses_pt_br_and_clears_when_empty(): void
    {
        $charge = $this->charge();
        $id = $this->receipt($charge);

        app(EditReceiptValue::class)($charge, $id, '1.234,56');
        $this->assertSame(1234.56, (float) DB::table('fin_payment_receipt')->value('valor'));

        app(EditReceiptValue::class)($charge, $id, '');
        $this->assertNull(DB::table('fin_payment_receipt')->value('valor'));

        $this->expectExceptionMessage('Valor do recibo inválido.');
        app(EditReceiptValue::class)($charge, $id, 'abc');
    }

    public function test_delete_receipts_reopens_the_charge_and_removes_receipts_boletos_and_files(): void
    {
        $charge = $this->charge(['status' => 1]);
        $this->receipt($charge);
        $boletoFile = DB::table('cmn_files')->insertGetId(['name' => 'decl']);
        DB::table('fin_payment_boleto')->insert(['ref_file' => $boletoFile, 'ref_charge' => $charge->id, 'due_to' => '2026-10-27']);

        app(DeleteReceipts::class)($charge, $this->user('manager'));

        $this->assertSame(ChargeStatus::Open, $charge->refresh()->status);
        $this->assertSame([0, 0, 0], [DB::table('fin_payment_receipt')->count(), DB::table('fin_payment_boleto')->count(), DB::table('cmn_files')->count()]);
        $this->assertSame([1, 0], [DB::table('fin_charges_log')->value('status_anterior'), DB::table('fin_charges_log')->value('status_novo')]);
        $this->assertSame('Recibo/Declaração Excluída', DB::table('rastreamento')->value('assunto'));
        $this->assertSame(['Recibo/Declaração Excluída'], $this->sentSubjects()->all());
    }

    public function test_delete_receipts_is_refused_without_permission_open_status_or_with_issued_nfse(): void
    {
        $charge = $this->charge(['status' => 1]);
        $this->receipt($charge);

        $this->assertRefused($charge, $this->user('financial'), 'Você não tem permissão para remover o recibo desta cobrança.');
        $this->assertRefused($this->charge(['status' => 0], ['embarque_id' => 11]), $this->user('manager'), 'Você não tem permissão para remover o recibo desta cobrança.');

        DB::table('fin_charges_nfse')->insert(['ref_charge' => $charge->id, 'id_externo' => 'x', 'status' => 'autorizada']);
        $this->assertRefused($charge, $this->user('manager'), 'Não é possível remover o recibo: já existe NFS-e emitida (autorizada).');
        $this->assertSame(1, DB::table('fin_payment_receipt')->count());
    }

    private function assertRefused(FinCharges $charge, $user, string $message): void
    {
        try {
            app(DeleteReceipts::class)($charge, $user);
            $this->fail('Esperava DomainException.');
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
