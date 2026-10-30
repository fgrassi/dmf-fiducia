<?php

namespace App\Filament\Resources\AccountingCovers\Tables;

use App\Actions\AccountingCovers\ArchiveCovers;
use App\Actions\AccountingCovers\CoverPdf;
use App\Actions\AccountingCovers\DeleteCover;
use App\Actions\AccountingCovers\UpdateCover;
use App\Models\CapaProcesso;
use App\Models\CapaProcessoArquivo;
use App\Models\CmnCurrency;
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

class AccountingCoversTable
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
                $query->withItemTotals()->with(['embarque', 'currency', 'folder']);

                self::applyDisplay($query, $livewire->tableFilters ?? []);

                return $query;
            })
            ->defaultSort('numero', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('numero')
                    ->label('Número'),
                TextColumn::make('embarque.booking')
                    ->label('Booking'),
                TextColumn::make('payment_date')
                    ->label('Data')
                    ->date('d/m/Y'),
                TextColumn::make('currency.name')
                    ->label('Moeda')
                    ->wrap()
                    ->formatStateUsing(fn ($state, CapaProcesso $record) => $state ? "{$state} ({$record->currency->symbol})" : null),
                TextColumn::make('custo_financeiro')
                    ->label('Custo Financeiro')
                    ->formatStateUsing(fn ($state, CapaProcesso $record) => self::money($state, $record->currency?->symbol)),
                TextColumn::make('receitas')
                    ->label('Receitas')
                    ->formatStateUsing(fn ($state, CapaProcesso $record) => self::money($state, $record->currency?->symbol)),
                TextColumn::make('comissoes')
                    ->label('Comissão')
                    ->formatStateUsing(fn ($state, CapaProcesso $record) => self::money($state, $record->currency?->symbol)),
                TextColumn::make('valor_cotacao')
                    ->label('Valor Cotação')
                    ->formatStateUsing(fn ($state) => 'R$ '.number_format((float) $state, 4, ',', '.')),
                TextColumn::make('cover_result')
                    ->label('Resultado (Capa)')
                    ->state(fn (CapaProcesso $record) => self::money($record->coverResult(), 'R$')),
                TextColumn::make('process_result')
                    ->label('Resultado (Processo)')
                    ->state(fn (CapaProcesso $record) => self::money($record->processResult(), 'R$')),
                TextColumn::make('folder.nome')
                    ->label('Caixa')
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
                    ->query(fn (Builder $query, array $data) => $query->when($data['numero'], fn (Builder $q, $number) => $q->where('capa_processo.numero', $number))),
                Filter::make('booking')
                    ->schema([TextInput::make('booking')->label('Booking')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['booking'], fn (Builder $q, $booking) => $q->whereHas('embarque', fn (Builder $e) => $e->where('booking', 'like', "%{$booking}%")))),
                SelectFilter::make('folder')
                    ->label('Caixa')
                    ->options(fn () => CapaProcessoArquivo::orderByDesc('ano')->orderByDesc('mes')->pluck('nome', 'capa_processo_arquivo_id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn (Builder $q, $folder) => $q->where('capa_processo.capa_processo_arquivo_id', $folder))),
                SelectFilter::make('origin_port')
                    ->label('Porto de origem')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => self::ports($search))
                    ->getOptionLabelUsing(fn ($value) => DB::table('porto')->where('porto_id', $value)->value('nome'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn (Builder $q, $port) => $q->whereHas('embarque', fn (Builder $e) => $e->where('porto_origem_id', $port)))),
                SelectFilter::make('destination_port')
                    ->label('Porto de destino')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => self::ports($search))
                    ->getOptionLabelUsing(fn ($value) => DB::table('porto')->where('porto_id', $value)->value('nome'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn (Builder $q, $port) => $q->whereHas('embarque', fn (Builder $e) => $e->where('porto_destino_id', $port)))),
                SelectFilter::make('client')
                    ->label('Cliente')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => DB::table('pessoa')->where('nome', 'like', "%{$search}%")->orderBy('nome')->limit(30)->pluck('nome', 'pessoa_id')->all())
                    ->getOptionLabelUsing(fn ($value) => DB::table('pessoa')->where('pessoa_id', $value)->value('nome'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn (Builder $q, $client) => $q->whereHas('embarque', fn (Builder $e) => $e->where('cliente_id', $client)))),
                Filter::make('goods')
                    ->schema([TextInput::make('goods')->label('Mercadoria')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['goods'], fn (Builder $q, $goods) => $q->whereExists(fn ($m) => $m->selectRaw('1')->from('mercadoria as m')
                        ->whereColumn('m.embarque_id', 'capa_processo.embarque_id')->where('m.nome', 'like', "%{$goods}%")))),
                SelectFilter::make('operation')
                    ->label('Impo/Expo')
                    ->options(['i' => 'Importação', 'e' => 'Exportação'])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn (Builder $q, $operation) => $q->whereHas('embarque', fn (Builder $e) => $e->where('impor_expor', $operation)))),
            ])
            ->recordActions([
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(fn (CapaProcesso $record, CoverPdf $pdf) => $pdf($record)),
                EditAction::make()
                    ->schema([
                        TextInput::make('numero')
                            ->label('Número')
                            ->numeric()
                            ->required(),
                        Select::make('moeda_id')
                            ->label('Moeda')
                            ->options(fn () => CmnCurrency::orderBy('name')->pluck('name', 'id')->all())
                            ->required(),
                        TextInput::make('custo_financeiro')
                            ->label('Custo Financeiro')
                            ->numeric(),
                        TextInput::make('receitas')
                            ->label('Receitas')
                            ->numeric(),
                        TextInput::make('comissoes')
                            ->label('Comissão')
                            ->numeric(),
                        TextInput::make('valor_cotacao')
                            ->label('Valor Cotação')
                            ->numeric(),
                        DatePicker::make('payment_date')
                            ->label('Data'),
                    ])
                    ->using(fn (CapaProcesso $record, array $data, UpdateCover $update) => $update($record, $data)),
                DeleteAction::make()
                    ->using(fn (CapaProcesso $record, DeleteCover $delete) => $delete($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('archive')
                        ->label('Arquivar')
                        ->icon('heroicon-o-archive-box-arrow-down')
                        ->schema([
                            Select::make('folder')
                                ->label('Caixa')
                                ->options(fn () => CapaProcessoArquivo::where('status', 1)->orderBy('nome')->pluck('nome', 'capa_processo_arquivo_id')->all())
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data, ArchiveCovers $archive) {
                            $count = $archive($records->modelKeys(), CapaProcessoArquivo::findOrFail($data['folder']));

                            Notification::make()->title("{$count} capa(s) arquivada(s).")->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * "Exibir" do legado só vale sem busca por número, reserva ou pasta; com busca, mostra a capa esteja onde estiver.
     *
     * @param  array<string, mixed>  $filters
     */
    private static function applyDisplay(Builder $query, array $filters): void
    {
        $searching = filled($filters['numero']['numero'] ?? null)
            || filled($filters['booking']['booking'] ?? null)
            || filled($filters['folder']['value'] ?? null);

        if ($searching) {
            return;
        }

        match ($filters['display']['value'] ?? 'unarchived') {
            'recent' => $query->orderByDesc('capa_processo.capa_process_id'),
            'all' => null,
            'archived' => $query->whereNotNull('capa_processo.capa_processo_arquivo_id'),
            default => $query->whereNull('capa_processo.capa_processo_arquivo_id'),
        };
    }

    /** @return array<int, string> */
    private static function ports(string $search): array
    {
        return DB::table('porto')->where('nome', 'like', "%{$search}%")->orderBy('nome')->limit(30)->pluck('nome', 'porto_id')->all();
    }

    private static function money(mixed $value, ?string $symbol): string
    {
        return trim("{$symbol} ".number_format((float) $value, 2, ',', '.'));
    }
}
