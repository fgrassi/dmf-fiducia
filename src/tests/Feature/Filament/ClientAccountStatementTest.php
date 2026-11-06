<?php

namespace Tests\Feature\Filament;

use App\Actions\Financeiro\ClientAccountStatement as ClientAccountStatementReport;
use App\Actions\Financeiro\ExportClientAccountStatement;
use App\Filament\Pages\ClientAccountStatement;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ClientAccountStatementTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-04-30 10:00:00');
        DB::table('pessoa')->insert(['pessoa_id' => 7, 'nome' => 'Cliente Sete', 'cnpj' => '11.222.333/0001-44']);
        DB::table('embarque')->insert([
            ['embarque_id' => 1, 'booking' => 'BKG-1', 'cliente_id' => 7, 'tipo_embarque' => 'm'],
            ['embarque_id' => 2, 'booking' => 'BKG-2', 'cliente_id' => 8, 'tipo_embarque' => 'm'],
        ]);
        DB::table('bl')->insert(['bl_id' => 1, 'embarque_id' => 1, 'mbl_number' => 'MBL-1']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_only_the_legacy_credentials_open_the_page(): void
    {
        $this->actingAs($this->user('import'))->get(ClientAccountStatement::getUrl())->assertForbidden();
        $this->actingAs($this->user('supervisor'))->get(ClientAccountStatement::getUrl())->assertOk();
    }

    public function test_requires_the_client_and_the_start_date(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Informe o cliente e a data inicial.');

        $this->report(['cliente_id' => null]);
    }

    public function test_statement_lists_open_items_of_any_age_up_to_the_end_date(): void
    {
        $open = $this->charge(['total_value' => 1000, 'due_date' => '2026-03-20']);
        $partial = $this->charge(['total_value' => 1000, 'discount' => 100, 'received_value' => 400, 'created_at' => '2026-02-10', 'due_date' => '2026-05-10']);
        $this->charge(['total_value' => 100.005, 'received_value' => 100]);
        $this->charge(['status' => 1, 'total_value' => 500, 'received_value' => 500]);
        $this->charge(['total_value' => 900], embarque: 2);
        $this->charge(['total_value' => 700, 'created_at' => '2026-05-02']);

        $report = $this->report();

        $this->assertSame([$partial, $open], array_column($report['statement'], 'id'));
        [$partialRow, $openRow] = $report['statement'];
        $this->assertSame(['Partially Matched', 500.0, null], [$partialRow['match_status'], $partialRow['balance_open'], $partialRow['days_overdue']]);
        $this->assertSame(['Open', 1000.0, 41], [$openRow['match_status'], $openRow['balance_open'], $openRow['days_overdue']]);
        $this->assertSame('MBL-1', $openRow['bl_ref']);
        $this->assertSame(1500.0, $report['totais']['em_aberto']);
        $this->assertSame('Cliente Sete', $report['cliente_nome']);
    }

    public function test_invoices_cover_only_the_period_and_manual_settlement_counts_as_fully_received(): void
    {
        $this->charge(['total_value' => 1000, 'created_at' => '2026-02-10']);
        $manual = $this->charge(['status' => 3, 'total_value' => 800, 'discount' => 50, 'due_date' => '2026-04-10']);
        $bank = $this->charge(['status' => 1, 'total_value' => 500, 'received_value' => 500, 'payment_date' => '2026-03-15']);

        $report = $this->report();

        $this->assertSame([$manual, $bank], array_column($report['invoices'], 'id'));
        [$manualRow, $bankRow] = $report['invoices'];
        $this->assertSame([750.0, 'Fully Matched', '2026-04-10', 0.0], [$manualRow['received_value'], $manualRow['match_status'], $manualRow['payment_date'], $manualRow['balance_open']]);
        $this->assertSame('2026-03-15', $bankRow['payment_date']);
        $this->assertSame(['faturado' => 1300.0, 'recebido' => 1250.0], ['faturado' => $report['totais']['faturado'], 'recebido' => $report['totais']['recebido']]);
    }

    public function test_payments_come_from_receipts_boletos_and_settlements_without_either(): void
    {
        $withReceipt = $this->charge(['status' => 1, 'total_value' => 500, 'received_value' => 500, 'payment_date' => '2026-03-15']);
        DB::table('cmn_files')->insert(['id' => 1, 'created_at' => '2026-03-14 09:00:00', 'approved_at' => '2026-03-16 10:00:00']);
        DB::table('fin_payment_receipt')->insert(['ref_charge' => $withReceipt, 'ref_file' => 1, 'valor' => 200]);

        $withBoleto = $this->charge(['status' => 2, 'total_value' => 300, 'received_value' => 300]);
        DB::table('fin_payment_boleto')->insert([['ref_file' => 1, 'ref_charge' => $withBoleto, 'due_to' => '2026-03-01'], ['ref_file' => 1, 'ref_charge' => $withBoleto, 'due_to' => '2026-03-25']]);
        // Com recibo, o boleto da mesma cobrança não vira outra linha.
        DB::table('fin_payment_boleto')->insert(['ref_file' => 1, 'ref_charge' => $withReceipt, 'due_to' => '2026-03-02']);

        $settled = $this->charge(['status' => 1, 'total_value' => 400, 'received_value' => 400, 'payment_date' => '2026-03-20']);
        $partial = $this->charge(['total_value' => 1000, 'received_value' => 200]);
        DB::table('fin_payment_receipt')->insert(['ref_charge' => $partial, 'ref_file' => null, 'valor' => 200]);

        $payments = $this->report()['payments'];

        $this->assertSame([[$withReceipt, 'R-1'], [$withBoleto, 'B-2'], [$settled, 'S-'.$settled], [$partial, 'R-2']], array_map(fn ($p) => [$p['invoice_ref'], $p['payment_ref']], $payments));
        $this->assertSame(['2026-03-16 10:00:00', '2026-03-25', '2026-03-20', null], array_column($payments, 'payment_date'));
        $this->assertSame(['Fully Allocated', 'Fully Allocated', 'Fully Allocated', 'Partially Allocated'], array_column($payments, 'allocation_status'));
        $this->assertSame([500.0, 300.0, 400.0, 1000.0], array_column($payments, 'amount_applied'));
    }

    public function test_page_shows_the_statement_and_downloads_the_excel_with_the_four_sheets(): void
    {
        $this->actingAs($this->user('financial'));
        $this->charge(['total_value' => 1000, 'num_nota_fiscal' => 'NF-77', 'due_date' => '2026-03-20']);

        Livewire::test(ClientAccountStatement::class)
            ->call('send')
            ->assertHasFormErrors(['cliente_id' => 'required', 'data_ini' => 'required'])
            ->fillForm(['cliente_id' => 7, 'data_ini' => '2026-03-01', 'data_fim' => '2026-03-31'])
            ->call('send')
            ->assertSee(['Extrato de Conta - Cliente Sete', '11.222.333/0001-44', 'NF-77', 'R$ 1.000,00', 'BKG-1', 'Open'])
            ->call('excel')
            ->assertFileDownloaded();

        $book = app(ExportClientAccountStatement::class)->build($this->report());

        $this->assertSame(['Info', 'Statement', 'Invoices', 'Payments'], $book->getSheetNames());
        $this->assertSame('NF-77', $book->getSheetByName('Statement')->getCell('B2')->getValue());
        $this->assertEquals(1000, $book->getSheetByName('Statement')->getCell('I3')->getValue());
        $this->assertSame('Cliente Sete', $book->getSheetByName('Info')->getCell('B2')->getValue());
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function report(array $filters = []): array
    {
        return app(ClientAccountStatementReport::class)($filters + ['cliente_id' => 7, 'data_ini' => '2026-03-01', 'data_fim' => '2026-03-31']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function charge(array $attributes, int $embarque = 1): int
    {
        return DB::table('fin_charges')->insertGetId(['ref_type' => 1, 'ref_booking' => $embarque, 'ref_bl' => $embarque === 1 ? 1 : null, 'status' => 0, 'created_at' => '2026-03-05 08:00:00', ...$attributes]);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}
