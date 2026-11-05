<?php

namespace App\Filament\Livewire;

use App\Actions\Financeiro\FasaProcessSheet;
use App\Filament\Resources\Charges\ChargeResource;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/** Uma das três seções da Planilha Processos FASA (a página monta uma por seção, com os filtros já aplicados). */
class FasaSectionTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public string $section;

    /** @var array<string, mixed> */
    public array $sheetFilters = [];

    /** @var array<string, mixed>|null */
    private ?array $sheet = null;

    /** @param  array<string, mixed>  $sheetFilters */
    public function mount(string $section, array $sheetFilters = []): void
    {
        $this->section = $section;
        $this->sheetFilters = $sheetFilters;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn () => $this->sheet()['title'])
            ->description(fn () => $this->sheet()['total_label'].': R$ '.number_format($this->sheet()['total'], 2, ',', '.'))
            ->records(fn (?string $sortColumn, ?string $sortDirection, ?string $search): Collection => collect($this->sheet()['rows'])
                ->keyBy('id')
                ->when(filled($search), fn (Collection $rows) => $rows->filter(fn (array $row) => collect($row)->only(['empresa', 'shipper', 'consignee', 'booking', 'fatura'])
                    ->contains(fn (string $value) => str_contains(mb_strtolower($value), mb_strtolower($search)))))
                ->when(filled($sortColumn), fn (Collection $rows) => $rows->sortBy($sortColumn, SORT_NATURAL | SORT_FLAG_CASE, $sortDirection === 'desc')))
            ->extraAttributes(['class' => 'sas-ta-compact'], merge: true)
            ->paginated(false)
            ->emptyStateHeading('Nenhum registro')
            ->recordClasses(fn (array $record) => $record['situacao'] === 'vencido' ? 'bg-red-50 dark:bg-red-950/30' : null)
            ->columns([
                TextColumn::make('empresa')
                    ->label('Empresa')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('shipper')
                    ->label('Shipper')
                    ->sortable()
                    ->wrap(),
                TextColumn::make('consignee')
                    ->label('Consignee')
                    ->sortable()
                    ->wrap(),
                TextColumn::make('booking')
                    ->label('Booking')
                    ->sortable(),
                TextColumn::make('tipo_cobranca')
                    ->label('Tipo'),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->numeric(2, ',', '.')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('fatura')
                    ->label('Fatura'),
                TextColumn::make('saida_efetiva')
                    ->label('Saída efetiva'),
                TextColumn::make('vencimento')
                    ->label('Vencimento'),
                TextColumn::make('taxa')
                    ->label('Taxa')
                    ->visible(fn () => $this->sheet()['has_taxa'])
                    ->wrap(),
                TextColumn::make('situacao')
                    ->label('Situação'),
                TextColumn::make('num_cobranca')
                    ->label('Num. Cobrança')
                    ->url(fn (array $record) => ChargeResource::getUrl('view', ['record' => $record['id']]))
                    ->openUrlInNewTab(),
                TextColumn::make('data_pagamento')
                    ->label('Data do pagamento'),
                TextColumn::make('atraso')
                    ->label('Atraso')
                    ->wrap(),
                TextColumn::make('obs')
                    ->label('Obs')
                    ->placeholder('N/D')
                    ->wrap(),
            ]);
    }

    public function render(): View
    {
        return view('livewire.fasa-section-table');
    }

    /** @return array<string, mixed> */
    private function sheet(): array
    {
        return $this->sheet ??= app(FasaProcessSheet::class)($this->sheetFilters)[$this->section];
    }
}
