<?php

namespace App\Filament\Resources\AccessLinks;

use App\Filament\Resources\AccessLinks\Pages\ManageAccessLinks;
use App\Models\LinkAcesso;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** links (legado), "Relação de Links e Senhas": sites de acesso com login e senha; excluir só marca deleted_at. */
class AccessLinkResource extends Resource
{
    protected static ?string $model = LinkAcesso::class;

    protected static ?string $modelLabel = 'Link';

    protected static ?string $pluralModelLabel = 'Links';

    protected static ?string $navigationLabel = 'Links';

    protected static ?string $slug = 'cadastros/links';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 140;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('deleted_at');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('descricao')
                    ->label('Descrição')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('link')
                    ->label('Link')
                    ->url()
                    ->required()
                    ->maxLength(255),
                TextInput::make('login')
                    ->label('Login')
                    ->required()
                    ->maxLength(50),
                TextInput::make('password')
                    ->label('Password')
                    ->required()
                    ->maxLength(50),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('descricao')
                    ->label('Descrição')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('link')
                    ->label('Link')
                    ->sortable()
                    ->url(fn (LinkAcesso $record) => $record->link, shouldOpenInNewTab: true),
                TextColumn::make('login')
                    ->label('Login')
                    ->sortable()
                    ->copyable(),
                TextColumn::make('password')
                    ->label('Password')
                    ->copyable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateDataUsing(fn (array $data) => [...$data, 'created_at' => now(), 'created_by' => auth()->id()]),
                DeleteAction::make()
                    ->using(fn (LinkAcesso $record) => $record->update(['deleted_at' => now(), 'deleted_by' => auth()->id()])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAccessLinks::route('/'),
        ];
    }
}
