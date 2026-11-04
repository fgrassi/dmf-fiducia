<?php

namespace Tests\Feature\Actions\Expenses;

use App\Actions\Expenses\CompanyExpenseReportPdf;
use App\Actions\Expenses\ConfirmExpensePayment;
use App\Actions\Expenses\DeleteExpense;
use App\Actions\Expenses\DuplicateExpense;
use App\Actions\Expenses\ExportCompanyExpenses;
use App\Actions\Expenses\ManageExpenseFiles;
use App\Actions\Expenses\MergeExpenseCategories;
use App\Actions\Expenses\MergeSuppliers;
use App\Actions\Expenses\SaveExpense;
use App\Models\ArquivoDespesa;
use App\Models\CategoriaDespesa;
use App\Models\Despesa;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CompanyExpenseActionsTest extends TestCase
{
    use LegacySchema;

    public function test_new_expense_is_open_and_signed_by_the_user(): void
    {
        $expense = app(SaveExpense::class)($this->data(), 7);

        $this->assertSame(Despesa::STATUS_OPEN, $expense->status);
        $this->assertSame(7, $expense->created_by);
        $this->assertSame(7, $expense->usuario_id);
        $this->assertNotNull($expense->created_at);
    }

    public function test_editing_keeps_a_closed_expense_closed_and_does_not_touch_the_author(): void
    {
        $expense = $this->expense(['status' => Despesa::STATUS_CLOSED, 'created_by' => 3]);

        app(SaveExpense::class)(['valor' => 99.5], 7, $expense);

        $expense->refresh();
        $this->assertSame(99.5, (float) $expense->valor);
        $this->assertSame(Despesa::STATUS_CLOSED, $expense->status);
        $this->assertSame(3, $expense->created_by);
        $this->assertNull($expense->updated_by);
    }

    public function test_payment_closes_the_expense_and_keeps_the_bank_when_none_is_sent(): void
    {
        $expense = $this->expense(['banco_id' => 4]);

        app(ConfirmExpensePayment::class)($expense, ['data_efetiva' => '2026-11-03', 'tipo_pagamento' => 6, 'banco_id' => null, 'valor' => 120, 'juros' => 2.5], 7);

        $expense->refresh();
        $this->assertSame(Despesa::STATUS_CLOSED, $expense->status);
        $this->assertSame(6, $expense->tipo_pagamento);
        $this->assertSame(4, $expense->banco_id);
        $this->assertSame(7, $expense->updated_by);
        $this->assertSame('2026-11-03', $expense->data_efetiva->toDateString());
        $this->assertSame(2.5, (float) $expense->juros);
    }

    public function test_duplicate_copies_the_basics_and_clears_the_payment(): void
    {
        $paid = $this->expense(['status' => 1, 'data_efetiva' => '2026-10-01', 'nota_fiscal' => 'NF1', 'moeda_id' => 2, 'banco_id' => 4, 'tipo_pagamento' => 3, 'observacao' => 'x', 'juros' => 1, 'empresa' => 'f', 'tipo_despesa' => 2]);

        $copy = app(DuplicateExpense::class)($paid, 9);

        $this->assertNotSame($paid->getKey(), $copy->getKey());
        $this->assertSame(Despesa::STATUS_OPEN, $copy->status);
        $this->assertSame(9, $copy->created_by);
        $this->assertSame('f', $copy->empresa);
        $this->assertSame(2, $copy->tipo_despesa);
        $this->assertSame((float) $paid->valor, (float) $copy->valor);
        $this->assertSame(0, $copy->tipo_pagamento);
        foreach (['data_efetiva', 'nota_fiscal', 'moeda_id', 'banco_id', 'observacao', 'juros'] as $cleared) {
            $this->assertNull($copy->$cleared, $cleared);
        }
    }

    public function test_delete_removes_the_expense_and_its_file_links_only(): void
    {
        $expense = $this->expense();
        $other = $this->expense();
        $expense->files()->create(['arquivo' => 'a.pdf']);
        $other->files()->create(['arquivo' => 'b.pdf']);

        app(DeleteExpense::class)($expense);

        $this->assertNull($expense->fresh());
        $this->assertSame(['b.pdf'], ArquivoDespesa::pluck('arquivo')->all());
    }

    public function test_attach_stores_with_a_clean_dated_name_and_remove_only_unlinks(): void
    {
        Storage::fake('tracking_uploads');
        $this->travelTo('2026-11-04 08:05:00');
        $expense = $this->expense();

        $names = app(ManageExpenseFiles::class)->attach($expense, [UploadedFile::fake()->create('Nota fiscal ç 1.pdf', 5)]);

        $this->assertSame(['041126805_Nota_fiscal__1.pdf'], $names);
        Storage::disk('tracking_uploads')->assertExists('despesa/041126805_Nota_fiscal__1.pdf');

        app(ManageExpenseFiles::class)->remove($expense, [$expense->files()->value('arquivo_despesa_id')]);

        $this->assertSame(0, $expense->files()->count());
        Storage::disk('tracking_uploads')->assertExists('despesa/041126805_Nota_fiscal__1.pdf');
    }

    public function test_attach_rejects_scripts_and_double_extensions_before_storing_anything(): void
    {
        Storage::fake('tracking_uploads');
        $expense = $this->expense();

        foreach (['shell.php', 'x.phtml', 'a.php.txt', 'sem-extensao'] as $bad) {
            try {
                app(ManageExpenseFiles::class)->attach($expense, [UploadedFile::fake()->create('ok.pdf', 1), UploadedFile::fake()->create($bad, 1)]);
                $this->fail("{$bad} foi aceito");
            } catch (DomainException) {
            }
        }

        $this->assertSame([], Storage::disk('tracking_uploads')->allFiles());
        $this->assertSame(0, $expense->files()->count());
    }

    public function test_attach_neutralizes_dots_inside_the_base_name(): void
    {
        Storage::fake('tracking_uploads');

        $names = app(ManageExpenseFiles::class)->attach($this->expense(), [UploadedFile::fake()->create('a.php.PDF', 1)]);

        $this->assertMatchesRegularExpression('/^\d{9,10}_a_php\.pdf$/', $names[0]);
    }

    public function test_merge_moves_expenses_and_due_dates_and_drops_the_duplicated_due_date(): void
    {
        [$target, $a, $b] = [$this->category('Alvo'), $this->category('A'), $this->category('B')];
        $this->expense(['categoria_despesa_id' => $a]);
        $this->expense(['categoria_despesa_id' => $b]);
        DB::table('despesa_vencimento')->insert([
            ['fornecedor_id' => 1, 'categoria_despesa_id' => $target, 'dia_vencimento' => 5],
            ['fornecedor_id' => 1, 'categoria_despesa_id' => $a, 'dia_vencimento' => 6],
            ['fornecedor_id' => 2, 'categoria_despesa_id' => $b, 'dia_vencimento' => 7],
        ]);

        app(MergeExpenseCategories::class)([$a, $b, $target], $target);

        $this->assertSame([$target], CategoriaDespesa::pluck('categoria_despesa_id')->all());
        $this->assertSame(2, Despesa::where('categoria_despesa_id', $target)->count());
        $this->assertEqualsCanonicalizing([5, 7], DB::table('despesa_vencimento')->pluck('dia_vencimento')->all());
        $this->assertSame(0, DB::table('despesa_vencimento')->where('categoria_despesa_id', '!=', $target)->count());
    }

    public function test_merging_suppliers_moves_expenses_and_due_dates_and_drops_the_duplicated_due_date(): void
    {
        DB::table('fornecedor')->insert([['fornecedor_id' => 1, 'nome' => 'Alvo'], ['fornecedor_id' => 2, 'nome' => 'A'], ['fornecedor_id' => 3, 'nome' => 'B']]);
        $this->expense(['fornecedor_id' => 2]);
        $this->expense(['fornecedor_id' => 3]);
        DB::table('despesa_vencimento')->insert([
            ['fornecedor_id' => 1, 'categoria_despesa_id' => 10, 'dia_vencimento' => 5],
            ['fornecedor_id' => 2, 'categoria_despesa_id' => 10, 'dia_vencimento' => 6],
            ['fornecedor_id' => 3, 'categoria_despesa_id' => 11, 'dia_vencimento' => 7],
        ]);

        app(MergeSuppliers::class)([2, 3, 1], 1);

        $this->assertSame([1], DB::table('fornecedor')->pluck('fornecedor_id')->all());
        $this->assertSame(2, Despesa::where('fornecedor_id', 1)->count());
        $this->assertEqualsCanonicalizing([5, 7], DB::table('despesa_vencimento')->pluck('dia_vencimento')->all());
        $this->assertSame(0, DB::table('despesa_vencimento')->where('fornecedor_id', '!=', 1)->count());
    }

    public function test_merging_suppliers_rejects_unknown_target_and_empty_sources(): void
    {
        DB::table('fornecedor')->insert(['fornecedor_id' => 1, 'nome' => 'Único']);

        try {
            app(MergeSuppliers::class)([1], 1);
            $this->fail('aceitou origem vazia');
        } catch (DomainException $e) {
            $this->assertSame('Selecione ao menos um fornecedor de origem diferente do destino.', $e->getMessage());
        }

        $this->expectExceptionMessage('Fornecedor destino não encontrado.');
        app(MergeSuppliers::class)([1], 999);
    }

    public function test_merge_rejects_missing_target_and_empty_sources(): void
    {
        $category = $this->category('Única');

        $this->expectException(DomainException::class);
        app(MergeExpenseCategories::class)([$category], $category);
    }

    public function test_merge_rejects_unknown_target(): void
    {
        $category = $this->category('Única');

        $this->expectExceptionMessage('Categoria destino não encontrada.');
        app(MergeExpenseCategories::class)([$category], 999);
    }

    public function test_report_pdf_renders_the_selected_columns_and_the_total(): void
    {
        DB::table('fornecedor')->insert(['fornecedor_id' => 1, 'nome' => 'Fornecedor Um']);
        $expenses = collect([$this->expense(['valor' => 10]), $this->expense(['valor' => 2.5])])->each->load('supplier');

        $pdf = app(CompanyExpenseReportPdf::class)->render($expenses, ['supplier', 'value', 'status'], ['Status: Aberto'], 'Ana');
        $pages = $pdf->getNumPages();

        $this->assertStringStartsWith('%PDF', $pdf->Output('', 'S'));
        $this->assertSame(1, $pages);
    }

    public function test_excel_has_the_list_columns_with_a_numeric_value_and_text_dates(): void
    {
        DB::table('fornecedor')->insert(['fornecedor_id' => 1, 'nome' => 'Fornecedor Um']);
        $expense = $this->expense(['valor' => 1234.5, 'empresa' => 'f', 'tipo_pagamento' => 6, 'status' => 1, 'data_efetiva' => '2026-11-03', 'descricao' => 'Luz']);

        $sheet = app(ExportCompanyExpenses::class)->build(collect([$expense->load('supplier')]))->getActiveSheet();

        $this->assertSame('Despesas', $sheet->getTitle());
        $this->assertSame(['Fornecedor', 'Empresa', 'Centro de Custo', 'Categoria', 'Descrição', 'Valor (R$)'], $sheet->rangeToArray('A1:F1')[0]);
        $this->assertSame(['Fornecedor Um', 'Fiducia', null, null, 'Luz', 1234.5], $sheet->rangeToArray('A2:F2', null, true, false)[0]);
        $this->assertSame(['Pix', null, '10/11/2026', '03/11/2026', null, 'Ordinárias', 'Encerrado'], $sheet->rangeToArray('I2:O2', null, true, false)[0]);
    }

    private function category(string $name): int
    {
        return DB::table('categoria_despesa')->insertGetId(['nome' => $name]);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return ['fornecedor_id' => 1, 'centro_custo_id' => 1, 'categoria_despesa_id' => 1, 'valor' => 10, 'data_prevista' => '2026-11-10', 'tipo_despesa' => 1];
    }

    /** @param  array<string, mixed>  $attributes */
    private function expense(array $attributes = []): Despesa
    {
        return Despesa::create($attributes + $this->data() + ['status' => Despesa::STATUS_OPEN, 'created_by' => 1, 'created_at' => now()]);
    }
}
