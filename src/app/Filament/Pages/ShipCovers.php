<?php

namespace App\Filament\Pages;

use App\Actions\ShipCover\ShipCoverPdf;
use App\Filament\Support\ReportFields;
use App\Models\Embarque;
use App\Models\Navio;
use App\Models\Porto;
use BackedEnum;
use Filament\Actions\BulkAction;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * capaNavio (legado), "Capas de Navio (Impo)": as reservas em aberto do navio entre dois portos (cadastradas nos últimos
 * 2 anos e ainda sem saída efetiva) e a capa de navio em PDF com as marcadas.
 */
class ShipCovers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Capas de Navio (Impo)';

    protected static ?string $navigationLabel = 'Capas de Navio (Impo)';

    protected static ?string $slug = 'cadastros/capas-navio';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 160;

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
            ->query(fn () => $this->bookings())
            ->defaultSort('booking')
            ->paginated(false)
            ->emptyStateHeading(fn () => $this->hasFilters()
                ? 'Nenhum registro encontrado'
                : 'É necessário informar o Navio, Porto de Origem e Porto de Destino para gerar a capa de navio.')
            ->emptyStateDescription(null)
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn ($action) => $action->label('Buscar'))
            ->filtersResetAction(fn ($action) => $action->label('Limpar'))
            ->filters([
                Filter::make('capa')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                ReportFields::ship('navio', 'Navio'),
                                ReportFields::port('porto_origem', 'Porto de Origem'),
                                ReportFields::port('porto_destino', 'Porto de Destino'),
                            ]),
                    ]),
            ])
            ->columns([
                TextColumn::make('cliente.nome')
                    ->label('Cliente'),
                TextColumn::make('booking')
                    ->label('Booking')
                    ->sortable(),
                TextColumn::make('saida_prevista')
                    ->label('ETD')
                    ->date('d/m/Y'),
                TextColumn::make('chegada_prevista')
                    ->label('ETA')
                    ->date('d/m/Y'),
                TextColumn::make('vg')
                    ->label('VG'),
                TextColumn::make('equipamento')
                    ->label('Equipamento')
                    ->state(fn (Embarque $record) => $record->goods->map(fn ($goods) => $goods->quantidade.' * '.$goods->equipment?->nome)->all())
                    ->listWithLineBreaks(),
                TextColumn::make('agente.nome')
                    ->label('Agente'),
            ])
            ->toolbarActions([
                BulkAction::make('capa')
                    ->label('Capa de Navio (PDF)')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->visible(fn () => $this->hasFilters())
                    ->action(fn (Collection $records) => app(ShipCoverPdf::class)(
                        Navio::findOrFail($this->filters()['navio']),
                        Porto::findOrFail($this->filters()['porto_origem']),
                        Porto::findOrFail($this->filters()['porto_destino']),
                        $records,
                    ))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    private function bookings(): Builder
    {
        $query = Embarque::query()->with(['cliente', 'agente', 'goods.equipment']);

        if (! $this->hasFilters()) {
            return $query->whereRaw('1 = 0');
        }

        $filters = $this->filters();

        return $query
            ->where('navio_id', $filters['navio'])
            ->where('porto_origem_id', $filters['porto_origem'])
            ->where('porto_destino_id', $filters['porto_destino'])
            ->whereNotIn('finalizado', ['c', 'e'])
            ->whereNull('saida_efetiva')
            ->whereYear('dteCadastro', '>=', now()->year - 2);
    }

    /** @return array{navio?: mixed, porto_origem?: mixed, porto_destino?: mixed} */
    private function filters(): array
    {
        return $this->tableFilters['capa'] ?? [];
    }

    private function hasFilters(): bool
    {
        $filters = $this->filters();

        return filled($filters['navio'] ?? null) && filled($filters['porto_origem'] ?? null) && filled($filters['porto_destino'] ?? null);
    }
}
