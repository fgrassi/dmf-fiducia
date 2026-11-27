<?php

namespace App\Filament\Resources\Embarques\Pages;

use App\Actions\Booking\Creation\CreateBooking;
use App\Enums\OperationType;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Resources\Embarques\Pages\Concerns\ConfirmsPickupLocations;
use App\Filament\Resources\Embarques\Schemas\EmbarqueForm;
use App\Models\Embarque;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/** booking/create, createExpo, createImpoAereo e createExpoAereo (legado). */
class CreateEmbarque extends CreateRecord
{
    use ConfirmsPickupLocations;

    protected static string $resource = EmbarqueResource::class;

    protected static bool $canCreateAnother = false;

    #[Locked]
    public string $imporExpor;

    #[Locked]
    public string $modal;

    public function mount(string $operacao = 'i', string $tipo = 'm'): void
    {
        $only = auth()->user()->onlyOperation();

        abort_unless(in_array($operacao, ['i', 'e'], true) && in_array($tipo, ['m', 'a'], true), 404);
        abort_if($only && $only !== $operacao, 403);

        $this->imporExpor = $operacao;
        $this->modal = $tipo;

        parent::mount();
    }

    /** "Nova reserva: Importação marítima". */
    public static function label(string $operation, string $mode): string
    {
        return OperationType::labelFor($operation).' '.mb_strtolower(Embarque::TYPES[$mode]);
    }

    public function getTitle(): string
    {
        return 'Nova reserva: '.self::label($this->imporExpor, $this->modal);
    }

    public function form(Schema $schema): Schema
    {
        return (new EmbarqueForm($this->imporExpor, $this->modal))->configure($schema);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CreateBooking::class)($data, $this->imporExpor, $this->modal, auth()->user());
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);
        }
    }

    protected function getCreateFormAction(): Action
    {
        $action = parent::getCreateFormAction();

        return $this->imporExpor === 'e' && $this->modal === 'm'
            ? $this->confirmPickupLocations($action, fn () => $this->create())
            : $action;
    }

    protected function getRedirectUrl(): string
    {
        return EmbarqueResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
