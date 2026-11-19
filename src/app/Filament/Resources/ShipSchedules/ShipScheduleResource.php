<?php

namespace App\Filament\Resources\ShipSchedules;

use App\Filament\Resources\ShipSchedules\Pages\ManageShipSchedules;
use App\Filament\Support\ReportFields;
use App\Models\ProgramacaoNavio;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** programacaoNavio (legado): programação de saídas dos navios entre portos, em ordem de ETD. */
class ShipScheduleResource extends Resource
{
    protected static ?string $model = ProgramacaoNavio::class;

    protected static ?string $modelLabel = 'Programação de Navio';

    protected static ?string $pluralModelLabel = 'Programação de Navios';

    protected static ?string $navigationLabel = 'Programação de Navios';

    protected static ?string $slug = 'cadastros/programacao-navios';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 150;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('joint')
                    ->label('Joint')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                ReportFields::ship('navio_id', 'Navio')
                    ->required(),
                TextInput::make('vg')
                    ->label('VG')
                    ->required()
                    ->maxLength(50),
                ReportFields::port('porto_origem_id', 'Porto de Origem')
                    ->required(),
                ReportFields::port('porto_destino_id', 'Porto de Destino')
                    ->required(),
                DatePicker::make('saida_prevista')
                    ->label('Saída prevista')
                    ->required(),
                DatePicker::make('chegada_prevista')
                    ->label('Chegada prevista')
                    ->required()
                    ->afterOrEqual('saida_prevista'),
                TextInput::make('via')
                    ->label('Via')
                    ->required()
                    ->maxLength(255),
                Textarea::make('obs')
                    ->label('Observações')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('saida_prevista')
            ->columns([
                TextColumn::make('joint')
                    ->label('Joint')
                    ->searchable(),
                TextColumn::make('originPort.nome')
                    ->label('POO'),
                TextColumn::make('destinationPort.nome')
                    ->label('POD'),
                TextColumn::make('ship.nome')
                    ->label('Navio'),
                TextColumn::make('vg')
                    ->label('VG'),
                TextColumn::make('via')
                    ->label('Via'),
                TextColumn::make('saida_prevista')
                    ->label('ETD')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('chegada_prevista')
                    ->label('ETA')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('tt')
                    ->label('TT')
                    ->state(fn (ProgramacaoNavio $record) => $record->transitDays()),
                TextColumn::make('obs')
                    ->label('OBS')
                    ->limit(60),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageShipSchedules::route('/'),
        ];
    }
}
