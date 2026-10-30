<?php

namespace App\Filament\Resources\AccountingCovers;

use App\Filament\Resources\AccountingCovers\Pages\ListAccountingCovers;
use App\Filament\Resources\AccountingCovers\Tables\AccountingCoversTable;
use App\Models\CapaProcesso;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Módulo capasContabilidade do legado: capa contábil por processo. */
class AccountingCoverResource extends Resource
{
    protected static ?string $model = CapaProcesso::class;

    protected static ?string $modelLabel = 'Capa de Contabilidade';

    protected static ?string $pluralModelLabel = 'Capas de Contabilidade';

    protected static ?string $slug = 'financeiro/capas-de-contabilidade';

    protected static ?string $navigationLabel = 'Capas de Contabilidade';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Capas';

    protected static ?int $navigationSort = 1;

    /** security.yml do módulo (legado): credencial financial ou director. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['financial', 'director']);
    }

    public static function table(Table $table): Table
    {
        return AccountingCoversTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountingCovers::route('/'),
        ];
    }
}
