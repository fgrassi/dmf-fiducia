<?php

namespace App\Filament\Resources\Tradings;

use App\Filament\Resources\Tradings\Pages\ManageTradings;
use App\Filament\Resources\Tradings\Pages\ViewTrading;
use App\Filament\Resources\Tradings\RelationManagers\BrokersRelationManager;
use App\Filament\Resources\Tradings\RelationManagers\ContactsRelationManager;
use App\Models\Trading;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * trading (legado): cadastro de tradings, com os contatos e os despachantes vinculados.
 *
 *  sem a foto (o legado a grava mas nenhuma tela a mostra).
 */
class TradingResource extends Resource
{
    protected static ?string $model = Trading::class;

    protected static ?string $modelLabel = 'Trading';

    protected static ?string $pluralModelLabel = 'Tradings';

    protected static ?string $navigationLabel = 'Tradings';

    protected static ?string $slug = 'cadastros/tradings';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 30;

    /** security.yml do módulo (legado): credencial auxiliares. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('auxiliares');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('razao_social')
                    ->label('Razão Social')
                    ->maxLength(255),
                TextInput::make('cnpj')
                    ->label('CNPJ')
                    ->maxLength(255),
                TextInput::make('domain')
                    ->label('Domínio')
                    ->helperText('Contatos com e-mail de outro domínio precisam de justificativa e de aprovação.')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detalhes da Trading')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('nome')
                            ->label('Nome'),
                        TextEntry::make('razao_social')
                            ->label('Razão Social')
                            ->placeholder('Não Informado'),
                        TextEntry::make('cnpj')
                            ->label('CNPJ')
                            ->placeholder('Não Informado'),
                        TextEntry::make('domain')
                            ->label('Domínio')
                            ->placeholder('Não Informado'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nome')
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('dtecadastro')
                    ->label('Cadastro')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make()
                    ->modalDescription('Confirmar exclusão?')
                    ->hidden(fn (Trading $record) => $record->inUse())
                    ->using(function (Trading $record) {
                        $record->contacts()->delete();
                        $record->brokers()->delete();
                        $record->delete();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ContactsRelationManager::class,
            BrokersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTradings::route('/'),
            'view' => ViewTrading::route('/{record}'),
        ];
    }
}
