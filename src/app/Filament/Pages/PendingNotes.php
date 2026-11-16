<?php

namespace App\Filament\Pages;

use App\Actions\Reports\PendingNotesPdf;
use App\Actions\Reports\PendingNotesReport;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Support\ReportRecords;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * reports/listaProcessoSemNotas (legado), "Pendências Debit/Credit Notes": reservas marítimas de exportação embarcadas sem nota de
 * débito nem de crédito, com o frete negociado de cada uma. O PDF só leva as embarcadas há mais de 5 dias, como o legado.
 */
class PendingNotes extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Pendências Debit/Credit Notes';

    protected static ?string $navigationLabel = 'Pendências Debit/Credit Notes';

    protected static ?string $slug = 'relatorios/pendencias-debit-credit-notes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMinus;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 21;

    /** security.yml do módulo (legado): credencial booking. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('booking');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('Gerar PDF')
                ->color('gray')
                ->action(fn () => app(PendingNotesPdf::class)()),
        ];
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
            ->records(fn (int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection) => ReportRecords::paginate(app(PendingNotesReport::class)(), $page, $recordsPerPage, $sortColumn, $sortDirection))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Nenhum item a ser exibido')
            ->columns([
                TextColumn::make('booking')
                    ->label('Booking')
                    ->sortable()
                    ->url(fn (array $record) => EmbarqueResource::getUrl('view', ['record' => $record['id']]), shouldOpenInNewTab: true),
                TextColumn::make('direct_bl')
                    ->label('BL Direto')
                    ->sortable(),
                TextColumn::make('freight')
                    ->label('Frete Negociado')
                    ->listWithLineBreaks(),
                TextColumn::make('shipper')
                    ->label('Shipper')
                    ->sortable()
                    ->wrap(),
                TextColumn::make('vessel')
                    ->label('Navio')
                    ->sortable(),
                TextColumn::make('origin')
                    ->label('Porto de Origem')
                    ->sortable(),
                TextColumn::make('destination')
                    ->label('Porto de Destino')
                    ->sortable(),
                TextColumn::make('departed')
                    ->label('Saída Efetiva')
                    ->sortable()
                    ->date('d/m/Y'),
            ]);
    }
}
