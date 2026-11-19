<?php

namespace App\Filament\Resources\Phrases;

use App\Filament\Resources\Phrases\Pages\ManagePhrases;
use App\Models\FraseCategoria;
use App\Models\FraseTransborda;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** fraseTransborda (legado): frases prontas do rastreamento (o rótulo vira o assunto do e-mail). */
class PhraseResource extends Resource
{
    protected static ?string $model = FraseTransborda::class;

    protected static ?string $modelLabel = 'Frase Rastreamento';

    protected static ?string $pluralModelLabel = 'Frases Rastreamento';

    protected static ?string $navigationLabel = 'Frases Rastreamento';

    protected static ?string $slug = 'cadastros/frases-rastreamento';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 130;

    /** security.yml do módulo (legado): credencial auxiliares. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('auxiliares');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('valor')
                    ->label('Assunto do E-mail')
                    ->maxLength(255),
                Textarea::make('frase')
                    ->label('Frase')
                    ->rows(3),
                Select::make('categoria_id')
                    ->label('Categoria')
                    ->options(fn () => FraseCategoria::query()->orderBy('nome')->pluck('nome', 'id')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('frase')
            ->columns([
                TextColumn::make('valor')
                    ->label('Assunto do E-mail')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('frase')
                    ->label('Frase')
                    ->searchable()
                    ->sortable()
                    ->limit(80),
                TextColumn::make('category.nome')
                    ->label('Categoria')
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('categoria_id')
                    ->label('Categoria')
                    ->options(fn () => FraseCategoria::query()->orderBy('nome')->pluck('nome', 'id')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePhrases::route('/'),
        ];
    }
}
