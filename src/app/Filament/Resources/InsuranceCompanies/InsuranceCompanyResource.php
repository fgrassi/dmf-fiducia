<?php

namespace App\Filament\Resources\InsuranceCompanies;

use App\Filament\Resources\InsuranceCompanies\Pages\ManageInsuranceCompanies;
use App\Models\CiaSeguro;
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

/** ciaSeguro (legado): seguradoras das reservas com seguro. */
class InsuranceCompanyResource extends Resource
{
    protected static ?string $model = CiaSeguro::class;

    protected static ?string $modelLabel = 'Cia Seguro';

    protected static ?string $pluralModelLabel = 'Cias Seguro';

    protected static ?string $navigationLabel = 'Cias Seguro';

    protected static ?string $slug = 'cadastros/cias-seguro';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 121;

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
            'index' => ManageInsuranceCompanies::route('/'),
        ];
    }
}
