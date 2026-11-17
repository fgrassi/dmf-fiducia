<?php

namespace App\Filament\Resources\Bls\Pages;

use App\Actions\Booking\Bls\BlDefaults;
use App\Filament\Resources\Bls\BlResource;
use App\Filament\Resources\Bls\Pages\Concerns\ManagesBl;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Novo BL (editAprovacaoBl sem id, legado): já vem com o que a reserva sabe. */
class CreateBl extends CreateRecord
{
    use ManagesBl;

    protected static string $resource = BlResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return 'Novo BL';
    }

    protected function fillForm(): void
    {
        $this->form->fill([...app(BlDefaults::class)($this->booking()), 'containers' => []]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        return $this->saveBl(null, $data);
    }

    public function createAndFinalize(): void
    {
        $this->finalize = true;
        $this->create();
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->label('Salvar'),
            $this->finalizeAction('createAndFinalize'),
            $this->cancelAction(),
        ];
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'BL salvo';
    }
}
