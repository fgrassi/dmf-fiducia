<?php

namespace App\Filament\Resources\Embarques\Pages\Concerns;

use Closure;
use Filament\Actions\Action;

/** O legado pede para conferir os locais de retirada e entrega antes de salvar a exportação marítima. */
trait ConfirmsPickupLocations
{
    private function confirmPickupLocations(Action $action, Closure $save): Action
    {
        return $action
            ->submit(null)
            ->requiresConfirmation()
            ->modalHeading('Os locais de retirada e entrega estão corretos?')
            ->modalDescription(fn () => 'Retirada: '.(($this->data['local_retirada'] ?? null) ?: 'não informado').' · Entrega: '.(($this->data['local_entrega'] ?? null) ?: 'não informado'))
            ->action($save);
    }
}
