<?php

namespace App\Filament\Clusters;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use UnitEnum;

/** Configuração Despesa: as abas Fornecedores, Centros de Custo, Categorias e Vencimentos do módulo despesa (legado). */
class ExpenseSettings extends Cluster
{
    protected static ?string $navigationLabel = 'Configuração Despesa';

    protected static ?string $slug = 'financeiro/despesas/configuracao';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Despesas';

    protected static ?int $navigationSort = 2;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
