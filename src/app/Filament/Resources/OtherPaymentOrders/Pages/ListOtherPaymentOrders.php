<?php

namespace App\Filament\Resources\OtherPaymentOrders\Pages;

use App\Actions\PaymentOrders\Other\GenerateOtherPaymentOrders;
use App\Filament\Resources\OtherPaymentOrders\OtherPaymentOrderResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListOtherPaymentOrders extends ListRecords
{
    protected static string $resource = OtherPaymentOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('gerarManualmente')
                ->label('Gerar manualmente')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->modalHeading('Gerar as ordens de pagamento?')
                ->modalDescription('Gera as ordens das reservas com pagamento de outros pendente: importação marítima com chegada confirmada, importação aérea, exportação, seguro, desembaraço e correio.')
                ->action(function (GenerateOtherPaymentOrders $generate, Action $action) {
                    try {
                        $created = $generate->all();
                    } catch (DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();
                    }

                    $created
                        ? Notification::make()->title("{$created} ordem(ns) de pagamento gerada(s) com sucesso!")->success()->send()
                        : Notification::make()->title('Nenhuma ordem de pagamento gerada!')->warning()->send();
                }),
        ];
    }
}
