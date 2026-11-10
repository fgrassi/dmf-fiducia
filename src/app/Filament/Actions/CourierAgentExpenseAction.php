<?php

namespace App\Filament\Actions;

use App\Actions\Courier\CreateAgentExpense;
use App\Models\ControleCourier;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** "Habilitar despesa de agente" (incluirDespesaAgente, legado): na lista e na página do courier. */
class CourierAgentExpenseAction
{
    public static function make(): Action
    {
        return Action::make('despesaAgente')
            ->label('Lançar despesa do agente')
            ->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn (ControleCourier $record) => ! $record->isLinked() && $record->bookings()->exists())
            ->requiresConfirmation()
            ->modalHeading('Habilitar despesa de agente?')
            ->modalDescription('Lança a nota de débito de metade do valor para o agente e trava o courier.')
            ->action(function (ControleCourier $record, CreateAgentExpense $create, Action $action) {
                try {
                    $create($record);
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Despesa do agente lançada.')->success()->send();
            });
    }
}
