<?php

namespace App\Filament\Resources\OtherPaymentOrders;

use App\Filament\Resources\OtherPaymentOrders\Pages\ListOtherPaymentOrders;
use App\Filament\Resources\OtherPaymentOrders\Pages\ViewOtherPaymentOrder;
use App\Filament\Resources\OtherPaymentOrders\RelationManagers\InstructionsRelationManager;
use App\Filament\Resources\OtherPaymentOrders\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\OtherPaymentOrders\RelationManagers\TaPaymentsRelationManager;
use App\Filament\Resources\OtherPaymentOrders\Schemas\OtherPaymentOrderInfolist;
use App\Filament\Resources\OtherPaymentOrders\Tables\OtherPaymentOrdersTable;
use App\Models\OrdemPagamento;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Módulo ordemPagamentoOutros do legado: ordens de pagamento a despachante, cia aérea e seguro. */
class OtherPaymentOrderResource extends Resource
{
    protected static ?string $model = OrdemPagamento::class;

    protected static ?string $modelLabel = 'Ordem de Pagamento (Outros)';

    protected static ?string $pluralModelLabel = 'Ordens de Pagamento (Outros)';

    protected static ?string $slug = 'financeiro/ordens-de-pagamento-outros';

    protected static ?string $navigationLabel = 'Ordem de pagamento (outros)';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Ordem de Pagamento';

    protected static ?int $navigationSort = 2;

    /** security.yml do módulo ordemPagamentoOutros (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public static function infolist(Schema $schema): Schema
    {
        return OtherPaymentOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OtherPaymentOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            InstructionsRelationManager::class,
            TaPaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOtherPaymentOrders::route('/'),
            'view' => ViewOtherPaymentOrder::route('/{record}'),
        ];
    }
}
