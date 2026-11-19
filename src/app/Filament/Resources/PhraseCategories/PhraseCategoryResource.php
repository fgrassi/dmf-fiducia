<?php

namespace App\Filament\Resources\PhraseCategories;

use App\Filament\Resources\PhraseCategories\Pages\ManagePhraseCategories;
use App\Models\FraseCategoria;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** fraseCategoria (legado): categorias das frases do rastreamento. */
class PhraseCategoryResource extends Resource
{
    protected static ?string $model = FraseCategoria::class;

    protected static ?string $modelLabel = 'Frase Categoria';

    protected static ?string $pluralModelLabel = 'Frases Categorias';

    protected static ?string $navigationLabel = 'Frases Categorias';

    protected static ?string $slug = 'cadastros/frases-categorias';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 131;

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
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (FraseCategoria $record) => $record->inUse()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePhraseCategories::route('/'),
        ];
    }
}
