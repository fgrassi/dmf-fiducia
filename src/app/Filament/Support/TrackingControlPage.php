<?php

namespace App\Filament\Support;

use App\Actions\TrackingControl\TrackingControlReport;
use App\Filament\Resources\Embarques\EmbarqueResource;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** Base das listas de Controle de Tracking (as telas do legado só mudam o filtro da operação e uma coluna). */
abstract class TrackingControlPage extends Page implements HasTable
{
    use InteractsWithTable;

    /** impor_expor: 'i' ou 'e'. */
    protected static string $operation;

    /** security.yml do módulo expenses (legado, o menu "Tracking" exige expenses/list): credencial booking. */
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

    /** A coluna que só existe numa das operações: Free Time na importação, Status na exportação. */
    abstract protected static function extraColumn(): TextColumn;

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection, ?string $search) {
                $rows = app(TrackingControlReport::class)(static::$operation);

                if (filled($search)) {
                    $rows = array_filter($rows, fn (array $row) => str_contains(mb_strtolower(implode(' ', [$row['booking'], $row['client'], $row['carrier'], $row['mbl'], $row['containers'], $row['ships']])), mb_strtolower($search)));
                }

                return ReportRecords::paginate($rows, $page, $recordsPerPage, $sortColumn, $sortDirection);
            })
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Nenhuma reserva em tracking')
            ->recordClasses(fn (array $record) => $record['priority'] ? 'bg-danger-50 dark:bg-danger-950' : null)
            ->recordUrl(fn (array $record) => EmbarqueResource::getUrl('view', ['record' => $record['id']]), shouldOpenInNewTab: true)
            ->columns([
                TextColumn::make('booking')
                    ->label('Booking')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client')
                    ->label('Cliente')
                    ->wrap()
                    ->sortable(),
                TextColumn::make('carrier')
                    ->label('Armador')
                    ->wrap()
                    ->sortable(),
                TextColumn::make('arrival')
                    ->label('Previsão de Chegada')
                    ->sortable()
                    ->date('d/m/Y'),
                TextColumn::make('destination')
                    ->label('Destino')
                    ->wrap()
                    ->sortable(),
                TextColumn::make('mbl')
                    ->label('MBL')
                    ->wrap(),
                TextColumn::make('containers')
                    ->label('Container')
                    ->wrap(),
                TextColumn::make('ships')
                    ->label('Navios')
                    ->wrap(),
                static::extraColumn(),
                TextColumn::make('last_tracking')
                    ->label('Último Tracking')
                    ->sortable()
                    ->date('d/m/Y'),
            ]);
    }
}
