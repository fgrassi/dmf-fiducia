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

/**
 * listPasta (legado): pastas mensais das capas de contabilidade. No legado não há menu para ela (só a URL
 * `/capasContabilidade/listPasta`); fica em Config IT até a chefia decidir se mantém.
 */
class CoverFolderResource extends Resource
{
    protected static ?string $model = CapaProcessoArquivo::class;

    protected static ?string $modelLabel = 'Pasta';

    protected static ?string $pluralModelLabel = 'Pastas de Capas';

    protected static ?string $navigationLabel = 'Pastas de Capas';

    protected static ?string $slug = 'financeiro/pastas-de-capas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static string|UnitEnum|null $navigationGroup = 'Config IT';

    protected static ?int $navigationSort = 26;

    /** O legado usava `financial` ou `director` (só pela URL); agora é do TI (credencial `it`; o admin sempre passa). */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('it');
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
                    ->label('Pasta'),
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
