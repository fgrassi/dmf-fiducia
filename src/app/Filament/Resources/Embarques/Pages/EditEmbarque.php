<?php

namespace App\Filament\Resources\Embarques\Pages;

use App\Actions\Booking\UpdateBooking;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Resources\Embarques\Pages\Concerns\ConfirmsPickupLocations;
use App\Filament\Resources\Embarques\Schemas\EmbarqueForm;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

/** booking/edit, editAereo e editExpo (legado). */
class EditEmbarque extends EditRecord
{
    use ConfirmsPickupLocations;

    protected static string $resource = EmbarqueResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Editar reserva '.$this->getRecord()->booking;
    }

    public function form(Schema $schema): Schema
    {
        return $this->bookingForm()->configure($schema);
    }

    /** As abas da reserva ficam só na visualização. */
    public function getRelationManagers(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->bookingForm()->state($data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(UpdateBooking::class)($record, $data, auth()->user());
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);
        }
    }

    protected function getSaveFormAction(): Action
    {
        $action = parent::getSaveFormAction();
        $booking = $this->getRecord();

        return $booking->impor_expor === 'e' && $booking->tipo_embarque === 'm'
            ? $this->confirmPickupLocations($action, fn () => $this->save())
            : $action;
    }

    protected function getRedirectUrl(): string
    {
        return EmbarqueResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    private function bookingForm(): EmbarqueForm
    {
        $booking = $this->getRecord();

        return new EmbarqueForm($booking->impor_expor, $booking->tipo_embarque, $booking);
    }
}
