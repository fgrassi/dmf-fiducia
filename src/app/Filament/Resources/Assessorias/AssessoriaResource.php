<?php

namespace App\Filament\Resources\Assessorias;

use App\Filament\Resources\Assessorias\Pages\ManageAssessorias;
use App\Filament\Resources\Assessorias\Pages\ViewAssessoria;
use App\Filament\Resources\Assessorias\RelationManagers\ContactsRelationManager;
use App\Models\Assessoria;
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
 * assessoria (legado): cadastro de assessorias, com os contatos e os despachantes vinculados.
 *
 *  sem a foto (o legado a grava mas nenhuma tela a mostra).
 */
class AssessoriaResource extends Resource
{
    protected static ?string $model = Assessoria::class;

    protected static ?string $modelLabel = 'Assessoria';

    protected static ?string $pluralModelLabel = 'Assessorias';

    protected static ?string $navigationLabel = 'Assessorias';

    protected static ?string $slug = 'cadastros/assessorias';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 31;

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
                Section::make('Detalhes da Assessoria')
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
                    ->hidden(fn (Assessoria $record) => $record->inUse())
                    ->using(function (Assessoria $record) {
                        $record->contacts()->delete();
                        $record->delete();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ContactsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAssessorias::route('/'),
            'view' => ViewAssessoria::route('/{record}'),
        ];
    }
}
