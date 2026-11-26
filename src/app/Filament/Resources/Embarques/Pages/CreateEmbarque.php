<?php

namespace App\Filament\Resources\Embarques\Pages;

use App\Actions\Booking\Creation\CreateBooking;
use App\Enums\OperationType;
use App\Filament\Resources\Embarques\EmbarqueResource;
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

    /** O legado pede para conferir os locais de retirada e entrega antes de confirmar a exportação marítima. */
    protected function getCreateFormAction(): Action
    {
        $action = parent::getCreateFormAction();

        if ($this->imporExpor !== 'e' || $this->modal !== 'm') {
            return $action;
        }

        return $action
            ->submit(null)
            ->requiresConfirmation()
            ->modalHeading('Os locais de retirada e entrega estão corretos?')
            ->modalDescription(fn () => 'Retirada: '.(($this->data['local_retirada'] ?? null) ?: 'não informado').' · Entrega: '.(($this->data['local_entrega'] ?? null) ?: 'não informado'))
            ->action(fn () => $this->create());
    }

    protected function getRedirectUrl(): string
    {
        return EmbarqueResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
