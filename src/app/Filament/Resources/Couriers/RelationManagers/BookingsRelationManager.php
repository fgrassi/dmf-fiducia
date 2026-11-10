<?php

namespace App\Filament\Resources\Couriers\RelationManagers;

use App\Actions\Courier\AddCourierBooking;
use App\Actions\Courier\RemoveCourierBooking;
use App\Models\CtrCourierEmbarque;
use App\Models\Embarque;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** "Vinculado a Reserva" (showSuccess do legado): inclui e remove reservas enquanto o courier não tem nota vinculada. */
class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    protected static ?string $title = 'Vinculado a Reserva';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $locked = fn () => $this->getOwnerRecord()->isLinked();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('booking')->orderBy('ctr_courier_embarque_id'))
            ->paginated(false)
            ->emptyStateHeading('Nenhuma reserva vinculada.')
            ->columns([
                TextColumn::make('booking.booking')
                    ->label('Embarque'),
                TextColumn::make('booking.house_bl')
                    ->label('House BL'),
                TextColumn::make('observacao')
                    ->label('Observação')
                    ->placeholder('-')
                    ->wrap(),
            ])
            ->headerActions([
                Action::make('incluir')
                    ->label('Vincular Reserva')
                    ->icon(Heroicon::OutlinedPlus)
                    ->hidden($locked)
                    ->modalWidth('md')
                    ->schema([
                        Select::make('booking_id')
                            ->label('Embarque')
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Embarque::query()
                                ->where('booking', 'like', "%{$search}%")
                                ->orderByDesc('embarque_id')
                                ->limit(30)
                                ->pluck('booking', 'embarque_id'))
                            ->getOptionLabelUsing(fn ($value) => Embarque::find($value)?->booking),
                        Textarea::make('observacao')
                            ->label('Observação')
                            ->rows(4),
                    ])
                    ->action(function (array $data, AddCourierBooking $add, Action $action) {
                        try {
                            $add($this->getOwnerRecord(), (int) $data['booking_id'], $data['observacao'] ?? null);
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }

                        Notification::make()->title('Embarque vinculado.')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('remover')
                    ->label('Remover')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->hidden($locked)
                    ->requiresConfirmation()
                    ->modalHeading('Confirmar exclusão?')
                    ->action(function (CtrCourierEmbarque $record, RemoveCourierBooking $remove, Action $action) {
                        try {
                            $remove($record);
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }

                        Notification::make()->title('Embarque Removido com Sucesso')->success()->send();
                    }),
            ]);
    }
}
