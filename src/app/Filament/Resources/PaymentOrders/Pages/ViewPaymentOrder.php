<?php

namespace App\Filament\Resources\PaymentOrders\Pages;

use App\Actions\PaymentOrders\DeletePaymentOrder;
use App\Actions\PaymentOrders\EditOrderCode;
use App\Actions\PaymentOrders\EditPaymentStatus;
use App\Actions\PaymentOrders\RecalculatePaymentOrder;
use App\Filament\Resources\PaymentOrders\PaymentOrderResource;
use App\Models\FinPaymentOrder;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPaymentOrder extends ViewRecord
{
    protected static string $resource = PaymentOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recalcular')
                ->label('Recalcular despesas')
                ->icon(Heroicon::OutlinedCalculator)
                ->requiresConfirmation()
                ->modalHeading('Recalcular as despesas da ordem?')
                ->modalDescription('Os itens são refeitos com as despesas e as cotações de hoje; os que já estão em instrução de pagamento não mudam.')
                ->action(function (FinPaymentOrder $record, Action $action) {
                    $total = null;
                    $this->run(function () use ($record, &$total) {
                        $total = app(RecalculatePaymentOrder::class)($record);
                    }, $action);
                    Notification::make()->title('Cobrança recalculada com sucesso!')->body('Total: R$ '.number_format($total, 2, ',', '.'))->success()->send();
                }),
            Action::make('codigoIdentificador')
                ->label('Código identificador')
                ->icon(Heroicon::OutlinedHashtag)
                ->modalWidth('sm')
                ->modalDescription('Caso o armador possua um código específico para esse pagamento, favor informar abaixo.')
                ->fillForm(fn (FinPaymentOrder $record) => ['code' => $record->codigo_identificacao])
                ->schema([TextInput::make('code')->label('Código de identificação')])
                ->action(function (array $data, FinPaymentOrder $record) {
                    app(EditOrderCode::class)($record, $data['code'] ?: null);
                    Notification::make()->title('Código atualizado.')->success()->send();
                }),
            Action::make('alterarStatus')
                ->label('Alterar status')
                ->icon(Heroicon::OutlinedArrowPath)
                ->modalWidth('sm')
                ->fillForm(fn (FinPaymentOrder $record) => ['status' => $record->status])
                ->schema([
                    Select::make('status')
                        ->label('Status do pagamento')
                        ->options(FinPaymentOrder::STATUSES)
                        ->required(),
                ])
                ->action(function (array $data, FinPaymentOrder $record, Action $action) {
                    $this->run(fn () => app(EditPaymentStatus::class)($record, (int) $data['status']), $action);
                    Notification::make()->title('Status atualizado.')->success()->send();
                }),
            Action::make('excluir')
                ->label('Excluir')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Excluir a ordem de pagamento?')
                ->modalDescription('Apaga a ordem, os itens e as instruções de pagamento e devolve a reserva à fila de pagamento.')
                ->action(function (FinPaymentOrder $record) {
                    app(DeletePaymentOrder::class)($record);
                    Notification::make()->title('Registro excluído com sucesso.')->success()->send();

                    return redirect(PaymentOrderResource::getUrl());
                }),
        ];
    }

    /** Conta escolhida (0 = boleto) no campo "Dados Bancários do Armador" da página. @var array{bank?: int}|null */
    public ?array $bankData = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->bankData = ['bank' => (int) $this->getRecord()->fin_armador_bank_id];
    }

    private function run(callable $change, Action $action): void
    {
        try {
            $change();
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();
        }
    }
}
