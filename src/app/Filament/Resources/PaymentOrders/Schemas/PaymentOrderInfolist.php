<?php

namespace App\Filament\Resources\PaymentOrders\Schemas;

use App\Actions\PaymentOrders\EditPaymentDate;
use App\Actions\PaymentOrders\SetOrderBank;
use App\Models\FinArmadorBanks;
use App\Models\FinPaymentOrder;
use App\Models\Porto;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** showSuccess do legado: informações financeiras e dados da reserva (o resumo das despesas é a tabela abaixo). */
class PaymentOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $booking = fn (FinPaymentOrder $order, string $field) => $order->embarque?->{$field};
        $name = fn (string $table, string $key, $id) => $id ? (string) DB::table($table)->where($key, $id)->value('nome') : '';

        return $schema
            ->components([
                Section::make('Informações Financeiras')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('armador.nome')
                            ->label('Armador'),
                        TextEntry::make('armador.cnpj')
                            ->label('CNPJ')
                            ->placeholder('O CNPJ está em branco!')
                            ->color(fn (?string $state) => blank($state) ? 'danger' : null),
                        TextEntry::make('status')
                            ->label('Status Pagamento')
                            ->badge()
                            ->formatStateUsing(fn ($state) => FinPaymentOrder::STATUSES[(int) $state] ?? 'N/D'),
                        TextEntry::make('payment_date')
                            ->label('Data Pagamento')
                            ->state(fn (FinPaymentOrder $order) => $order->payment_date?->format('d/m/Y') ?? 'N/D')
                            ->suffixAction(self::editPaymentDate()),
                        TextEntry::make('created_at')
                            ->label('Data (Instrução de Pagamento)')
                            ->date('d/m/Y'),
                        TextEntry::make('mbl')
                            ->label('MBL')
                            ->placeholder('—'),
                        TextEntry::make('codigo_identificacao')
                            ->label('Código Identificador')
                            ->placeholder('#'),
                    ]),
                Section::make('Dados Bancários do Armador')
                    ->statePath('bankData')
                    ->schema([
                        Radio::make('bank')
                            ->hiddenLabel()
                            ->options(fn (FinPaymentOrder $record) => self::bankOptions($record))
                            ->descriptions(fn (FinPaymentOrder $record) => self::bankDescriptions($record))
                            ->visible(fn (FinPaymentOrder $record) => self::bankAccounts($record)->isNotEmpty())
                            ->live()
                            ->afterStateUpdated(function ($state, FinPaymentOrder $record) {
                                try {
                                    app(SetOrderBank::class)($record, (int) $state);
                                    Notification::make()->title('Conta Selecionada/Atualizada com sucesso!')->success()->send();
                                } catch (DomainException $e) {
                                    Notification::make()->title($e->getMessage())->danger()->send();
                                }
                            }),
                        TextEntry::make('no_bank_accounts')
                            ->hiddenLabel()
                            ->state('Atenção! Esse armador não possui Dados Bancários.')
                            ->color('danger')
                            ->visible(fn (FinPaymentOrder $record) => self::bankAccounts($record)->isEmpty()),
                    ]),
                Section::make('Reserva')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('embarque.booking')
                            ->label('Reserva'),
                        TextEntry::make('navio_origem')
                            ->label('Navio Origem')
                            ->state(fn (FinPaymentOrder $order) => $name('navio', 'navio_id', $booking($order, 'navio_id')))
                            ->placeholder('—'),
                        TextEntry::make('navio_destino')
                            ->label('Navio Destino')
                            ->state(fn (FinPaymentOrder $order) => $name('navio', 'navio_id', $booking($order, 'navio_id_destino')))
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
                            ->state(fn (FinPaymentOrder $order) => Porto::find($booking($order, 'porto_origem_id'))?->label)
                            ->placeholder('—'),
                        TextEntry::make('destino')
                            ->label('Destino')
                            ->state(fn (FinPaymentOrder $order) => Porto::find($booking($order, 'porto_destino_id'))?->label)
                            ->placeholder('—'),
                        TextEntry::make('exportador')
                            ->label('Exportador')
                            ->state(fn (FinPaymentOrder $order) => $name('pessoa', 'pessoa_id', $booking($order, 'pessoa_id_cliente')))
                            ->placeholder('—'),
                        TextEntry::make('importador')
                            ->label('Importador')
                            ->state(fn (FinPaymentOrder $order) => $name('pessoa', 'pessoa_id', $booking($order, 'pessoa_id_importador')))
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
            ->fillForm(fn (FinPaymentOrder $record) => ['payment_date' => $record->payment_date?->toDateString()])
            ->schema([DatePicker::make('payment_date')->label('Data de pagamento')->required()])
            ->action(function (array $data, FinPaymentOrder $record, Action $action) {
                try {
                    app(EditPaymentDate::class)($record, $data['payment_date']);
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Data de pagamento atualizada.')->success()->send();
            });
    }

    /** Contas ativas do armador da ordem. @return Collection<int, FinArmadorBanks> */
    private static function bankAccounts(FinPaymentOrder $order): Collection
    {
        return FinArmadorBanks::active()->with('bank')->where('ref_armador', $order->ref_armador)->orderBy('id')->get();
    }

    /** @return array<int, string> Boleto (0) e uma opção por conta: banco, agência e conta. */
    private static function bankOptions(FinPaymentOrder $order): array
    {
        return [0 => 'Boleto'] + self::bankAccounts($order)->mapWithKeys(fn (FinArmadorBanks $b) => [
            $b->id => implode(' | ', array_filter([$b->bank?->name, $b->bank_agency ? "Agência {$b->bank_agency}" : null, $b->bank_account ? "Conta {$b->bank_account}" : null])),
        ])->all();
    }

    /** @return array<int, string> Código e descrição de cada conta. */
    private static function bankDescriptions(FinPaymentOrder $order): array
    {
        return self::bankAccounts($order)->mapWithKeys(fn (FinArmadorBanks $b) => [
            $b->id => implode(' | ', array_filter([$b->id_code ? "Código {$b->id_code}" : null, $b->obs])),
        ])->all();
    }
}
