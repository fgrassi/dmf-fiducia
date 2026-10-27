<?php

namespace Tests\Feature\Actions\Expenses;

use App\Actions\Expenses\ExpenseDueNotice;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ExpenseDueNoticeTest extends TestCase
{
    use LegacySchema;

    public function test_expense_due_on_day_beyond_month_end_falls_on_last_day(): void
    {
        $this->travelTo('2027-02-25');
        DB::table('fornecedor')->insert(['fornecedor_id' => 1, 'nome' => 'Fornecedor']);
        DB::table('categoria_despesa')->insert(['categoria_despesa_id' => 1, 'nome' => 'Aluguel']);
        DB::table('despesa_vencimento')->insert([
            ['fornecedor_id' => 1, 'categoria_despesa_id' => 1, 'dia_vencimento' => 31, 'descricao' => 'dia 31'],
            ['fornecedor_id' => 1, 'categoria_despesa_id' => 1, 'dia_vencimento' => 15, 'descricao' => 'dia 15'],
        ]);

        $this->assertSame(1, app(ExpenseDueNotice::class)());
        $this->assertSame(['[Aviso] Vencimento de despesas em 3 dias (28/02/2027)'], $this->sentSubjects()->all());
    }
}
