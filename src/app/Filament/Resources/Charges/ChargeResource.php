<?php

namespace App\Filament\Resources\Charges;

use App\Filament\Resources\Charges\Pages\ListCharges;
use App\Filament\Resources\Charges\Pages\ViewCharge;
use App\Filament\Resources\Charges\Schemas\ChargeInfolist;
use App\Filament\Resources\Charges\Tables\ChargesTable;
use App\Models\FinCharges;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class ChargeResource extends Resource
{
    protected static ?string $model = FinCharges::class;

    protected static ?string $modelLabel = 'Cobrança';

    protected static ?string $pluralModelLabel = 'Central de Cobranças';

    protected static string|BackedEnum|null $navigationIcon = 'fas-money-bill-wave';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 10;

    public static function infolist(Schema $schema): Schema
    {
        return ChargeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChargesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCharges::route('/'),
            'view' => ViewCharge::route('/{record}'),
        ];
    }
}
