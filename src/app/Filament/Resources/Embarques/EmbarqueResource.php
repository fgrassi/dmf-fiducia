<?php

namespace App\Filament\Resources\Embarques;

use App\Actions\Booking\UpdateBooking;
use App\Filament\Resources\Embarques\Pages\CreateEmbarque;
use App\Filament\Resources\Embarques\Pages\EditEmbarque;
use App\Filament\Resources\Embarques\Pages\ListEmbarques;
use App\Filament\Resources\Embarques\Pages\ListSeguros;
use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Filament\Resources\Embarques\RelationManagers\AmsAfricaRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\AmsUaeRelationManager;
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
use App\Filament\Resources\Embarques\RelationManagers\OriginDocsRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\RemarksRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\RouteRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\ScheduleRelationManager;
use App\Filament\Resources\Embarques\RelationManagers\ShippingInstructionRelationManager;
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
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Reserva (módulo booking do legado): lista, criação, edição e as abas da reserva. */
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

    /** Reserva que o legado não consegue abrir (sem tipo, ou exportação sem cliente) também não abre aqui: ver Embarque::scopeOpenableInLegacy. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->openableInLegacy();
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

    /** Mesma regra no botão "Editar", na página e na busca global. */
    public static function getEditAuthorizationResponse(Model $record): Response
    {
        $user = auth()->user();

        return $user && UpdateBooking::allows($record, $user)
            ? Response::allow()
            : Response::deny();
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
            RelationGroup::make('Demurrage e Detention', [
                DemurrageRelationManager::class,
                DetentionRelationManager::class,
            ]),
            BlsRelationManager::class,
            RelationGroup::make('Programação', [
                ScheduleRelationManager::class,
                ImoRelationManager::class,
                RouteRelationManager::class,
                MailingsRelationManager::class,
            ]),
            ShippingInstructionRelationManager::class,
            OriginDocsRelationManager::class,
            RelationGroup::make('AMS', [
                AmsAfricaRelationManager::class,
                AmsUaeRelationManager::class,
            ]),
            RelationGroup::make('Histórico', [
                TrackingRelationManager::class,
                RemarksRelationManager::class,
                LogsRelationManager::class,
            ])
                ->badge(fn (Embarque $ownerRecord): ?string => RemarksRelationManager::getBadge($ownerRecord, ViewEmbarque::class)),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmbarques::route('/'),
            'seguros' => ListSeguros::route('/seguros'),
            'create' => CreateEmbarque::route('/nova/{operacao}/{tipo}'),
            'view' => ViewEmbarque::route('/{record}'),
            'edit' => EditEmbarque::route('/{record}/editar'),
        ];
    }
}
