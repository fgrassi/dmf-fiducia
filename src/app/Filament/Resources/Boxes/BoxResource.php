<?php

namespace App\Filament\Resources\Boxes;

use App\Filament\Resources\Boxes\Pages\ListBoxes;
use App\Filament\Resources\Boxes\Pages\ViewBox;
use App\Filament\Resources\Boxes\RelationManagers\BookingsRelationManager;
use App\Filament\Resources\Boxes\Schemas\BoxInfolist;
use App\Filament\Resources\Boxes\Tables\BoxesTable;
use App\Models\ControleCaixa;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Módulo controleCaixa do legado: caixas de arquivo dos processos encerrados. */
class BoxResource extends Resource
{
    protected static ?string $model = ControleCaixa::class;

    protected static ?string $modelLabel = 'Caixa';

    protected static ?string $pluralModelLabel = 'Controle de Caixas';

    protected static ?string $navigationLabel = 'Controle de Caixas';

    protected static ?string $slug = 'financeiro/controle-de-caixas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 50;

    /** security.yml do módulo controleCaixa (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public static function infolist(Schema $schema): Schema
    {
        return BoxInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BoxesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            BookingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBoxes::route('/'),
            'view' => ViewBox::route('/{record}'),
        ];
    }
}
