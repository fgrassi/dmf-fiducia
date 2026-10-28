<?php

namespace App\Filament\Resources\ExpenseDueTos;

use App\Filament\Resources\ExpenseDueTos\Pages\ManageExpenseDueTos;
use App\Models\FinExpensesValuesDueTo;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** finExpensesDueTo (legado): "Due To" das cotações e despesas (nome e imagem, guardada em uploads/images do tracking). */
class ExpenseDueToResource extends Resource
{
    protected static ?string $model = FinExpensesValuesDueTo::class;

    protected static ?string $modelLabel = 'Due To';

    protected static ?string $pluralModelLabel = 'Due To';

    protected static ?string $navigationLabel = 'Due To';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static string|UnitEnum|null $navigationGroup = 'Config IT';

    protected static ?int $navigationSort = 25;

    /** security.yml do módulo (legado): credencial admin (o admin sempre passa) e, aqui, também `it`. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('it');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                FileUpload::make('image')
                    ->label('Imagem')
                    ->image()
                    ->disk('tracking_uploads')
                    ->directory('images')
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
                ImageColumn::make('image')
                    ->label('Imagem')
                    ->disk('tracking_uploads')
                    ->imageSize(40),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageExpenseDueTos::route('/'),
        ];
    }
}
