<?php

namespace Tests\Feature\Actions\Booking;

use App\Actions\Booking\ExpenseReviewNotice;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ExpenseReviewNoticeTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09');
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'Cliente Sul', 'cnpj' => '11.222.333/0001-44']);
        DB::table('cmn_currencies')->insert([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$'], ['id' => 3, 'name' => 'Euro', 'symbol' => '€']]);
        DB::table('fin_currencies_rates')->insert([['id' => 3, 'name' => 'Taxa Diária', 'ref_currency' => 2], ['id' => 6, 'name' => 'Euro', 'ref_currency' => 3]]);
        DB::table('fin_default_rates')->insert(['ref_currency_rate' => 3, 'default_tax' => 0]);
        DB::table('fin_currencies_rates_values')->insert(['rate' => 5.0, 'ref_currency_rate' => 3, 'created_by' => 1, 'rate_date' => '2026-11-09']);
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'Container', 'acronym' => 'CNT']);
        DB::table('fin_expenses')->insert(['id' => 1, 'name' => 'THC', 'ref_unity' => 1]);
        DB::table('fin_expenses_values_due_to')->insert(['id' => 1, 'name' => 'DMF']);
        DB::table('embarque')->insert([
            ['embarque_id' => 10, 'booking' => 'IMP10', 'impor_expor' => 'i', 'cliente_id' => 1],
            ['embarque_id' => 20, 'booking' => 'EXP20', 'impor_expor' => 'e', 'cliente_id' => 1],
        ]);
    }

    public function test_import_email_lists_sale_below_cost_and_expenses_without_rate(): void
    {
        $this->expense(10, brCurrency: 2, srCurrency: 2, buying: 100, selling: 80);
        $this->expense(10, brCurrency: 2, srCurrency: 1, buying: 10, selling: 40);
        $this->expense(10, brCurrency: 2, srCurrency: 1, buying: 10, selling: 60);
        $this->expense(10, brCurrency: 3, srCurrency: 1, buying: 10, selling: 100);
        $this->expense(10, brCurrency: 2, srCurrency: 2, buying: 100, selling: 1, allIn: '1');
        $this->expense(10, brCurrency: 2, srCurrency: 2, buying: 100, selling: 2, createdAt: '2026-11-08', updatedAt: '2026-11-08');
        $this->expense(10, brCurrency: 2, srCurrency: 2, buying: 100, selling: 3, createdAt: '2026-11-01', updatedAt: '2026-11-09');

        $sent = app(ExpenseReviewNotice::class)();

        $this->assertSame(4, $sent);
        $this->assertSame(['[DMF] Revisão de despesas - 09/11/2026 - IMPO'], $this->sentSubjects()->all());
        $this->assertSame(['cobranca@dmflog.com.br', 'imp2@dmflog.com.br', 'imp3@dmflog.com.br', 'imp4@dmflog.com.br', 'imp5@dmflog.com.br'], $this->sentRecipients());

        $html = $this->sentHtml();
        [$below, $withoutRate] = explode('moeda de compra e venda diferentes', $html);
        $this->assertStringContainsString('<td>IMP10</td><td>Cliente Sul (11.222.333/0001-44)</td><td>THC / CNT</td><td>US$ 100,00</td><td>US$ 80,00</td><td>PA</td><td>DMF</td>', $below);
        $this->assertStringContainsString('<td>US$ 10,00</td><td>R$ 40,00</td>', $below);
        $this->assertStringContainsString('<td>US$ 100,00</td><td>US$ 3,00</td>', $below);
        $this->assertStringNotContainsString('R$ 60,00', $html);
        $this->assertStringNotContainsString('US$ 1,00', $html);
        $this->assertStringNotContainsString('US$ 2,00', $html);
        $this->assertStringContainsString('<td>€ 10,00</td><td>R$ 100,00</td>', $withoutRate);
    }

    public function test_export_email_goes_to_the_export_team_without_the_import_text(): void
    {
        $this->expense(20, brCurrency: 2, srCurrency: 2, buying: 100, selling: 80);

        $sent = app(ExpenseReviewNotice::class)();

        $this->assertSame(1, $sent);
        $this->assertSame(['[DMF] Revisão de despesas - 09/11/2026 - EXPO'], $this->sentSubjects()->all());
        $this->assertSame(['cobranca@dmflog.com.br', 'douglas@dmflog.com.br'], $this->sentRecipients());
        $this->assertStringContainsString('<td>EXP20</td>', $this->sentHtml());
        $this->assertStringNotContainsString('não há cotação', $this->sentHtml());
    }

    public function test_sends_nothing_when_no_expense_needs_review(): void
    {
        $this->expense(10, brCurrency: 2, srCurrency: 1, buying: 10, selling: 60);

        $this->assertSame(0, app(ExpenseReviewNotice::class)());
        $this->assertSame([], $this->sentSubjects()->all());
    }

    private function expense(int $bookingId, int $brCurrency, int $srCurrency, float $buying, float $selling, ?string $allIn = '0', string $createdAt = '2026-11-09', string $updatedAt = '2026-11-09'): void
    {
        DB::table('fin_expenses_values')->insert([
            'ref_embarque' => $bookingId, 'ref_expense' => 1, 'ref_currency_br' => $brCurrency, 'ref_currency_sr' => $srCurrency,
            'buying_rate' => $buying, 'selling_rate' => $selling, 'billing' => 'a', 'ref_due_to' => 1, 'all_in' => $allIn,
            'created_at' => $createdAt, 'updated_at' => $updatedAt,
        ]);
    }
}
