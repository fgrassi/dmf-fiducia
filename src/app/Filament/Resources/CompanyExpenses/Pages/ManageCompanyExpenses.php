<?php

namespace App\Filament\Resources\CompanyExpenses\Pages;

use App\Actions\Expenses\CompanyExpenseReportPdf;
use App\Actions\Expenses\ExportCompanyExpenses;
use App\Actions\Expenses\SaveExpense;
use App\Filament\Resources\CompanyExpenses\CompanyExpenseResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageCompanyExpenses extends ManageRecords
{
    protected static string $resource = CompanyExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('relatorio')
                ->label('Relatório')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->modalHeading('Relatório de Despesas (PDF)')
                ->modalDescription('Sai com os filtros aplicados na lista; marque as colunas que entram.')
                ->modalSubmitActionLabel('Gerar PDF')
                ->schema([
                    CheckboxList::make('columns')
                        ->label('Colunas')
                        ->options(collect(CompanyExpenseReportPdf::COLUMNS)->map(fn (array $column) => $column[0])->all())
                        ->default(['supplier', 'category', 'description', 'value', 'due_date', 'status'])
                        ->columns(3)
                        ->bulkToggleable()
                        ->required(),
                ])
                ->action(fn (array $data, CompanyExpenseReportPdf $report) => $report(
                    $this->getFilteredTableQuery()
                        ->with(['supplier', 'costCenter', 'category', 'currency', 'bank'])
                        ->reorder('despesa.created_at', 'desc')
                        ->get(),
                    $data['columns'],
                    CompanyExpenseResource::activeFilterLabels($this->tableFilters ?? []),
                    (string) auth()->user()->getFilamentName(),
                )),
            Action::make('excel')
                ->label('Exportar para Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->action(fn (ExportCompanyExpenses $export) => $export(
                    $this->getFilteredSortedTableQuery()
                        ->with(['supplier', 'costCenter', 'category', 'currency', 'bank'])
                        ->get(),
                )),
            CreateAction::make()
                ->label('Nova Despesa')
                ->modalWidth('4xl')
                ->using(fn (array $data) => app(SaveExpense::class)($data, (int) auth()->id())),
        ];
    }
}
