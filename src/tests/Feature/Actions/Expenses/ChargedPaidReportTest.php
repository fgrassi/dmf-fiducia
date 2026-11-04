<?php

namespace Tests\Feature\Actions\Expenses;

use App\Actions\Expenses\ChargedPaidReport;
use App\Actions\Expenses\ExportChargedPaidReport;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ChargedPaidReportTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'Container', 'acronym' => 'CNT']);
        DB::table('fin_expenses')->insert([['id' => 1, 'name' => 'THC', 'ref_unity' => 1], ['id' => 2, 'name' => 'AFRMM', 'ref_unity' => null]]);
        DB::table('pessoa')->insert(['pessoa_id' => 7, 'nome' => 'Cliente Sete', 'cnpj' => '123']);
        DB::table('embarque')->insert([
            ['embarque_id' => 10, 'booking' => 'B-10', 'cliente_id' => 7, 'saida_prevista' => '2026-10-01', 'chegada_efetiva' => '2026-10-20'],
            ['embarque_id' => 11, 'booking' => 'A-11', 'cliente_id' => 7, 'saida_prevista' => '2026-11-01', 'chegada_prevista' => '2026-11-25'],
        ]);
    }

    public function test_merges_charged_and_paid_per_expense_and_booking_with_totals(): void
    {
        $this->charged(fev: 100, expense: 1, booking: 10, value: 300, chargedAt: '2026-10-05 10:00:00');
        $this->charged(fev: 100, expense: 1, booking: 10, value: 50, chargedAt: '2026-10-03 09:00:00');
        $this->paidByOrder(fev: 100, value: 120, paidAt: '2026-10-08');
        $this->paidByOther(fev: 100, value: 30, paidAt: '2026-10-09');
        $this->charged(fev: 101, expense: 1, booking: 11, value: 10, chargedAt: '2026-10-06 00:00:00');

        $report = app(ChargedPaidReport::class)($this->filters());

        $this->assertSame(2, $report['totais']['reservas']);
        $this->assertSame([360.0, 150.0, 210.0], [$report['totais']['cobrado'], $report['totais']['pago'], $report['totais']['resultado']]);
        $this->assertSame('THC / CNT / 01/10/2026 até 31/10/2026', $report['grupos'][0]['titulo']);

        [$first, $second] = $report['grupos'][0]['rows'];
        $this->assertSame(['A-11', 'B-10'], [$first['booking'], $second['booking']]);
        $this->assertSame('Cliente Sete (123)', $second['cliente_nome']);
        $this->assertSame([350.0, 150.0, 200.0], [$second['cobrado'], $second['pago'], $second['resultado']]);
        $this->assertSame(['2026-10-03 09:00:00', '2026-10-08'], [$second['data_cobranca'], $second['data_pagamento']]);
        $this->assertSame(['2026-10-01', '2026-10-20'], [$second['etd'], $second['eta']]);
    }

    public function test_cancelled_and_disabled_orders_do_not_count_as_paid(): void
    {
        $this->charged(fev: 100, expense: 1, booking: 10, value: 100, chargedAt: '2026-10-05 10:00:00');
        $this->paidByOrder(fev: 100, value: 40, paidAt: '2026-10-08', status: 2);
        $this->paidByOrder(fev: 100, value: 25, paidAt: '2026-10-08', disabled: true);
        $this->paidByOrder(fev: 100, value: 7, paidAt: '2026-10-08', status: 1);
        $this->paidByOther(fev: 100, value: 9, paidAt: '2026-10-08', status: 2);

        $row = app(ChargedPaidReport::class)($this->filters())['grupos'][0]['rows'][0];

        $this->assertSame([100.0, 7.0], [$row['cobrado'], $row['pago']]);
    }

    public function test_payment_axis_takes_the_charges_of_the_lines_paid_in_the_period(): void
    {
        $this->charged(fev: 100, expense: 1, booking: 10, value: 100, chargedAt: '2026-08-05 10:00:00');
        $this->paidByOrder(fev: 100, value: 60, paidAt: '2026-10-08');
        $this->charged(fev: 101, expense: 1, booking: 11, value: 999, chargedAt: '2026-10-05 10:00:00');

        $report = app(ChargedPaidReport::class)($this->filters(['tipo_data' => 'pagamento']));

        $this->assertCount(1, $report['grupos'][0]['rows']);
        $this->assertSame([100.0, 60.0], [$report['totais']['cobrado'], $report['totais']['pago']]);
    }

    public function test_charge_axis_takes_the_payments_of_the_lines_charged_in_the_period(): void
    {
        $this->charged(fev: 100, expense: 1, booking: 10, value: 100, chargedAt: '2026-10-05 10:00:00');
        $this->paidByOrder(fev: 100, value: 60, paidAt: '2026-12-01');
        $this->paidByOrder(fev: 101, value: 77, paidAt: '2026-10-08', booking: 11);

        $report = app(ChargedPaidReport::class)($this->filters());

        $this->assertSame([100.0, 60.0], [$report['totais']['cobrado'], $report['totais']['pago']]);
    }

    public function test_etd_axis_filters_by_the_shipment_departure(): void
    {
        $this->charged(fev: 100, expense: 1, booking: 10, value: 100, chargedAt: '2026-01-05 10:00:00');
        $this->charged(fev: 101, expense: 1, booking: 11, value: 5, chargedAt: '2026-01-05 10:00:00');

        $report = app(ChargedPaidReport::class)($this->filters(['tipo_data' => 'etd', 'data_ini' => '2026-11-01', 'data_fim' => '2026-11-30']));

        $this->assertSame(['A-11'], array_column($report['grupos'][0]['rows'], 'booking'));
    }

    public function test_filters_by_client_expense_and_expense_rule(): void
    {
        DB::table('pessoa')->insert(['pessoa_id' => 8, 'nome' => 'Outro']);
        DB::table('embarque')->insert(['embarque_id' => 12, 'booking' => 'C-12', 'cliente_id' => 8]);
        $this->charged(fev: 100, expense: 1, booking: 10, value: 1, chargedAt: '2026-10-05 10:00:00');
        $this->charged(fev: 102, expense: 2, booking: 10, value: 2, chargedAt: '2026-10-05 10:00:00');
        $this->charged(fev: 103, expense: 1, booking: 12, value: 4, chargedAt: '2026-10-05 10:00:00');
        DB::table('regra_despesa_cobranca')->insert(['regra_despesa_cobranca_id' => 3, 'tipo_embarque' => 'm', 'impor_expor' => 'i']);
        DB::table('regra_despesa_cobranca_item')->insert(['despesa_id' => 2, 'regra_despesa_cobranca_id' => 3]);

        $report = fn (array $extra) => app(ChargedPaidReport::class)($this->filters($extra))['totais']['cobrado'];

        $this->assertSame(3.0, $report(['cliente_id' => 7]));
        $this->assertSame(5.0, $report(['despesa_ids' => [1]]));
        $this->assertSame(2.0, $report(['regra_ids' => [3]]));
    }

    public function test_cover_file_filters_the_shipments_and_brings_manual_covers_in_reais_or_converted(): void
    {
        DB::table('capa_processo_arquivo')->insert(['capa_processo_arquivo_id' => 5, 'nome' => 'Outubro/2026']);
        DB::table('capa_processo')->insert(['embarque_id' => 10, 'moeda_id' => 1, 'capa_processo_arquivo_id' => 5]);
        $this->charged(fev: 100, expense: 1, booking: 10, value: 100, chargedAt: '2026-10-05 10:00:00');
        $this->charged(fev: 101, expense: 1, booking: 11, value: 9, chargedAt: '2026-10-05 10:00:00');
        DB::table('capa_processo_manual')->insert(['capa_processo_manual_id' => 1, 'numero' => 42, 'moeda_id' => 1, 'ref_currency_br' => 1, 'ref_currency_sr' => 2, 'buying_rate' => 10, 'selling_rate' => 20, 'valor_cotacao' => 5,
            'capa_processo_arquivo_id' => 5, 'cliente_id' => 7, 'ref_expense' => 1, 'created_at' => '2026-10-10 08:00:00', 'payment_date' => '2026-10-12']);
        DB::table('capa_processo_manual')->insert(['capa_processo_manual_id' => 2, 'moeda_id' => 1, 'ref_currency_br' => 1, 'ref_currency_sr' => 1, 'buying_rate' => 1, 'selling_rate' => 1,
            'capa_processo_arquivo_id' => 6, 'ref_expense' => 1, 'created_at' => '2026-10-10 08:00:00']);

        $report = app(ChargedPaidReport::class)(['arquivo_id' => 5, 'tipo_data' => 'cobranca']);

        $this->assertSame('Outubro/2026', $report['periodo']);
        $this->assertSame(['B-10', 'Capa manual 42'], array_column($report['grupos'][0]['rows'], 'booking'));
        $this->assertSame([200.0, 10.0], [$report['totais']['cobrado'], $report['totais']['pago']]);
    }

    public function test_manual_covers_skip_the_etd_and_eta_axes_without_a_cover_file(): void
    {
        DB::table('capa_processo_manual')->insert(['capa_processo_manual_id' => 1, 'moeda_id' => 1, 'ref_currency_br' => 1, 'ref_currency_sr' => 1, 'buying_rate' => 1, 'selling_rate' => 1, 'ref_expense' => 1, 'created_at' => '2026-11-10 08:00:00']);

        $report = app(ChargedPaidReport::class)($this->filters(['tipo_data' => 'eta', 'data_ini' => '2026-11-01', 'data_fim' => '2026-11-30']));

        $this->assertSame([], $report['grupos']);
    }

    public function test_rejects_invalid_filters_with_the_legacy_messages(): void
    {
        $report = app(ChargedPaidReport::class);

        foreach ([
            [['data_ini' => '2026-10-01'], 'Informe a data inicial e a data final.'],
            [[], 'Informe o período ou o arquivo de capa.'],
            [['data_ini' => '2026-10-31', 'data_fim' => '2026-10-01'], 'A data inicial deve ser anterior ou igual à data final.'],
            [['arquivo_id' => 99], 'Arquivo de capa não encontrado.'],
        ] as [$filters, $message]) {
            try {
                $report($filters);
                $this->fail("sem erro para: {$message}");
            } catch (DomainException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function test_excel_lists_every_row_with_numbers_and_a_total_line(): void
    {
        $this->charged(fev: 100, expense: 1, booking: 10, value: 300, chargedAt: '2026-10-05 10:00:00');
        $this->paidByOrder(fev: 100, value: 120, paidAt: '2026-10-08');
        $report = app(ChargedPaidReport::class)($this->filters());

        $sheet = app(ExportChargedPaidReport::class)->build($report)->getActiveSheet();

        $this->assertSame('Cobradas x pagas', $sheet->getTitle());
        $this->assertSame(['THC', 'CNT', 'B-10', 'Cliente Sete (123)', '01/10/2026', '20/10/2026', '05/10/2026', '08/10/2026', 300.0, 120.0, 180.0], $sheet->rangeToArray('A2:K2', null, true, false)[0]);
        $this->assertSame(['Total', 300.0, 120.0, 180.0], [$sheet->getCell('A3')->getValue(), ...$sheet->rangeToArray('I3:K3', null, true, false)[0]]);
    }

    /** @param  array<string, mixed>  $extra */
    private function filters(array $extra = []): array
    {
        return $extra + ['data_ini' => '2026-10-01', 'data_fim' => '2026-10-31', 'tipo_data' => 'cobranca'];
    }

    private function charged(int $fev, int $expense, int $booking, float $value, string $chargedAt): void
    {
        DB::table('fin_expenses_values')->insertOrIgnore(['id' => $fev, 'ref_expense' => $expense, 'ref_embarque' => $booking]);
        $charge = DB::table('fin_charges')->insertGetId(['ref_type' => 1, 'ref_booking' => $booking, 'created_at' => $chargedAt]);
        DB::table('fin_charges_items')->insert(['ref_charge' => $charge, 'ref_expense' => $fev, 'ref_currency' => 1, 'item_value' => $value, 'created_at' => $chargedAt]);
    }

    private function paidByOrder(int $fev, float $value, string $paidAt, int $status = 0, bool $disabled = false, int $booking = 10): void
    {
        DB::table('fin_expenses_values')->insertOrIgnore(['id' => $fev, 'ref_expense' => 1, 'ref_embarque' => $booking]);
        $order = DB::table('fin_payment_orders')->insertGetId(['ref_armador' => 1, 'created_at' => $paidAt, 'status' => $status, 'payment_date' => $paidAt, 'ref_type' => 1, 'ref_embarque' => $booking]);
        DB::table('fin_payment_order_items')->insert(['ref_payment_order' => $order, 'ref_expense' => $fev, 'ref_currency' => 1, 'item_value' => $value, 'created_at' => $paidAt, 'ref_bl' => 1]);

        if ($disabled) {
            DB::table('fin_payment_order_items_disabled')->insert(['ref_fin_payment_order' => $order, 'ref_expense' => $fev, 'created_by' => 1, 'created_at' => $paidAt, 'status' => 0]);
        }
    }

    private function paidByOther(int $fev, float $value, string $paidAt, int $status = 0): void
    {
        $order = DB::table('ordem_pagamento')->insertGetId(['ref_embarque' => 10, 'created_at' => $paidAt, 'status' => $status, 'payment_date' => $paidAt, 'ref_type' => 1]);
        DB::table('ordem_pagamento_item')->insert(['ordem_pagamento_id' => $order, 'ref_expense' => $fev, 'ref_currency' => 1, 'item_value' => $value, 'created_at' => $paidAt]);
    }
}
