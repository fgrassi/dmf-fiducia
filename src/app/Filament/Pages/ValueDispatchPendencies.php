<?php

namespace App\Filament\Pages;

use App\Actions\Booking\ReleaseValueDispatch;
use App\Actions\Reports\ValueDispatchPendenciesReport;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Support\ReportRecords;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * relPendenciaEnvioVal/list (legado), "Pendências - Envio de Valores": o controle de envio dos valores finais ao despachante, por
 * reserva, com status, quem liberou e quando. Credencial followup (security.yml do módulo).
 */
class ValueDispatchPendencies extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Pendências Envio de Valores ao Despachante';

    protected static ?string $navigationLabel = 'Pendências - Envio de Valores';

    protected static ?string $slug = 'relatorios/pendencias-envio-de-valores';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 22;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('followup');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection) => ReportRecords::paginate(app(ValueDispatchPendenciesReport::class)($this->tableFilters['busca'] ?? []), $page, $recordsPerPage, $sortColumn, $sortDirection))
            ->paginated([20, 50, 100])
            ->defaultPaginationPageOption(20)
            ->emptyStateHeading('Nenhum registro encontrado')
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn (Action $action) => $action->label('Buscar'))
            ->filtersResetAction(fn (Action $action) => $action->label('Limpar'))
            ->filters([
                Filter::make('busca')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                Select::make('status')
                                    ->label('Status')
                                    ->options([ValueDispatchPendenciesReport::RELEASED => 'Liberado', ValueDispatchPendenciesReport::PENDING => 'Pendente']),
                                Select::make('embarque_id')
                                    ->label('Booking')
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search) => DB::table('embarque')->where('booking', 'like', "%{$search}%")->orderBy('booking')->limit(30)->pluck('booking', 'embarque_id')->all())
                                    ->getOptionLabelUsing(fn ($value) => DB::table('embarque')->where('embarque_id', $value)->value('booking')),
                                DatePicker::make('data')
                                    ->label('Data Liberação'),
                            ]),
                    ]),
            ])
            ->recordActions([
                Action::make('liberar')
                    ->label('Liberar')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->requiresConfirmation()
                    ->modalHeading('Remover a pendência?')
                    ->visible(fn (array $record) => $record['status'] === 'Pendente' && auth()->user()->hasLegacyCredential('lib_val_des'))
                    ->action(function (array $record) {
                        app(ReleaseValueDispatch::class)($record['id'], auth()->id());

                        Notification::make()->title('Pendência liberada')->success()->send();
                    }),
            ])
            ->columns([
                TextColumn::make('booking')
                    ->label('Booking')
                    ->sortable()
                    ->url(fn (array $record) => EmbarqueResource::getUrl('view', ['record' => $record['id']])),
                TextColumn::make('client')
                    ->label('Cliente')
                    ->sortable()
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => $state === 'Pendente' ? 'warning' : 'success')
                    ->sortable(),
                TextColumn::make('released_by')
                    ->label('Liberado Por')
                    ->sortable(),
                TextColumn::make('released_on')
                    ->label('Data da Liberação')
                    ->sortable()
                    ->date('d/m/Y'),
            ]);
    }
}
