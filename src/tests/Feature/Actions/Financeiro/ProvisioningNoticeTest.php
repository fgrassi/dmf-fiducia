<?php

namespace Tests\Feature\Actions\Financeiro;

use App\Actions\Financeiro\ProvisioningNotice;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ProvisioningNoticeTest extends TestCase
{
    use LegacySchema;

    /** Hoje é a semana ISO 46; o aviso olha a 48, de domingo 22/11 a sábado 28/11/2026. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09');
        DB::table('pessoa')->insert([['pessoa_id' => 1, 'nome' => 'Cliente Sul'], ['pessoa_id' => 2, 'nome' => 'Cliente Norte']]);
        DB::table('armador')->insert(['armador_id' => 1, 'nome' => 'Maersk']);
        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$'], ['id' => 3, 'name' => 'Euro', 'symbol' => '€']]);
        DB::table('fin_expenses_unities')->insert([['id' => 1, 'name' => 'Container', 'acronym' => 'CNT'], ['id' => 2, 'name' => 'BL', 'acronym' => 'BL']]);
        DB::table('fin_expenses')->insert([['id' => 1, 'name' => 'THC', 'ref_unity' => 1], ['id' => 2, 'name' => 'Doc', 'ref_unity' => 2]]);
        DB::table('fin_clients_boleto')->insert(['ref_client' => 2, 'prazo_imp' => 10, 'prazo_exp' => 0]);
        $this->rate(3, 5.0, '2026-11-05');
        $this->rate(6, 6.0, '2026-11-05');
    }

    public function test_sends_exports_by_etd_and_imports_by_eta_with_totals_per_currency(): void
    {
        $this->booking(10, 'EXP10', 'e', 'saida_prevista', '2026-11-25', client: 1);
        $this->booking(11, 'EXP-FORA', 'e', 'saida_prevista', '2026-11-29', client: 1);
        $this->booking(12, 'IMP12', 'i', 'chegada_prevista', '2026-11-24', client: 2);
        DB::table('bl')->insert(['bl_id' => 1, 'embarque_id' => 10, 'mbl_number' => 'MBL1']);
        DB::table('container')->insert([['bl_id' => 1], ['bl_id' => 1]]);
        DB::table('mercadoria')->insert(['embarque_id' => 12, 'quantidade' => 3]);
        $this->expense(10, currency: 2, buying: 100, selling: 150, expense: 1);
        $this->expense(10, currency: 1, buying: 10, selling: 20, expense: 2);
        $this->expense(10, currency: 2, buying: 999, selling: 999, expense: 1, billing: 'c');
        $this->expense(10, currency: 2, buying: 999, selling: 999, expense: 1, dueTo: 4);
        $this->expense(11, currency: 2, buying: 999, selling: 999, expense: 1);
        $this->expense(12, currency: 3, buying: 10, selling: 20, expense: 1);
        $this->expense(12, currency: 3, buying: 999, selling: 999, expense: 1, billing: 'p');

        $sent = app(ProvisioningNotice::class)();

        $this->assertSame(2, $sent);
        $this->assertSame([
            'Provisionamento EXPORTAÇÕES - Semana 46 de 2026',
            'Provisionamento IMPORTAÇÕES - Semana 46 de 2026',
        ], $this->sentSubjects()->all());
        $this->assertSame(['marcia@dmflog.com.br', 'it@dmflog.com.br'], $this->sentRecipients());

        $html = $this->sentHtml();
        $this->assertStringContainsString('<h2>Semana: 22/11/2026 a 28/11/2026</h2>', $html);
        $this->assertStringContainsString("<th colspan='10'>Armador: Maersk</th>", $html);
        $this->assertStringContainsString(
            '<td>Cliente Sul</td><td>EXP10</td><td>25/11/2026</td><td>25/11/2026</td>'
            ."<td align='right'>300,00</td><td align='right'>1.500,00</td><td align='right'>200,00</td><td align='right'>1.000,00</td>"
            ."<td align='right'>20,00</td><td align='right'>10,00</td>",
            $html,
        );
        $this->assertStringContainsString(
            '<td>Cliente Norte</td><td>IMP12</td><td>24/11/2026</td><td>03/12/2026</td>'
            ."<td align='right'>60,00</td><td align='right'>360,00</td><td align='right'>30,00</td><td align='right'>180,00</td>",
            $html,
        );
        $this->assertStringNotContainsString('EXP-FORA', $html);
    }

    public function test_converts_with_the_latest_rate_when_none_was_posted_since_last_week(): void
    {
        DB::table('fin_currencies_rates_values')->where('ref_currency_rate', 3)->update(['created_at' => '2026-10-01', 'rate_date' => '2026-10-01']);
        $this->booking(10, 'EXP10', 'e', 'saida_prevista', '2026-11-25', client: 1);
        $this->expense(10, currency: 2, buying: 10, selling: 20, expense: 1);
        DB::table('mercadoria')->insert(['embarque_id' => 10, 'quantidade' => 1]);

        app(ProvisioningNotice::class)();

        $this->assertStringContainsString("<td align='right'>20,00</td><td align='right'>100,00</td><td align='right'>10,00</td><td align='right'>50,00</td>", $this->sentHtml());
    }

    public function test_still_sends_both_emails_when_nothing_is_forecast(): void
    {
        $sent = app(ProvisioningNotice::class)();

        $this->assertSame(0, $sent);
        $this->assertCount(2, $this->sentSubjects());
        $this->assertStringNotContainsString('Armador', $this->sentHtml());
    }

    private function rate(int $rateId, float $rate, string $date): void
    {
        DB::table('fin_currencies_rates_values')->insert(['rate' => $rate, 'ref_currency_rate' => $rateId, 'created_by' => 1, 'created_at' => $date, 'rate_date' => $date]);
    }

    private function booking(int $id, string $booking, string $type, string $dateColumn, string $date, int $client): void
    {
        DB::table('embarque')->insert([
            'embarque_id' => $id, 'booking' => $booking, 'impor_expor' => $type, 'tipo_embarque' => 'm', 'finalizado' => 'a',
            'cliente_id' => $client, 'armador_id' => 1, 'saida_prevista' => $date, 'chegada_prevista' => $date, $dateColumn => $date,
        ]);
    }

    private function expense(int $bookingId, int $currency, float $buying, float $selling, int $expense, string $billing = 'a', int $dueTo = 3): void
    {
        DB::table('fin_expenses_values')->insert([
            'ref_embarque' => $bookingId, 'ref_expense' => $expense, 'ref_currency_br' => $currency, 'buying_rate' => $buying,
            'selling_rate' => $selling, 'billing' => $billing, 'ref_due_to' => $dueTo,
        ]);
    }
}
