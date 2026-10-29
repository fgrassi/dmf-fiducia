<?php

namespace App\Filament\Resources\PaymentOrders;

use App\Filament\Resources\PaymentOrders\Pages\ListPaymentOrders;
use App\Filament\Resources\PaymentOrders\Pages\ViewPaymentOrder;
use App\Filament\Resources\PaymentOrders\RelationManagers\CustomRatesRelationManager;
use App\Filament\Resources\PaymentOrders\RelationManagers\InstructionsRelationManager;
use App\Filament\Resources\PaymentOrders\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\PaymentOrders\RelationManagers\ReceiptsRelationManager;
use App\Filament\Resources\PaymentOrders\Schemas\PaymentOrderInfolist;
use App\Filament\Resources\PaymentOrders\Tables\PaymentOrdersTable;
use App\Models\FinPaymentOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Módulo ordemPagamento do legado: ordens de pagamento a armadores. */
class PaymentOrderResource extends Resource
{
    protected static ?string $model = FinPaymentOrder::class;

    protected static ?string $modelLabel = 'Ordem de Pagamento';

    protected static ?string $pluralModelLabel = 'Ordens de Pagamento';

    protected static ?string $slug = 'financeiro/ordens-de-pagamento';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 30;

    /** security.yml do módulo ordemPagamento (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            CustomRatesRelationManager::class,
            InstructionsRelationManager::class,
            ReceiptsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentOrders::route('/'),
            'view' => ViewPaymentOrder::route('/{record}'),
        ];
    }
}
