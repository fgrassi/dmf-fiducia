<?php

namespace Tests\Feature\Actions\Contabilidade;

use App\Actions\Contabilidade\CostsToAccrueReport;
use App\Actions\Contabilidade\ExportAccountingReport;
use App\Actions\Contabilidade\IssuedInvoiceCostsReport;
use App\Actions\Contabilidade\ReceivableClientsReport;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class AccountingReportsTest extends TestCase
{
    use LegacySchema;

    private const PERIOD = ['data_ini' => '2026-10-01', 'data_fim' => '2026-10-31'];

    public function test_the_period_is_required_and_ordered(): void
    {
        foreach ([ReceivableClientsReport::class, CostsToAccrueReport::class, IssuedInvoiceCostsReport::class] as $report) {
            try {
                app($report)(['data_ini' => '2026-10-01']);
                $this->fail('A data final é obrigatória.');
            } catch (DomainException $e) {
                $this->assertSame('Informe a data inicial e a data final.', $e->getMessage());
            }

            try {
                app($report)(['data_ini' => '2026-11-01', 'data_fim' => '2026-10-01']);
                $this->fail('A data inicial não pode passar da final.');
            } catch (DomainException $e) {
                $this->assertSame('A data inicial não pode ser maior que a data final.', $e->getMessage());
            }
        }
    }

    public function test_receivable_clients_lists_the_balance_on_the_end_date_of_invoices_issued_in_the_period(): void
    {
        $this->booking(1, 'BKG-1', 'Cliente B');
        $this->booking(2, 'BKG-2', 'Cliente A');

        // Aberta com desconto: 1000 - 100. A NFS-e vem da chave de 50 dígitos (número 123) e a emissão do payload.
        $this->charge(1, 1, ['total_value' => 1000, 'discount' => 100, 'status' => 0], '2026-10-10', $this->key('123'));
        // Baixada dentro do período: sem saldo.
        $this->charge(2, 1, ['total_value' => 500, 'received_value' => 500, 'status' => 1, 'payment_date' => '2026-10-20'], '2026-10-11');
        // Baixada depois da data final: ainda devia na data final.
        $this->charge(3, 2, ['total_value' => 300, 'received_value' => 300, 'status' => 1, 'payment_date' => '2026-11-05'], '2026-10-12', null, 'NF-9');
        // Emitida fora do período e NFS-e cancelada: fora.
        $this->charge(4, 1, ['total_value' => 700, 'status' => 0], '2026-09-15');
        $this->charge(5, 1, ['total_value' => 800, 'status' => 0], '2026-10-15', null, null, 'cancelada');

        $report = app(ReceivableClientsReport::class)(self::PERIOD);

        $this->assertSame([
            ['Cliente A', 'BKG-2', 'NF-9', 300.0],
            ['Cliente B', 'BKG-1', '123', 900.0],
        ], array_map(fn (array $row) => [$row['cliente'], $row['reserva_numero'], $row['nf_numero'], $row['valor']], $report['rows']));
        $this->assertSame(['valor' => 1200.0], $report['totals']);
    }

    public function test_costs_to_accrue_compares_what_was_paid_in_the_period_with_the_booking_expenses(): void
    {
        DB::table('fin_expenses')->insert(['id' => 1, 'name' => 'THC', 'ref_unity' => 1]);

        // Reserva 1, sem capa: grade em R$ (10 × 2 BL = 20) + despesa em dólar vale o já pago (70).
        $this->booking(1, 'BKG-1', 'Cliente A', qtdBl: 2);
        $this->expense(1, 1, buying: 10, currency: 1);
        $this->expense(3, 1, buying: 5, currency: 2);
        $this->expense(2, 1, buying: 0, currency: 1);
        $this->armadorOrder(1, 1, '2026-10-05', [[1, 100], [2, 50]], disabled: [2]); // o item 2 está desativado
        $this->otherOrder(1, 1, '2026-10-06', [[1, 20]]);
        $this->armadorOrder(2, 1, '2026-09-01', [[3, 70]]); // fora do período: não é pago, mas vale na despesa em dólar
        DB::table('fin_charges')->insert(['id' => 1, 'ref_type' => 1, 'ref_booking' => 1, 'status' => 0, 'total_value' => 100, 'created_at' => '2026-10-01']);

        // Reserva 2: paga, mas todas as ICs estão quitadas até a data final.
        $this->booking(2, 'BKG-2', 'Cliente B');
        $this->expense(4, 2, buying: 10, currency: 1);
        $this->armadorOrder(3, 2, '2026-10-07', [[4, 90]]);
        DB::table('fin_charges')->insert(['id' => 2, 'ref_type' => 1, 'ref_booking' => 2, 'status' => 1, 'total_value' => 90, 'received_value' => 90, 'payment_date' => '2026-10-20', 'created_at' => '2026-10-01']);

        // Reserva 3, com capa: o apropriado é a capa (40), não a grade.
        $this->booking(3, 'BKG-3', 'Cliente A');
        $this->expense(5, 3, buying: 999, currency: 1);
        $this->armadorOrder(4, 3, '2026-10-08', [[5, 60]]);
        DB::table('capa_processo')->insert(['capa_process_id' => 1, 'embarque_id' => 3, 'moeda_id' => 1, 'created_at' => '2026-10-01']);
        DB::table('capa_processo_despesa_item')->insert(['capa_processo_id' => 1, 'fin_expenses_values_id' => 5, 'nome' => 'THC', 'valor' => 40]);
        DB::table('fin_charges')->insert(['id' => 3, 'ref_type' => 1, 'ref_booking' => 3, 'status' => 0, 'total_value' => 50, 'created_at' => '2026-10-01']);

        $report = app(CostsToAccrueReport::class)(self::PERIOD);

        $this->assertSame([
            ['Cliente A', 'BKG-1', 120.0, 90.0, 30.0],
            ['Cliente A', 'BKG-3', 60.0, 40.0, 20.0],
        ], array_map(fn (array $row) => [$row['cliente'], $row['reserva_numero'], $row['pago'], $row['apropriado'], $row['saldo']], $report['rows']));
        $this->assertSame(['pago' => 180.0, 'apropriado' => 130.0, 'saldo' => 50.0], $report['totals']);
    }

    public function test_issued_invoice_costs_sum_the_buying_cost_of_the_charge_expenses_per_currency(): void
    {
        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dolar', 'symbol' => 'US$'], ['id' => 3, 'name' => 'Euro', 'symbol' => 'EUR']]);
        DB::table('fin_expenses')->insert([['id' => 1, 'name' => 'Por BL', 'ref_unity' => 1], ['id' => 2, 'name' => 'Fixa', 'ref_unity' => 3]]);
        $this->booking(1, 'BKG-1', 'Cliente A', qtdBl: 3);
        $this->charge(1, 1, ['total_value' => 100], '2026-10-10', $this->key('55'));
        $this->charge(2, 1, ['total_value' => 100], '2026-09-10'); // emitida fora do período

        $this->expense(1, 1, buying: 10, currency: 2, expense: 1); // 10 × 3 BL = 30 USD
        $this->expense(2, 1, buying: 5, currency: 3, expense: 2); // 5 EUR
        $this->expense(3, 1, buying: 10, currency: 1, expense: 2, brMin: 50); // abaixo do mínimo: 50 R$
        $this->expense(4, 1, buying: 0, currency: 1, expense: 2); // sem custo de compra: fora
        foreach ([1, 2, 3, 4] as $expense) {
            DB::table('fin_charges_items')->insert(['ref_charge' => 1, 'ref_expense' => $expense, 'ref_currency' => 1, 'created_at' => '2026-10-10']);
            DB::table('fin_charges_items')->insert(['ref_charge' => 2, 'ref_expense' => $expense, 'ref_currency' => 1, 'created_at' => '2026-09-10']);
        }

        $report = app(IssuedInvoiceCostsReport::class)(self::PERIOD);

        $this->assertCount(1, $report['rows']);
        $this->assertSame(['2026-10-10', '55', 'BKG-1', 30.0, 5.0, 50.0], [
            $report['rows'][0]['nf_data'], $report['rows'][0]['nf_numero'], $report['rows'][0]['reserva_numero'], $report['rows'][0]['usd'], $report['rows'][0]['eur'], $report['rows'][0]['brl'],
        ]);
        $this->assertSame(['usd' => 30.0, 'eur' => 5.0, 'brl' => 50.0], $report['totals']);
    }

    public function test_the_excel_never_turns_text_into_a_formula(): void
    {
        $book = app(ExportAccountingReport::class)->build('Teste', ['cliente' => ['Cliente', 'text'], 'valor' => ['R$', 'money']], [['cliente' => '=HYPERLINK("http://x","clique")', 'valor' => 10.5]], ['valor' => 10.5]);
        $cell = $book->getActiveSheet()->getCell('A2');

        $this->assertSame('s', $cell->getDataType());
        $this->assertSame('=HYPERLINK("http://x","clique")', $cell->getValue());
        $this->assertSame(10.5, $book->getActiveSheet()->getCell('B3')->getValue());
    }

    private function booking(int $id, string $booking, string $client, int $qtdBl = 1): void
    {
        DB::table('pessoa')->insertOrIgnore(['pessoa_id' => $id, 'nome' => $client]);
        DB::table('embarque')->insert(['embarque_id' => $id, 'booking' => $booking, 'cliente_id' => $id, 'qtd_bl' => $qtdBl, 'data_topo' => '2026-09-20']);
    }

    /** NFS-e autorizada (ou do status dado) da cobrança, com a emissão no payload. */
    private function charge(int $id, int $booking, array $charge, string $issued, ?string $key = null, ?string $number = null, string $status = 'autorizada'): void
    {
        DB::table('fin_charges')->insert(['id' => $id, 'ref_type' => 1, 'ref_booking' => $booking, 'created_at' => '2026-10-01', 'num_nota_fiscal' => $number, ...$charge]);
        DB::table('fin_charges_nfse')->insert([
            'ref_charge' => $id, 'id_externo' => "ext-{$id}", 'status' => $status, 'chave_acesso' => $key,
            'payload_json' => json_encode(['data_emissao' => $issued]), 'created_at' => $issued.' 10:00:00',
        ]);
    }

    private function key(string $number): string
    {
        return str_repeat('1', 23).str_pad($number, 13, '0', STR_PAD_LEFT).str_repeat('1', 14);
    }

    private function expense(int $id, int $booking, float $buying, int $currency, int $expense = 1, ?float $brMin = null): void
    {
        DB::table('fin_expenses_values')->insert(['id' => $id, 'ref_expense' => $expense, 'ref_embarque' => $booking, 'buying_rate' => $buying, 'ref_currency_br' => $currency, 'br_min' => $brMin]);
    }

    /** @param  list<array{0: int, 1: float}>  $items  [despesa da reserva, valor]  @param  list<int>  $disabled */
    private function armadorOrder(int $id, int $booking, string $paidOn, array $items, array $disabled = []): void
    {
        DB::table('fin_payment_orders')->insert(['id' => $id, 'ref_armador' => 1, 'ref_type' => 1, 'ref_embarque' => $booking, 'status' => 1, 'payment_date' => $paidOn, 'created_at' => $paidOn]);

        foreach ($items as [$expense, $value]) {
            DB::table('fin_payment_order_items')->insert(['ref_payment_order' => $id, 'ref_expense' => $expense, 'ref_currency' => 1, 'item_value' => $value, 'ref_bl' => 0, 'created_at' => $paidOn]);
        }

        foreach ($disabled as $expense) {
            DB::table('fin_payment_order_items_disabled')->insert(['ref_fin_payment_order' => $id, 'ref_expense' => $expense, 'created_by' => 1, 'created_at' => $paidOn, 'status' => 0]);
        }
    }

    /** @param  list<array{0: int, 1: float}>  $items */
    private function otherOrder(int $id, int $booking, string $paidOn, array $items): void
    {
        DB::table('ordem_pagamento')->insert(['ordem_pagamento_id' => $id, 'ref_embarque' => $booking, 'ref_type' => 1, 'status' => 1, 'payment_date' => $paidOn, 'created_at' => $paidOn]);

        foreach ($items as [$expense, $value]) {
            DB::table('ordem_pagamento_item')->insert(['ordem_pagamento_id' => $id, 'ref_expense' => $expense, 'ref_currency' => 1, 'item_value' => $value, 'created_at' => $paidOn]);
        }
    }
}
