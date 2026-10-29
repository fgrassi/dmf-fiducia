<?php

namespace App\Filament\Resources\Embarques;

use App\Filament\Resources\Embarques\Pages\ListEmbarques;
use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Filament\Resources\Embarques\RelationManagers\ExpensesRelationManager;
use App\Filament\Resources\Embarques\Schemas\EmbarqueInfolist;
use App\Filament\Resources\Embarques\Tables\EmbarquesTable;
use App\Models\Embarque;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Reserva (módulo booking do legado), por enquanto só para a aba de despesas (D1 de despesas-embarque.md). */
class EmbarqueResource extends Resource
{
    protected static ?string $model = Embarque::class;

    protected static ?string $modelLabel = 'Reserva';

    protected static ?string $pluralModelLabel = 'Reservas';

    protected static ?string $slug = 'booking/reservas';

    protected static ?string $recordTitleAttribute = 'booking';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Booking';

    protected static ?int $navigationSort = 10;

    /** security.yml do módulo booking (legado): credencial booking. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('booking');
    }

    public static function infolist(Schema $schema): Schema
    {
        return EmbarqueInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmbarquesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ExpensesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmbarques::route('/'),
            'view' => ViewEmbarque::route('/{record}'),
        ];
    }
}
