<?php

namespace App\Filament\Resources\Couriers;

use App\Filament\Resources\Couriers\Pages\ListCouriers;
use App\Filament\Resources\Couriers\Pages\ViewCourier;
use App\Filament\Resources\Couriers\RelationManagers\BookingsRelationManager;
use App\Filament\Resources\Couriers\RelationManagers\NamesRelationManager;
use App\Filament\Resources\Couriers\Schemas\CourierInfolist;
use App\Filament\Resources\Couriers\Tables\CouriersTable;
use App\Models\ControleCourier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Módulo lancamentoCourier do legado: courier recebido, nota e despesa para o agente. */
class CourierResource extends Resource
{
    protected static ?string $model = ControleCourier::class;

    protected static ?string $modelLabel = 'Courrier';

    protected static ?string $pluralModelLabel = 'Controle Courrier';

    protected static ?string $navigationLabel = 'Controle Courrier';

    protected static ?string $slug = 'cadastros/controle-courrier';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 30;

    /** security.yml do módulo (legado): credencial auxiliares. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('auxiliares');
    }

    public static function infolist(Schema $schema): Schema
    {
        return CourierInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CouriersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            BookingsRelationManager::class,
            NamesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCouriers::route('/'),
            'view' => ViewCourier::route('/{record}'),
        ];
    }
}
