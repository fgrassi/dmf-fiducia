<?php

namespace App\Filament\Resources\Banks;

use App\Filament\Resources\Banks\Pages\ManageBanks;
use App\Models\CmnBanks;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** bancos (legado): cadastro de bancos. */
class BankResource extends Resource
{
    protected static ?string $model = CmnBanks::class;

    protected static ?string $modelLabel = 'Banco';

    protected static ?string $pluralModelLabel = 'Bancos';

    protected static ?string $navigationLabel = 'Bancos';

    protected static ?string $slug = 'financeiro/bancos';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Cadastros';

    protected static ?int $navigationSort = 10;

    /** security.yml do módulo (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome do Banco')
                    ->required()
                    ->maxLength(100),
                TextInput::make('bank_number')
                    ->label('Número do Banco')
                    ->maxLength(10),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome do Banco')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('bank_number')
                    ->label('Número do Banco'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (CmnBanks $record) => $record->isReferenced()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBanks::route('/'),
        ];
    }
}
