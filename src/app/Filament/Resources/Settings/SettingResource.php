<?php

namespace App\Filament\Resources\Settings;

use App\Filament\Resources\Settings\Pages\ManageSettings;
use App\Models\Configuracoes;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** configuracao (legado): pares identificação/valor lidos pelo sistema (inclusive a trava das rotinas de cobrança). */
class SettingResource extends Resource
{
    protected static ?string $model = Configuracoes::class;

    protected static ?string $modelLabel = 'Configuração';

    protected static ?string $pluralModelLabel = 'Configurações do Sistema';

    protected static ?string $navigationLabel = 'Configurações do Sistema';

    protected static ?string $slug = 'configuracoes/sistema';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 20;

    /** security.yml do módulo (legado): credencial admin ou financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['admin', 'financial']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('identificacao')
                    ->label('Identificação')
                    ->maxLength(255),
                TextInput::make('valor')
                    ->label('Valor')
                    ->maxLength(255),
                TextInput::make('descricao')
                    ->label('Descrição')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('descricao')
            ->columns([
                TextColumn::make('descricao')
                    ->label('Descrição')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('valor')
                    ->label('Valor'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSettings::route('/'),
        ];
    }
}
