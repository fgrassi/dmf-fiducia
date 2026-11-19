<?php

namespace App\Filament\Resources\TransitTimes;

use App\Filament\Resources\TransitTimes\Pages\ManageTransitTimes;
use App\Filament\Support\ReportFields;
use App\Models\TransitTime;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * transitTime (legado), "Cadastro de Transit Times": o TT combinado por cliente, armador e trecho. Como no legado, só
 * inclui e lista (não edita nem exclui).
 */
class TransitTimeResource extends Resource
{
    protected static ?string $model = TransitTime::class;

    protected static ?string $modelLabel = 'Transit Time';

    protected static ?string $pluralModelLabel = 'Transit Time';

    protected static ?string $navigationLabel = 'Transit Time';

    protected static ?string $slug = 'cadastros/transit-time';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 170;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ReportFields::person('pessoa_id', 'Cliente')
                    ->required(),
                ReportFields::carrier('armador_id')
                    ->required(),
                ReportFields::port('porto_origem_id', 'Porto de Origem')
                    ->required(),
                ReportFields::port('porto_destino_id', 'Porto de Destino')
                    ->required(),
                TextInput::make('transit_time')
                    ->label('Transit Time')
                    ->required()
                    ->integer()
                    ->minValue(0),
                TextInput::make('transit_time_seguranca')
                    ->label('Transit Time Segurança')
                    ->required()
                    ->integer()
                    ->minValue(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('transit_time_id', 'desc')
            ->columns([
                TextColumn::make('person.nome')
                    ->label('Cliente')
                    ->searchable(),
                TextColumn::make('carrier.nome')
                    ->label('Armador'),
                TextColumn::make('originPort.nome')
                    ->label('Origem'),
                TextColumn::make('destinationPort.nome')
                    ->label('Destino'),
                TextColumn::make('transit_time')
                    ->label('TT')
                    ->sortable(),
                TextColumn::make('transit_time_seguranca')
                    ->label('TT Segurança')
                    ->sortable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTransitTimes::route('/'),
        ];
    }
}
