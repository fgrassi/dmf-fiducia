<?php

namespace App\Filament\Resources\OtherPaymentOrders\Schemas;

use App\Actions\PaymentOrders\EditPaymentDate;
use App\Models\FinPaymentOrder;
use App\Models\OrdemPagamento;
use App\Models\Porto;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/** showSuccess do legado: informações financeiras e dados da reserva (o resumo das despesas é a tabela abaixo). */
class OtherPaymentOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $booking = fn (OrdemPagamento $order, string $field) => $order->embarque?->{$field};
        $name = fn (string $table, string $key, $id) => $id ? (string) DB::table($table)->where($key, $id)->value('nome') : '';

        return $schema
            ->components([
                Section::make('Informações Financeiras')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('status')
                            ->label('Status Pagamento')
                            ->badge()
                            ->formatStateUsing(fn ($state) => FinPaymentOrder::STATUSES[(int) $state] ?? 'N/D'),
                        TextEntry::make('payment_date')
                            ->label('Data Pagamento')
                            ->state(fn (OrdemPagamento $order) => $order->payment_date?->format('d/m/Y') ?? 'N/D')
                            ->suffixAction(self::editPaymentDate()),
                        TextEntry::make('created_at')
                            ->label('Data (Instrução de Pagamento)')
                            ->date('d/m/Y'),
                    ]),
                Section::make('Reserva')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('embarque.booking')
                            ->label('Reserva'),
                        TextEntry::make('navio_origem')
                            ->label('Navio Origem')
                            ->state(fn (OrdemPagamento $order) => $name('navio', 'navio_id', $booking($order, 'navio_id')))
                            ->placeholder('—'),
                        TextEntry::make('navio_destino')
                            ->label('Navio Destino')
                            ->state(fn (OrdemPagamento $order) => $name('navio', 'navio_id', $booking($order, 'navio_id_destino')))
                            ->placeholder('—'),
                        TextEntry::make('embarque.saida_prevista')
                            ->label('Data de Saída')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        TextEntry::make('embarque.chegada_prevista')
                            ->label('Data de Chegada')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        TextEntry::make('embarque.free_time')
                            ->label('Free Time Demurrage')
                            ->formatStateUsing(fn ($state) => number_format((float) $state, 0).' dias'),
                        TextEntry::make('origem')
                            ->label('Origem')
                            ->state(fn (OrdemPagamento $order) => Porto::find($booking($order, 'porto_origem_id'))?->label)
                            ->placeholder('—'),
                        TextEntry::make('destino')
                            ->label('Destino')
                            ->state(fn (OrdemPagamento $order) => Porto::find($booking($order, 'porto_destino_id'))?->label)
                            ->placeholder('—'),
                        TextEntry::make('exportador')
                            ->label('Exportador')
                            ->state(fn (OrdemPagamento $order) => $name('pessoa', 'pessoa_id', $booking($order, 'pessoa_id_cliente')))
                            ->placeholder('—'),
                        TextEntry::make('importador')
                            ->label('Importador')
                            ->state(fn (OrdemPagamento $order) => $name('pessoa', 'pessoa_id', $booking($order, 'pessoa_id_importador')))
                            ->placeholder('—'),
                    ]),
            ]);
    }

    /** editDataPagamento (legado): data de pagamento editada ao lado do campo, sem histórico. */
    private static function editPaymentDate(): Action
    {
        return Action::make('alterarDataPagamento')
            ->icon(Heroicon::PencilSquare)
            ->tooltip('Alterar data de pagamento')
            ->modalHeading('Alterar data de pagamento')
            ->modalWidth('sm')
            ->fillForm(fn (OrdemPagamento $record) => ['payment_date' => $record->payment_date?->toDateString()])
            ->schema([DatePicker::make('payment_date')->label('Data de pagamento')->required()])
            ->action(function (array $data, OrdemPagamento $record, Action $action) {
                try {
                    app(EditPaymentDate::class)($record, $data['payment_date']);
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Data de pagamento atualizada.')->success()->send();
            });
    }
}
