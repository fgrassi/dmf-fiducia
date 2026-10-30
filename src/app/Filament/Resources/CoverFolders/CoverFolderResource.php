<?php

namespace App\Filament\Resources\CoverFolders;

use App\Filament\Resources\CoverFolders\Pages\ManageCoverFolders;
use App\Models\CapaProcessoArquivo;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** listPasta (legado): pastas mensais das capas de contabilidade. */
class CoverFolderResource extends Resource
{
    protected static ?string $model = CapaProcessoArquivo::class;

    protected static ?string $modelLabel = 'Caixa';

    protected static ?string $pluralModelLabel = 'Controle de Caixas';

    protected static ?string $navigationLabel = 'Controle de Caixas';

    protected static ?string $slug = 'financeiro/controle-de-caixas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Capas';

    protected static ?int $navigationSort = 2;

    /** security.yml do módulo capasContabilidade (legado): credencial financial ou director. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['financial', 'director']);
    }

    /** O legado edita mês, ano e status direto (common/editCampo) e não muda o nome da pasta. */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('mes')
                    ->label('Mês')
                    ->options(array_combine(range(1, 12), range(1, 12)))
                    ->required(),
                Select::make('ano')
                    ->label('Ano')
                    ->options(fn () => array_combine($years = range((int) date('Y'), 2015), $years))
                    ->required(),
                Select::make('status')
                    ->label('Status')
                    ->options(CapaProcessoArquivo::STATUSES)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn ($query) => $query->orderByDesc('ano')->orderByDesc('mes'))
            ->columns([
                TextColumn::make('nome')
                    ->label('Caixa'),
                TextColumn::make('mes')
                    ->label('Mês'),
                TextColumn::make('ano')
                    ->label('Ano'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => CapaProcessoArquivo::STATUSES[(int) $state] ?? $state)
                    ->color(fn ($state) => (int) $state === 1 ? 'success' : 'gray'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCoverFolders::route('/'),
        ];
    }
}
