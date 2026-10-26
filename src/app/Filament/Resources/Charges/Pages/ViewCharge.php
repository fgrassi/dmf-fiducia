<?php

namespace App\Filament\Resources\Charges\Pages;

use App\Actions\Cobranca\Rates\DeleteCustomRate;
use App\Filament\Resources\Charges\ChargeResource;
use App\Services\Cobranca\ChargeDetails;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewCharge extends ViewRecord
{
    protected static string $resource = ChargeResource::class;

    /** Remoção de cotação negociada, disparada pela lixeira de cada linha da tabela. */
    public function removerCotacaoAction(): Action
    {
        return Action::make('removerCotacao')
            ->requiresConfirmation()
            ->modalHeading('Remover Cotação Negociada')
            ->modalDescription('Tem certeza que deseja remover este registro?')
            ->color('danger')
            ->action(function (array $arguments) {
                app(DeleteCustomRate::class)($this->getRecord(), (int) $arguments['id']);
                Notification::make()->title('Cotação negociada removida.')->success()->send();
            });
    }

    public function getHeading(): string
    {
        return 'Cobrança';
    }

    public function getSubheading(): string
    {
        return app(ChargeDetails::class)->heading($this->getRecord());
    }
}
