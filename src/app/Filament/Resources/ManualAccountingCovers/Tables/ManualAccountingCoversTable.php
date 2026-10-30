<?php

namespace App\Filament\Resources\ManualAccountingCovers\Tables;

use App\Actions\AccountingCovers\ArchiveCovers;
use App\Actions\AccountingCovers\DeleteManualCover;
use App\Actions\AccountingCovers\ManualCoverPdf;
use App\Actions\AccountingCovers\SaveManualCover;
use App\Models\CapaProcessoArquivo;
use App\Models\CapaProcessoManual;
use App\Models\FinExpenses;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ManualAccountingCoversTable
{
    private const DISPLAY = [
        'unarchived' => 'Não Arquivado',
        'archived' => 'Arquivado',
        'all' => 'Todos',
        'recent' => 'Recentes',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query, HasTable $livewire) {
                $query->with(['client', 'expense', 'currency', 'buyingCurrency', 'sellingCurrency', 'folder']);

                self::applyDisplay($query, $livewire->tableFilters ?? []);

                return $query;
            })
            ->defaultSort('numero', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('numero')
                    ->label('Número'),
                TextColumn::make('client.nome')
                    ->label('Cliente')
                    ->wrap(),
                TextColumn::make('nome_cliente')
                    ->label('Nome Cliente')
                    ->wrap(),
                TextColumn::make('currency.symbol')
                    ->label('Moeda'),
                TextColumn::make('valor_cotacao')
                    ->label('Valor Cotação')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 4, ',', '.')),
                TextColumn::make('expense.name')
                    ->label('Despesa')
                    ->wrap(),
                TextColumn::make('buyingCurrency.symbol')
                    ->label('Moeda Compra'),
                TextColumn::make('buying_rate')
                    ->label('Valor Compra')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.')),
                TextColumn::make('sellingCurrency.symbol')
                    ->label('Moeda Venda'),
                TextColumn::make('selling_rate')
                    ->label('Valor Venda')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.')),
                TextColumn::make('payment_date')
                    ->label('Data de Pagamento')
                    ->date('d/m/Y'),
                TextColumn::make('folder.nome')
                    ->label('Pasta')
                    ->default('Não Arquivado'),
            ])
            ->filters([
                SelectFilter::make('display')
                    ->label('Exibir')
                    ->options(self::DISPLAY)
                    ->default('unarchived')
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query) => $query),
                Filter::make('numero')
                    ->schema([TextInput::make('numero')->label('Número')->numeric()])
                    ->query(fn (Builder $query, array $data) => $query->when(filled($data['numero']), fn (Builder $q) => $q->where('numero', $data['numero']))),
                SelectFilter::make('client')
                    ->label('Cliente')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => DB::table('pessoa')->where('nome', 'like', "%{$search}%")->orderBy('nome')->limit(30)->pluck('nome', 'pessoa_id')->all())
                    ->getOptionLabelUsing(fn ($value) => DB::table('pessoa')->where('pessoa_id', $value)->value('nome'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn (Builder $q, $client) => $q->where('cliente_id', $client))),
                Filter::make('client_name')
                    ->schema([TextInput::make('client_name')->label('Nome Cliente')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['client_name'], fn (Builder $q, $name) => $q->where('nome_cliente', 'like', "%{$name}%"))),
                SelectFilter::make('expense')
                    ->label('Despesa')
                    ->searchable()
                    ->options(fn () => FinExpenses::orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn (Builder $q, $expense) => $q->where('ref_expense', $expense))),
                SelectFilter::make('folder')
                    ->label('Pasta')
                    ->options(fn () => CapaProcessoArquivo::orderByDesc('ano')->orderByDesc('mes')->pluck('nome', 'capa_processo_arquivo_id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn (Builder $q, $folder) => $q->where('capa_processo_arquivo_id', $folder))),
                Filter::make('payment')
                    ->schema([
                        DatePicker::make('from')->label('Pago de'),
                        DatePicker::make('until')->label('Pago até'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'], fn (Builder $q, $date) => $q->whereDate('payment_date', '>=', $date))
                        ->when($data['until'], fn (Builder $q, $date) => $q->whereDate('payment_date', '<=', $date))),
            ])
            ->recordActions([
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(fn (CapaProcessoManual $record, ManualCoverPdf $pdf) => $pdf($record)),
                EditAction::make()
                    ->using(fn (CapaProcessoManual $record, array $data, SaveManualCover $save) => $save($data, $record)),
                DeleteAction::make()
                    ->using(fn (CapaProcessoManual $record, DeleteManualCover $delete) => $delete($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('archive')
                        ->label('Arquivar')
                        ->icon('heroicon-o-archive-box-arrow-down')
                        ->schema([
                            Select::make('folder')
                                ->label('Pasta')
                                ->options(fn () => CapaProcessoArquivo::where('status', 1)->orderBy('nome')->pluck('nome', 'capa_processo_arquivo_id')->all())
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data, ArchiveCovers $archive) {
                            $count = $archive($records->modelKeys(), CapaProcessoArquivo::findOrFail($data['folder']), CapaProcessoManual::class);

                            Notification::make()->title("{$count} capa(s) arquivada(s).")->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * "Exibir" do legado só vale sem filtro de pasta; com pasta, mostra as capas dela por número decrescente.
     *
     * @param  array<string, mixed>  $filters
     */
    private static function applyDisplay(Builder $query, array $filters): void
    {
        if (filled($filters['folder']['value'] ?? null)) {
            return;
        }

        match ($filters['display']['value'] ?? 'unarchived') {
            'recent' => $query->where('created_at', '>=', now()->subDays(30)->toDateString())->orderByDesc('capa_processo_manual_id'),
            'all' => null,
            'archived' => $query->whereNotNull('capa_processo_arquivo_id')->orderByDesc('capa_processo_arquivo_id'),
            default => $query->whereNull('capa_processo_arquivo_id'),
        };
    }
}
