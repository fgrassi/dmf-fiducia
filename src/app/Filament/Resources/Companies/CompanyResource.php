<?php

namespace App\Filament\Resources\Companies;

use App\Filament\Resources\Companies\Pages\CompanyBookings;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\FollowUps;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ManageCompanyContacts;
use App\Filament\Resources\Companies\Schemas\CompanyForm;
use App\Filament\Resources\Companies\Tables\CompaniesTable;
use App\Models\Pessoa;
use BackedEnum;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/** pessoa (legado): cadastro de empresas (clientes, agentes, despachantes...). Sem as abas de contatos, follow ups, etc. */
class CompanyResource extends Resource
{
    protected static ?string $model = Pessoa::class;

    protected static ?string $modelLabel = 'Empresa';

    protected static ?string $pluralModelLabel = 'Empresas';

    protected static ?string $navigationLabel = 'Empresas';

    protected static ?string $slug = 'cadastros/empresas';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 20;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    /** security.yml do módulo (legado): credencial empresa. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('empresa');
    }

    public static function form(Schema $schema): Schema
    {
        return CompanyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompaniesTable::configure($table);
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            EditCompany::class,
            FollowUps::class,
            ManageCompanyContacts::class,
            CompanyBookings::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'edit' => EditCompany::route('/{record}/edit'),
            'follow-ups' => FollowUps::route('/{record}/follow-ups'),
            'contacts' => ManageCompanyContacts::route('/{record}/contacts'),
            'bookings' => CompanyBookings::route('/{record}/bookings'),
        ];
    }
}
