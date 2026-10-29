<?php

namespace App\Filament\Resources\PaymentOrders\Pages;

use App\Actions\PaymentOrders\Generation\GeneratePaymentOrders;
use App\Filament\Resources\PaymentOrders\PaymentOrderResource;
use App\Models\Embarque;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPaymentOrders extends ListRecords
{
    protected static string $resource = PaymentOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('gerarManualmente')
                ->label('Gerar manualmente')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->modalHeading('Gerar as ordens de pagamento?')
                ->modalDescription('Gera as ordens das reservas com pagamento pendente: importação com chegada confirmada e exportação com saída confirmada.')
                ->action(fn (GeneratePaymentOrders $generate, Action $action) => $this->notifyCreated($this->run(fn () => $generate->all(), $action))),
            Action::make('gerarParaReserva')
                ->label('Gerar ordem da reserva')
                ->icon(Heroicon::OutlinedDocumentPlus)
                ->modalWidth('md')
                ->modalDescription('Gera as ordens de pagamento ao armador da reserva escolhida.')
                ->schema([
                    Select::make('embarque_id')
                        ->label('Reserva')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Embarque::query()
                            ->where('booking', 'like', "%{$search}%")
                            ->orderByDesc('embarque_id')
                            ->limit(30)
                            ->pluck('booking', 'embarque_id'))
                        ->getOptionLabelUsing(fn ($value) => Embarque::find($value)?->booking),
                ])
                ->action(fn (array $data, GeneratePaymentOrders $generate, Action $action) => $this->notifyCreated($this->run(fn () => $generate->forBooking(Embarque::findOrFail($data['embarque_id'])), $action))),
        ];
    }

    private function run(callable $generate, Action $action): int
    {
        try {
            return $generate();
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();
        }
    }

    private function notifyCreated(int $created): void
    {
        $created
            ? Notification::make()->title("{$created} ordem(ns) de pagamento gerada(s) com sucesso!")->success()->send()
            : Notification::make()->title('Nenhuma ordem de pagamento gerada!')->warning()->send();
    }
}
