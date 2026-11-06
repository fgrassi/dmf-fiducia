<?php

namespace Tests\Feature\Actions\Comissoes;

use App\Actions\Comissoes\AddCommission;
use App\Actions\Comissoes\ChangeCommissionType;
use App\Actions\Comissoes\ExportShippingCommissionsReport;
use App\Actions\Comissoes\PayCommission;
use App\Actions\Comissoes\RemoveCommission;
use App\Actions\Comissoes\ShippingCommissionsReport;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ShippingCommissionsReportTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$']]);
        DB::table('comissao_embarque_responsavel')->insert([['comissao_embarque_responsavel_id' => 1, 'nome' => 'Ana'], ['comissao_embarque_responsavel_id' => 2, 'nome' => 'Beto']]);
    }

    public function test_pays_in_reais_directly_and_converts_other_currencies_ignoring_payments_without_rate(): void
    {
        $dollar = $this->commission(1, 'B-1', currency: 2);
        $this->payment($dollar, 100, 5.0);
        $this->payment($dollar, 50, 6.0);
        $this->payment($dollar, 999, null);
        $real = $this->commission(1, 'B-2', currency: 1);
        $this->payment($real, 200, 5.0);

        $rows = collect(app(ShippingCommissionsReport::class)()['rows'])->keyBy('booking');

        $this->assertSame(800.0, $rows['B-1']['pago']);
        $this->assertSame(6.0, (float) $rows['B-1']['taxa']);
        $this->assertSame('Dólar (US$)', $rows['B-1']['moeda']);
        $this->assertSame(200.0, $rows['B-2']['pago']);
    }

    public function test_counts_containers_of_the_booking_and_totals_the_rows(): void
    {
        $this->commission(1, 'B-1', containers: 3);
        $this->commission(1, 'B-2', containers: 2);

        $report = app(ShippingCommissionsReport::class)();

        $this->assertSame(5, $report['totais']['containers']);
    }

    public function test_orders_by_receipt_date_with_undated_bookings_as_the_newest(): void
    {
        $this->commission(1, 'OLD', receipt: '2026-01-10');
        $this->commission(1, 'NEW', receipt: '2026-03-10');
        $this->commission(1, 'NONE');
        $this->commission(1, 'CANCELED-CHARGE', receipt: '2026-12-01', chargeStatus: 0);

        $desc = array_column(app(ShippingCommissionsReport::class)()['rows'], 'booking');
        $asc = array_column(app(ShippingCommissionsReport::class)(['dir' => 'asc'])['rows'], 'booking');

        // a cobrança com status 0 não conta: CANCELED-CHARGE fica sem data e empata com NONE (id maior primeiro)
        $this->assertSame(['CANCELED-CHARGE', 'NONE', 'NEW', 'OLD'], $desc);
        $this->assertSame(['CANCELED-CHARGE', 'NONE'], array_slice($asc, -2));
        $this->assertSame(['OLD', 'NEW'], array_slice($asc, 0, 2));
    }

    public function test_filters_by_commissioned_booking_types_and_status(): void
    {
        $this->commission(1, 'ABC-1', status: null);
        $this->commission(1, 'XYZ-2', status: 1, type: 1, commissionType: 2);
        $this->commission(2, 'ABC-3', status: 0);

        $report = new ShippingCommissionsReport;
        $bookings = fn (array $filters) => array_column($report($filters)['rows'], 'booking');

        $this->assertEqualsCanonicalizing(['ABC-1', 'XYZ-2'], $bookings(['responsavel_id' => 1]));
        $this->assertEqualsCanonicalizing(['ABC-1', 'ABC-3'], $bookings(['booking' => 'abc']));
        $this->assertEqualsCanonicalizing(['ABC-1', 'ABC-3'], $bookings(['status' => '0']));
        $this->assertSame(['XYZ-2'], $bookings(['status' => '1']));
        $this->assertSame(['XYZ-2'], $bookings(['tipo' => '1']));
        $this->assertSame(['XYZ-2'], $bookings(['tipo_comissao' => '2']));
        $this->assertCount(3, $bookings(['tipo' => '', 'status' => '', 'tipo_comissao' => '']));
    }

    public function test_created_since_filter_cuts_older_commissions(): void
    {
        $old = $this->commission(1, 'OLD-ONE');
        DB::table('comissao_embarque')->where('comissao_embarque_id', $old)->update(['created_at' => '2025-12-31 23:59:59']);
        $this->commission(1, 'NEW-ONE');

        $report = new ShippingCommissionsReport;

        $this->assertSame(['NEW-ONE'], array_column($report(['criada_desde' => '2026-01-01'])['rows'], 'booking'));
        $this->assertCount(2, $report(['criada_desde' => null])['rows']);
    }

    public function test_adding_and_removing_a_commission_logs_on_the_booking_tracking(): void
    {
        $booking = DB::table('embarque')->insertGetId(['booking' => 'B-9']);

        $id = app(AddCommission::class)($booking, ['responsavel_id' => 1, 'tipo_comissao' => 2, 'tipo' => 5, 'moeda_id' => 2, 'valor_unitario' => 1234.5], 7);

        $row = DB::table('comissao_embarque')->where('comissao_embarque_id', $id)->first();
        $this->assertSame([2, 0, 0, 7], [(int) $row->tipo_comissao, (int) $row->tipo, (int) $row->status, (int) $row->created_by]);
        $log = DB::table('rastreamento')->where('ref_embarque', $booking)->first();
        $this->assertSame(['Comissão adicionada', 'i', 'A comissão no valor de US$ 1.234,50 para Ana foi adicionada.'], [$log->assunto, $log->evento, $log->historico]);

        app(RemoveCommission::class)($id, 7);

        $this->assertSame(0, DB::table('comissao_embarque')->count());
        $removed = DB::table('rastreamento')->where('ref_embarque', $booking)->orderByDesc('id')->first();
        $this->assertSame(['Comissão removida', 'd'], [$removed->assunto, $removed->evento]);
    }

    public function test_paying_stores_the_payment_and_marks_the_commission_as_paid(): void
    {
        $id = $this->commission(1, 'B-1', currency: 2);

        app(PayCommission::class)($id, ['valor_total' => 100, 'taxa' => '', 'data_pagamento' => '2026-03-01', 'observacao' => 'parcial'], 7);

        $payment = DB::table('comissao_embarque_pagamento')->where('comissao_embarque_id', $id)->first();
        $this->assertSame([100.0, null, 'parcial', 7], [(float) $payment->valor_total, $payment->taxa, $payment->observacao, (int) $payment->created_by]);
        $this->assertSame(1, (int) DB::table('comissao_embarque')->where('comissao_embarque_id', $id)->value('status'));
    }

    public function test_changes_the_type_only_to_known_values(): void
    {
        $id = $this->commission(1, 'B-1');
        $change = app(ChangeCommissionType::class);

        $change($id, 'tipo', '1');
        $change($id, 'tipo_comissao', 2);

        $row = DB::table('comissao_embarque')->where('comissao_embarque_id', $id)->first();
        $this->assertSame([1, 2], [(int) $row->tipo, (int) $row->tipo_comissao]);

        foreach ([['tipo', '2'], ['tipo_comissao', '3'], ['tipo', ''], ['status', '1'], ['tipo', '1abc']] as [$field, $value]) {
            try {
                $change($id, $field, $value);
                $this->fail("{$field}={$value} deveria ser recusado");
            } catch (DomainException $e) {
                $this->assertSame('Valor inválido.', $e->getMessage());
            }
        }
    }

    public function test_excel_is_streamed_with_the_rows(): void
    {
        $this->commission(1, 'B-1');

        $response = app(ExportShippingCommissionsReport::class)(app(ShippingCommissionsReport::class)());

        $this->assertStringContainsString('comissoes_embarque_', $response->headers->get('Content-Disposition'));
        ob_start();
        $response->sendContent();
        $this->assertStringStartsWith('PK', ob_get_clean());
    }

    private function commission(int $responsible, string $booking, int $currency = 1, ?int $status = 0, int $type = 0, int $commissionType = 0, int $containers = 0, ?string $receipt = null, int $chargeStatus = 1): int
    {
        $embarque = DB::table('embarque')->insertGetId(['booking' => $booking]);
        $bl = DB::table('bl')->insertGetId(['embarque_id' => $embarque, 'bl' => $booking]);

        for ($i = 0; $i < $containers; $i++) {
            DB::table('container')->insert(['bl_id' => $bl, 'numero' => "{$booking}-{$i}"]);
        }

        if ($receipt) {
            DB::table('fin_charges')->insert(['ref_type' => 1, 'ref_booking' => $embarque, 'status' => $chargeStatus, 'created_at' => $receipt, 'payment_date' => $receipt]);
        }

        return DB::table('comissao_embarque')->insertGetId([
            'comissao_embarque_responsavel_id' => $responsible, 'embarque_id' => $embarque, 'tipo_comissao' => $commissionType,
            'tipo' => $type, 'moeda_id' => $currency, 'valor_unitario' => 10, 'created_at' => '2026-01-01 00:00:00', 'created_by' => 1, 'status' => $status,
        ]);
    }

    private function payment(int $commission, float $value, ?float $rate): void
    {
        DB::table('comissao_embarque_pagamento')->insert([
            'comissao_embarque_id' => $commission, 'valor_total' => $value, 'taxa' => $rate, 'data_pagamento' => '2026-02-01', 'created_at' => '2026-02-01 00:00:00', 'created_by' => 1,
        ]);
    }
}
