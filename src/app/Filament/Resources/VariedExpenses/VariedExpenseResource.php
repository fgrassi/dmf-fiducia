<?php

namespace App\Filament\Resources\VariedExpenses;

use App\Actions\VariedExpenses\BookingExpenseLines;
use App\Actions\VariedExpenses\DeleteVariedExpense;
use App\Actions\VariedExpenses\SaveVariedExpense;
use App\Enums\OperationType;
use App\Filament\Resources\VariedExpenses\Pages\ManageVariedExpenses;
use App\Filament\Support\OperationBadge;
use App\Models\CmnCurrency;
use App\Models\Embarque;
use App\Models\FinDespesasVariadas;
use App\Models\Pessoa;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** despesasVariadas (legado, "Notas Variadas Agentes"): notas de crédito e débito avulsas dos agentes, ligadas à reserva pelo booking. */
class VariedExpenseResource extends Resource
{
    protected static ?string $model = FinDespesasVariadas::class;

    protected static ?string $modelLabel = 'Nota Variada';

    protected static ?string $pluralModelLabel = 'Notas Variadas Agentes';

    protected static ?string $navigationLabel = 'Notas Variadas Agentes';

    protected static ?string $slug = 'financeiro/notas-variadas-agentes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 45;

    /** security.yml do módulo (legado): credenciais manager e financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['manager', 'financial']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Select::make('embarque_id')
                    ->label('Reserva')
                    ->helperText('Busque pelo booking; só aparecem reservas com despesas lançadas. Preenche os dados da nota.')
                    ->hiddenOn('edit')
                    ->searchable()
                    ->live()
                    ->columnSpanFull()
                    ->getSearchResultsUsing(fn (string $search) => app(BookingExpenseLines::class)->bookings($search)
                        ->mapWithKeys(fn (Embarque $booking) => [$booking->getKey() => self::bookingLabel($booking)]))
                    ->getOptionLabelUsing(fn ($value) => ($booking = Embarque::find($value)) ? self::bookingLabel($booking) : null)
                    ->afterStateUpdated(function (Set $set, $state) {
                        $booking = $state ? Embarque::with('cliente')->find($state) : null;

                        $set('booking', $booking?->booking);
                        $set('agente_id', $booking?->pessoa_id_representante);
                        $set('mbl', $booking?->master_bl);
                        $set('hbl', $booking?->house_bl);
                        $set('shipper', $booking?->cliente?->nome);
                        $set('impor_expor', $booking?->impor_expor ?? 'i');
                    }),
                TextInput::make('booking')
                    ->label('Booking')
                    ->required()
                    ->maxLength(255),
                Select::make('agente_id')
                    ->label('Agente')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => self::agents()
                        ->where('pessoa.nome', 'like', "%{$search}%")
                        ->limit(30)
                        ->pluck('pessoa.nome', 'pessoa.pessoa_id'))
                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                DatePicker::make('data_despesa')
                    ->label('Data')
                    ->required(),
                Select::make('impor_expor')
                    ->label('Tipo Embarque')
                    ->options(OperationType::options())
                    ->default('i')
                    ->selectablePlaceholder(false)
                    ->required(),
                Select::make('tipo_nota')
                    ->label('Tipo Nota')
                    ->options(FinDespesasVariadas::NOTE_TYPES)
                    ->default('1')
                    ->selectablePlaceholder(false)
                    ->required(),
                TextInput::make('mbl')
                    ->label('MBL')
                    ->maxLength(255),
                TextInput::make('hbl')
                    ->label('HBL')
                    ->maxLength(255),
                TextInput::make('invoice')
                    ->label('Invoice')
                    ->maxLength(255),
                TextInput::make('shipper')
                    ->label('Cliente')
                    ->maxLength(255),
                Select::make('cmn_currencies_id')
                    ->label('Moeda')
                    ->required()
                    ->options(fn () => CmnCurrency::query()->orderBy('name')->get()->mapWithKeys(fn (CmnCurrency $currency) => [$currency->id => "{$currency->symbol} - {$currency->name}"])),
                TextInput::make('valor')
                    ->label('Valor')
                    ->required()
                    ->numeric(),
                FileUpload::make('note_file')
                    ->label('Arquivo da Nota')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->storeFiles(false),
                Placeholder::make('lines')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->hidden(fn (Get $get) => ! $get('embarque_id'))
                    ->content(fn (Get $get) => ($booking = Embarque::find($get('embarque_id'))) ? self::linesTable($booking) : null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['agent', 'currency']))
            ->defaultSort('fin_despesas_variadas_id', 'desc')
            ->paginationPageOptions([40, 100])
            ->defaultPaginationPageOption(40)
            ->filtersFormWidth('3xl')
            ->filtersFormColumns(2)
            ->emptyStateHeading('Nenhum registro encontrado')
            ->columns([
                TextColumn::make('agent.nome')
                    ->label('Agente')
                    ->wrap(),
                TextColumn::make('data_despesa')
                    ->label('Data')
                    ->date('d/m/Y'),
                OperationBadge::column()
                    ->label('Tipo Embarque'),
                TextColumn::make('tipo_nota')
                    ->label('Tipo Nota')
                    ->formatStateUsing(fn (?string $state) => FinDespesasVariadas::NOTE_TYPES[$state] ?? ''),
                TextColumn::make('booking')
                    ->label('Booking'),
                TextColumn::make('mbl')
                    ->label('MBL'),
                TextColumn::make('hbl')
                    ->label('HBL'),
                TextColumn::make('invoice')
                    ->label('Invoice'),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->formatStateUsing(fn ($state, FinDespesasVariadas $record) => trim($record->currency?->symbol.' '.number_format((float) $state, 2, ',', '.'))),
                TextColumn::make('shipper')
                    ->label('Cliente')
                    ->wrap(),
                TextColumn::make('status_pagamento')
                    ->label('Status Baixa')
                    ->badge()
                    ->formatStateUsing(fn ($state) => (int) $state === 1 ? 'Baixada' : 'Aberto')
                    ->color(fn ($state) => (int) $state === 1 ? 'success' : 'gray'),
                TextColumn::make('data_pagamento')
                    ->label('Data Baixa')
                    ->date('d/m/Y'),
            ])
            ->filters([
                Filter::make('busca')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextInput::make('generico')->label('Booking/MBL/HBL/Invoice'),
                                TextInput::make('booking')->label('Booking'),
                                TextInput::make('invoice')->label('Invoice'),
                                TextInput::make('mbl')->label('MBL'),
                                TextInput::make('hbl')->label('HBL'),
                            ]),
                    ])
                    ->query(function (Builder $query, array $data) {
                        $like = fn (string $value) => '%'.trim($value).'%';

                        return $query
                            ->when(filled($data['generico'] ?? null), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                                ->where('booking', 'like', $like($data['generico']))
                                ->orWhere('hbl', 'like', $like($data['generico']))
                                ->orWhere('mbl', 'like', $like($data['generico']))
                                ->orWhere('invoice', 'like', $like($data['generico']))))
                            ->when(filled(trim((string) ($data['booking'] ?? ''))), fn (Builder $query) => $query->where('booking', 'like', $like($data['booking'])))
                            ->when(filled($data['invoice'] ?? null), fn (Builder $query) => $query->where('invoice', 'like', $like($data['invoice'])))
                            ->when(filled($data['hbl'] ?? null), fn (Builder $query) => $query->where('hbl', 'like', $like($data['hbl'])))
                            ->when(filled(trim((string) ($data['mbl'] ?? ''))), fn (Builder $query) => $query->where('mbl', 'like', $like($data['mbl'])));
                    }),
                SelectFilter::make('agente_id')
                    ->label('Agente')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => self::agents()
                        ->where('pessoa.nome', 'like', "%{$search}%")
                        ->limit(30)
                        ->pluck('pessoa.nome', 'pessoa.pessoa_id'))
                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                SelectFilter::make('impor_expor')
                    ->label('Tipo Embarque')
                    ->options(OperationType::options()),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Editar')
                    ->modalWidth('5xl')
                    ->using(fn (array $data, FinDespesasVariadas $record, Action $action) => self::save($data, $record, $action)),
                Action::make('remover')
                    ->label('Remover')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Confirmar exclusão?')
                    ->action(function (FinDespesasVariadas $record, DeleteVariedExpense $delete) {
                        $delete($record);
                        Notification::make()->title('Registro Removido com Sucesso')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageVariedExpenses::route('/'),
        ];
    }

    /**
     * Grava pela Action; o aviso do legado ("Registro Criado/Atualizado com Sucesso") sai da própria CreateAction/EditAction.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data, ?FinDespesasVariadas $record, Action $action): FinDespesasVariadas
    {
        $bookingId = $data['embarque_id'] ?? null;
        $noteFile = $data['note_file'] ?? null;
        unset($data['embarque_id'], $data['note_file']);

        try {
            return app(SaveVariedExpense::class)($data, $record, $bookingId ? (int) $bookingId : null, $noteFile);
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();
        }
    }

    /** Pessoas do tipo 1 (agentes), como o agente/ajax2 do legado. */
    public static function agents(): Builder
    {
        return Pessoa::query()
            ->whereIn('pessoa.pessoa_id', DB::table('pessoa_tipo_pessoa')->where('tipo_pessoa_id', 1)->select('pessoa_id'))
            ->orderBy('pessoa.nome');
    }

    private static function bookingLabel(Embarque $booking): string
    {
        $status = Embarque::STATUSES[$booking->finalizado] ?? null;

        return collect([$booking->booking, $booking->cliente?->nome, $booking->master_bl, $booking->house_bl, $status])->filter()->implode(' · ');
    }

    /** Tabela de compra e venda por BL (a da direita do formulário do legado). */
    private static function linesTable(Embarque $booking): HtmlString
    {
        $rows = collect(app(BookingExpenseLines::class)($booking))->map(fn (array $line) => '<tr>'.collect($line)->map(fn ($cell) => '<td style="padding: 2px 8px;">'.e($cell).'</td>')->implode('').'</tr>')->implode('');
        $head = collect(['BL', 'Despesa', 'Compra', 'Venda', 'Billing', 'Due To'])->map(fn (string $title) => '<th style="padding: 2px 8px; text-align: left;">'.e($title).'</th>')->implode('');

        return new HtmlString("<div style=\"overflow-x: auto; font-size: .875rem;\"><table style=\"width: 100%;\"><thead><tr>{$head}</tr></thead><tbody>{$rows}</tbody></table></div>");
    }
}
