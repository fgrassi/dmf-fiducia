<?php

namespace App\Filament\Resources\Embarques;

use App\Filament\Resources\Embarques\Pages\ListEmbarques;
use App\Filament\Resources\Embarques\Pages\ListSeguros;
use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Filament\Resources\Embarques\RelationManagers\ArrivalConfirmationsRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\BlsRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\CommissionsRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\CreditNotesRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\DebitNotesRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\DemurrageRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\DepartureConfirmationsRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\DetentionRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\ExpensesRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\FreightInvoicesRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\ImoRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\LogsRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\MailingsRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\RemarksRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\RouteRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\ScheduleRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\TrackingRelationManager;
use App\Filament\Resources\Embarques\Schemas\EmbarqueInfolist;
use App\Filament\Resources\Embarques\Tables\EmbarquesTable;
use App\Models\Embarque;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Resources\RelationManagers\RelationGroup;
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

    protected static ?string $navigationLabel = 'Lista de Bookings';

    protected static ?string $slug = 'booking/reservas';

    protected static ?string $recordTitleAttribute = 'booking';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Reserva';

    protected static ?string $navigationParentItem = 'Booking';

    protected static ?int $navigationSort = 10;

    /** security.yml do módulo booking (legado): credencial booking. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('booking');
    }

    /** "Lista de Seguros" (seguro/list do legado): lista própria das reservas de seguro, no menu Booking. */
    public static function getNavigationItems(): array
    {
        return [
            ...parent::getNavigationItems(),
            NavigationItem::make('Lista de Seguros')
                ->group('Reserva')
                ->parentItem('Booking')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->sort(11)
                ->url(static::getUrl('seguros'))
                ->isActiveWhen(fn () => request()->routeIs(static::getRouteBaseName().'.seguros'))
                ->visible(static::canAccess()),
        ];
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
            RelationGroup::make('Despesas', [
                ExpensesRelationManager::class,
                CommissionsRelationManager::class,
            ]),
            RelationGroup::make('Notas', [
                DebitNotesRelationManager::class,
                CreditNotesRelationManager::class,
            ]),
            RelationGroup::make('Confirmação', [
                DepartureConfirmationsRelationManager::class,
                ArrivalConfirmationsRelationManager::class,
            ]),
            FreightInvoicesRelationManager::class,
            DemurrageRelationManager::class,
            DetentionRelationManager::class,
            BlsRelationManager::class,
            TrackingRelationManager::class,
            ScheduleRelationManager::class,
            ImoRelationManager::class,
            RouteRelationManager::class,
            MailingsRelationManager::class,
            RemarksRelationManager::class,
            LogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmbarques::route('/'),
            'seguros' => ListSeguros::route('/seguros'),
            'view' => ViewEmbarque::route('/{record}'),
        ];
    }
}
