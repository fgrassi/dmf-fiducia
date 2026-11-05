<?php

namespace App\Filament\Resources\Airlines;

use App\Filament\Resources\Airlines\Pages\ManageAirlines;
use App\Models\CiaAerea;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** ciaAerea (legado): companhias aéreas das reservas aéreas. */
class AirlineResource extends Resource
{
    protected static ?string $model = CiaAerea::class;

    protected static ?string $modelLabel = 'Cia Aérea';

    protected static ?string $pluralModelLabel = 'Cias Aéreas';

    protected static ?string $navigationLabel = 'Cias Aéreas';

    protected static ?string $slug = 'cadastros/cias-aereas';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 120;

    /** security.yml do módulo (legado): credencial auxiliares. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('auxiliares');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('cnpj')
                    ->label('CNPJ')
                    ->maxLength(20),
                Textarea::make('dados_bancarios')
                    ->label('Dados Bancários')
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('cnpj')
                    ->label('CNPJ'),
                TextColumn::make('dados_bancarios')
                    ->label('Dados Bancários')
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
            'index' => ManageAirlines::route('/'),
        ];
    }
}
