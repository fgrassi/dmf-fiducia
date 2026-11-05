<?php

namespace Tests\Feature\Actions\Fiducia;

use App\Actions\Fiducia\ClientBookingsReport;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ClientBookingsReportTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$']]);
        DB::table('fin_currencies_rates')->insert(['id' => 1, 'name' => 'Taxa Diária US$', 'ref_currency' => 2]);
        DB::table('fin_currencies_rates_values')->insert([
            ['rate' => 4.0, 'ref_currency_rate' => 1, 'created_by' => 1, 'rate_date' => '2026-09-01'],
            ['rate' => 5.0, 'ref_currency_rate' => 1, 'created_by' => 1, 'rate_date' => '2026-10-01'],
        ]);
        DB::table('fin_expenses_values_due_to')->insert(['id' => 2, 'name' => 'Armador']);
        DB::table('fin_expenses')->insert(['id' => 1, 'name' => 'THC', 'ref_unity' => 1]);
        DB::table('pessoa')->insert([['pessoa_id' => 7, 'nome' => 'Cliente Sete'], ['pessoa_id' => 8, 'nome' => 'Cliente Oito'], ['pessoa_id' => 9, 'nome' => 'Sem Desconto']]);
        DB::table('fiducia_desconto')->insert([['pessoa_id' => 7, 'moeda_id' => 1, 'valor' => 10, 'tipo_desconto' => 'c'], ['pessoa_id' => 8, 'moeda_id' => 1, 'valor' => 10, 'tipo_desconto' => 'p']]);
    }

    public function test_counts_confirmed_by_actual_date_and_unconfirmed_by_planned_date_with_averages(): void
    {
        $confirmed = $this->booking(7, 'B-1', actual: '2026-10-05', planned: '2026-10-01', containers: 2);
        $this->booking(7, 'B-2', actual: null, planned: '2026-11-10', containers: 1);
        $this->booking(7, 'B-3', actual: '2026-12-01', planned: '2026-10-01', containers: 5);
        $this->booking(7, 'B-4', actual: '2026-10-06', planned: '2026-10-01', containers: 5, status: 'c');
        $this->booking(9, 'B-5', actual: '2026-10-07', planned: '2026-10-01', containers: 5);
        $cover = DB::table('capa_processo')->insertGetId(['embarque_id' => $confirmed, 'moeda_id' => 1, 'valor_cotacao' => 1, 'receitas' => 100, 'comissoes' => 20]);
        DB::table('capa_processo_receita_item')->insert(['capa_processo_id' => $cover, 'fin_expenses_values_id' => 1, 'nome' => 'THC', 'valor' => 500]);
        DB::table('capa_processo_despesa_item')->insert(['capa_processo_id' => $cover, 'fin_expenses_values_id' => 2, 'nome' => 'THC', 'valor' => 200]);

        $report = app(ClientBookingsReport::class)($this->filters(['mes_fim' => 11]));

        $this->assertSame(2, $report['qtd_meses']);
        [$eight, $seven] = $report['rows'];
        $this->assertSame(['Cliente Oito', 'Cliente Sete'], [$eight['cliente_nome'], $seven['cliente_nome']]);
        $this->assertSame([1, 2, 1, 1], [$seven['emb_confirmados'], $seven['eq_confirmados'], $seven['emb_nao_confirmados'], $seven['eq_nao_confirmados']]);
        $this->assertSame(0.5, $seven['media_emb_confirmados']);
        $this->assertSame(420.0, $seven['lucratividade']);
        $this->assertFalse($seven['lucratividade_provisoria']);
        $this->assertFalse($eight['tem_lucro']);
        $this->assertSame(1, $report['totais']['emb_confirmados']);
        $this->assertSame(5.0, $report['totais']['cotacao_dolar']);
    }

    public function test_confirmed_booking_without_cover_is_estimated_from_pending_expenses_at_the_daily_rate(): void
    {
        $booking = $this->booking(7, 'B-1', actual: '2026-10-05', planned: null, containers: 1, qtdBl: 2);
        DB::table('fin_expenses_values')->insert(['ref_expense' => 1, 'ref_embarque' => $booking, 'buying_rate' => 10, 'selling_rate' => 30, 'ref_currency_br' => 2, 'ref_currency_sr' => 1, 'ref_due_to' => 2]);

        $row = collect(app(ClientBookingsReport::class)($this->filters())['rows'])->firstWhere('cliente_nome', 'Cliente Sete');

        $this->assertTrue($row['lucratividade_provisoria']);
        $this->assertSame([60.0, 100.0, -40.0], [$row['receitas_estimadas'], $row['despesas_estimadas'], $row['lucratividade']]);
    }

    public function test_unconfirmed_booking_uses_the_expense_summary_balance_in_reais(): void
    {
        $booking = $this->booking(7, 'B-1', actual: null, planned: '2026-10-05', containers: 1);
        DB::table('fin_expenses_values')->insert(['ref_expense' => 1, 'ref_embarque' => $booking, 'buying_rate' => 10, 'selling_rate' => 30, 'ref_currency_br' => 2, 'ref_currency_sr' => 2, 'ref_due_to' => 2]);

        $row = collect(app(ClientBookingsReport::class)($this->filters(['listar_embarques' => true]))['rows'])->firstWhere('cliente_nome', 'Cliente Sete');

        $this->assertSame(100.0, $row['lucratividade']);
        $this->assertTrue($row['lucratividade_provisoria']);
        $this->assertSame(['B-1', false, 100.0], [$row['detalhes'][0]['booking'], $row['detalhes'][0]['confirmado'], $row['detalhes'][0]['lucratividade']]);
    }

    public function test_groups_by_month_and_keeps_only_clients_with_bookings_in_each_month(): void
    {
        $this->booking(7, 'B-1', actual: '2026-10-05', planned: null, containers: 1);
        $this->booking(8, 'B-2', actual: '2026-11-05', planned: null, containers: 3);

        $report = app(ClientBookingsReport::class)($this->filters(['mes_fim' => 11, 'agrupar_por_mes' => true]));

        $this->assertSame(['OUTUBRO/2026', 'NOVEMBRO/2026'], array_column($report['grupos'], 'label'));
        $this->assertSame(['Cliente Sete'], array_column($report['grupos'][0]['rows'], 'cliente_nome'));
        $this->assertSame(3, $report['grupos'][1]['totais']['eq_confirmados']);
        $this->assertSame(4, $report['totais']['eq_confirmados']);
        $this->assertSame(1.0, $report['totais']['media_emb_confirmados']);
    }

    public function test_only_with_cover_hides_clients_without_bookings_and_type_filters_bookings(): void
    {
        $this->booking(7, 'B-1', actual: '2026-10-05', planned: null, containers: 1, type: 'i');
        $this->booking(8, 'B-2', actual: '2026-10-05', planned: null, containers: 1, type: 'e');

        $report = app(ClientBookingsReport::class)($this->filters(['impor_expor' => 'e']));
        $this->assertSame([0, 1], [$report['rows'][1]['emb_confirmados'], $report['rows'][0]['emb_confirmados']]);

        $report = app(ClientBookingsReport::class)($this->filters(['apenas_com_capa' => true]));
        $this->assertSame([], $report['rows']);
    }

    public function test_flags_every_unconfirmed_booking_not_only_the_first(): void
    {
        $this->booking(7, 'B-1', actual: null, planned: '2026-10-05', containers: 1);
        $this->booking(7, 'B-2', actual: null, planned: '2026-10-06', containers: 2);
        $this->booking(7, 'B-3', actual: null, planned: '2026-10-07', containers: 3);

        $row = collect(app(ClientBookingsReport::class)($this->filters(['listar_embarques' => true]))['rows'])->firstWhere('cliente_nome', 'Cliente Sete');

        $this->assertSame([3, 6], [$row['emb_nao_confirmados'], $row['eq_nao_confirmados']]);
        $this->assertSame(['B-1', 'B-2', 'B-3'], array_column($row['detalhes'], 'booking'));
    }

    public function test_rejects_invalid_period(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('O período início deve ser anterior ou igual ao período fim.');

        app(ClientBookingsReport::class)($this->filters(['mes_ini' => 12, 'mes_fim' => 1]));
    }

    /** @param  array<string, mixed>  $override */
    private function filters(array $override = []): array
    {
        return $override + ['mes_ini' => 10, 'ano_ini' => 2026, 'mes_fim' => 10, 'ano_fim' => 2026];
    }

    private function booking(int $client, string $name, ?string $actual, ?string $planned, int $containers, string $status = 'a', string $type = 'e', ?int $qtdBl = null): int
    {
        $id = DB::table('embarque')->insertGetId([
            'booking' => $name, 'cliente_id' => $client, 'saida_efetiva' => $actual, 'saida_prevista' => $planned,
            'finalizado' => $status, 'impor_expor' => $type, 'qtd_bl' => $qtdBl,
        ]);
        $bl = DB::table('bl')->insertGetId(['embarque_id' => $id, 'bl' => $name]);

        for ($i = 0; $i < $containers; $i++) {
            DB::table('container')->insert(['bl_id' => $bl, 'numero' => "{$name}-{$i}", 'volume' => 1]);
        }

        return $id;
    }
}
