<?php

namespace App\Filament\Support;

use App\Actions\Reports\ConfirmationsReport;
use App\Enums\OperationType;
use App\Filament\Resources\Embarques\EmbarqueResource;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

/** Base das listas de confirmações de chegada e de embarque (as telas do legado só mudam a tabela e o título). */
abstract class ConfirmationsPage extends Page implements HasTable
{
    use InteractsWithTable;

    /** 'arrival' ou 'departure' (chave de ConfirmationsReport::TABLES). */
    protected static string $kind;

    /** security.yml do módulo (legado): credencial booking. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('booking');
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
            ->records(function (int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection) {
                $filters = $this->tableFilters['periodo'] ?? [];

                return ReportRecords::paginate(app(ConfirmationsReport::class)(static::$kind, $filters['data_ini'] ?? null, $filters['data_fim'] ?? null, $filters['impor_expor'] ?? null), $page, $recordsPerPage, $sortColumn, $sortDirection);
            })
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Nenhuma confirmação no período informado')
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn (Action $action) => $action->label('Enviar'))
            ->filtersResetAction(fn (Action $action) => $action->label('Limpar'))
            ->filters([
                Filter::make('periodo')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                DatePicker::make('data_ini')
                                    ->label('Data inicial')
                                    ->default(now()->subMonth()),
                                DatePicker::make('data_fim')
                                    ->label('Data final')
                                    ->default(now()),
                                Select::make('impor_expor')
                                    ->label('Tipo de embarque')
                                    ->options(OperationType::class),
                            ]),
                    ]),
            ])
            ->columns([
                TextColumn::make('date')
                    ->label('Data')
                    ->sortable()
                    ->date('d/m/Y'),
                OperationBadge::column('operation')
                    ->label('Tipo embarque')
                    ->sortable(),
                TextColumn::make('booking')
                    ->label('Booking')
                    ->sortable()
                    ->url(fn (array $record) => EmbarqueResource::getUrl('view', ['record' => $record['id']])),
                TextColumn::make('client')
                    ->label('Cliente')
                    ->wrap()
                    ->sortable(),
                TextColumn::make('departure')
                    ->label('Data de partida')
                    ->sortable()
                    ->date('d/m/Y'),
                TextColumn::make('arrival')
                    ->label('Data de chegada')
                    ->sortable()
                    ->date('d/m/Y'),
                TextColumn::make('author')
                    ->label('Confirmado por'),
            ]);
    }
}
