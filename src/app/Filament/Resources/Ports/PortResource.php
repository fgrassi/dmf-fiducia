<?php

namespace App\Filament\Resources\Ports;

use App\Filament\Actions\MergeRecordsAction;
use App\Filament\Clusters\Locations;
use App\Filament\Resources\Ports\Pages\ManagePorts;
use App\Models\Cidade;
use App\Models\Estado;
use App\Models\Pais;
use App\Models\Porto;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** porto (legado): portos, aeroportos e portos secos. */
class PortResource extends Resource
{
    protected static ?string $model = Porto::class;

    protected static ?string $modelLabel = 'Porto / Aeroporto';

    protected static ?string $pluralModelLabel = 'Portos / Aeroportos';

    protected static ?string $navigationLabel = 'Portos / Aeroportos';

    protected static ?string $slug = 'portos';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $cluster = Locations::class;

    protected static ?int $navigationSort = 4;

    /** security.yml do módulo (legado): credencial localidade. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('localidade');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                Select::make('tipo')
                    ->label('Tipo')
                    ->options(Porto::TYPES)
                    ->required(),
                Select::make('ref_pais')
                    ->label('País')
                    ->options(fn () => Pais::orderBy('nome')->pluck('nome', 'paises_id'))
                    ->searchable()
                    ->required(),
                Select::make('ref_estado')
                    ->label('Estado')
                    ->options(fn () => Estado::orderBy('nome')->pluck('nome', 'estados_id'))
                    ->searchable()
                    ->live(),
                Select::make('cidades_id')
                    ->label('Cidade')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search, callable $get) => Cidade::query()
                        ->when($get('ref_estado'), fn ($query, $state) => $query->where('estados_id', $state))
                        ->where('nome', 'like', "%{$search}%")
                        ->orderBy('nome')
                        ->limit(50)
                        ->pluck('nome', 'cidades_id'))
                    ->getOptionLabelUsing(fn ($value) => Cidade::find($value)?->nome),
                TextInput::make('uncode')
                    ->label('UN Code')
                    ->maxLength(5),
                TextInput::make('us_code')
                    ->label('US Code')
                    ->helperText('Código utilizado na integração com a AIC')
                    ->numeric(),
                Select::make('despachante_id')
                    ->label('Despachante')
                    ->options(fn () => DB::table('despachante')->orderBy('nome')->pluck('nome', 'despachante_id'))
                    ->searchable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nome')
            ->modifyQueryUsing(fn ($query) => $query->with('pais'))
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->formatStateUsing(fn (?string $state) => Porto::TYPES[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('uncode')
                    ->label('UN Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('pais.nome')
                    ->label('País'),
            ])
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(Porto::TYPES),
                SelectFilter::make('ref_pais')
                    ->label('País')
                    ->options(fn () => Pais::orderBy('nome')->pluck('nome', 'paises_id'))
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Porto $record) => $record->isReferenced()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    MergeRecordsAction::make('port'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePorts::route('/'),
        ];
    }
}
