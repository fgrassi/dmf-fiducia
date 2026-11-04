<?php

namespace App\Filament\Resources\Applications;

use App\Filament\Resources\Applications\Pages\ListApplications;
use App\Filament\Resources\Applications\Pages\ManageApplicationCompanies;
use App\Models\Aplicacao;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** acessoAplicacao (legado): aplicações (Gerenciador e CRM) e as empresas com acesso a cada uma. */
class ApplicationResource extends Resource
{
    /** Aplicacao::idsVisiveis (legado). */
    private const VISIBLE = [1, 4];

    protected static ?string $model = Aplicacao::class;

    protected static ?string $modelLabel = 'Aplicação';

    protected static ?string $pluralModelLabel = 'Acesso às Aplicações';

    protected static ?string $navigationLabel = 'Acesso às Aplicações';

    protected static ?string $slug = 'configuracoes/acesso-aplicacoes';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationParentItem = 'Configurações Usuários';

    protected static ?int $navigationSort = 10;

    /** security.yml do módulo (legado): credencial admin. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('admin');
    }

    /** Só as aplicações visíveis: as outras dão 404 também na tela de empresas. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('aplicacao_id', self::VISIBLE);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('companyLinks'))
            ->defaultSort('nome')
            ->columns([
                TextColumn::make('nome')
                    ->label('Aplicação')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company_links_count')
                    ->label('Empresas vinculadas'),
            ])
            ->recordActions([
                Action::make('companies')
                    ->label('Empresas')
                    ->icon('heroicon-o-building-office-2')
                    ->url(fn (Aplicacao $record) => static::getUrl('companies', ['record' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApplications::route('/'),
            'companies' => ManageApplicationCompanies::route('/{record}/companies'),
        ];
    }
}
