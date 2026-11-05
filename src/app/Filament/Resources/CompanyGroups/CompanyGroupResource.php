<?php

namespace App\Filament\Resources\CompanyGroups;

use App\Filament\Resources\CompanyGroups\Pages\CreateCompanyGroup;
use App\Filament\Resources\CompanyGroups\Pages\EditCompanyGroup;
use App\Filament\Resources\CompanyGroups\Pages\ListCompanyGroups;
use App\Filament\Resources\CompanyGroups\RelationManagers\MembersRelationManager;
use App\Models\GrupoEmpresa;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** grupoEmpresa (legado): grupos de empresas e seus membros. O legado não exclui grupo. */
class CompanyGroupResource extends Resource
{
    protected static ?string $model = GrupoEmpresa::class;

    protected static ?string $modelLabel = 'Grupo de Empresas';

    protected static ?string $pluralModelLabel = 'Grupos de Empresas';

    protected static ?string $navigationLabel = 'Grupos de Empresas';

    protected static ?string $slug = 'cadastros/grupos-de-empresas';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 25;

    /** security.yml do módulo (legado): a lista exige a credencial admin. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('admin');
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
                TextColumn::make('membros_count')
                    ->label('Membros')
                    ->counts('membros'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanyGroups::route('/'),
            'create' => CreateCompanyGroup::route('/create'),
            'edit' => EditCompanyGroup::route('/{record}/edit'),
        ];
    }
}
