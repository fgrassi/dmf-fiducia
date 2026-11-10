<?php

namespace App\Filament\Actions;

use App\Actions\Courier\CourierPdf;
use App\Actions\Courier\CreateAgentExpense;
use App\Models\ControleCourier;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Ícone de PDF do courier (legado): com reserva e sem nota vinculada, pergunta "Gerar PDF e Habilitar despesa de agente?",
 * lança a despesa e baixa o PDF. Nos outros casos só baixa o PDF.
 *
 * Como no legado, o PDF sai mesmo se a despesa não puder ser lançada; a diferença é o aviso do motivo.
 */
class CourierPdfAction
{
    public static function make(): Action
    {
        $createsExpense = fn (ControleCourier $record) => ! $record->isLinked() && $record->bookings()->exists();

        return Action::make('pdf')
            ->label('PDF')
            ->icon(Heroicon::OutlinedPrinter)
            ->requiresConfirmation($createsExpense)
            ->modalHeading('Gerar PDF e Habilitar despesa de agente?')
            ->action(function (ControleCourier $record, CreateAgentExpense $create, CourierPdf $pdf) use ($createsExpense) {
                if ($createsExpense($record)) {
                    try {
                        $create($record);
                        Notification::make()->title('Despesa do agente lançada.')->success()->send();
                    } catch (DomainException $e) {
                        Notification::make()->title($e->getMessage())->warning()->send();
                    }
                }

                return $pdf($record->fresh());
            });
    }
}
