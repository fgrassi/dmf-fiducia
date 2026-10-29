<?php

namespace App\Filament\Resources\PaymentOrders\Tables;

use App\Actions\PaymentOrders\AttachPaymentReceipt;
use App\Models\Armador;
use App\Models\FinPaymentOrder;
use DomainException;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class PaymentOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('fin_payment_orders.*')
                ->addSelect([
                    'items_total' => DB::table('fin_payment_order_items as i')
                        ->selectRaw('COALESCE(SUM(i.item_value), 0)')
                        ->whereColumn('i.ref_payment_order', 'fin_payment_orders.id')
                        ->whereNotExists(fn ($disabled) => $disabled->selectRaw('1')->from('fin_payment_order_items_disabled as d')
                            ->whereColumn('d.ref_fin_payment_order', 'i.ref_payment_order')
                            ->whereColumn('d.ref_expense', 'i.ref_expense')
                            ->where('d.status', 0)),
                    'charges_total' => DB::table('fin_charges')->selectRaw('COUNT(*)')->whereColumn('fin_charges.ref_booking', 'fin_payment_orders.ref_embarque'),
                    'charges_open' => DB::table('fin_charges')->selectRaw('COUNT(*)')->whereColumn('fin_charges.ref_booking', 'fin_payment_orders.ref_embarque')->where('fin_charges.status', 0),
                ])
                ->with(['embarque', 'armador']))
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('embarque.booking')
                    ->label('Booking'),
                TextColumn::make('mbl')
                    ->label('MBL'),
                TextColumn::make('armador.nome')
                    ->label('Armador'),
                TextColumn::make('items_total')
                    ->label('Valor Total')
                    ->formatStateUsing(fn ($state) => 'R$'.number_format((float) $state, 2, ',', '.')),
                TextColumn::make('created_at')
                    ->label('Data Criação')
                    ->date('d/m/Y'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => FinPaymentOrder::STATUSES[(int) $state] ?? $state)
                    ->color(fn ($state) => [0 => 'gray', 1 => 'success', 2 => 'danger'][(int) $state] ?? 'gray'),
                IconColumn::make('billing')
                    ->label('Cobrança')
                    ->state(fn (FinPaymentOrder $record) => self::billingState($record))
                    ->icon(fn (?string $state) => $state ? 'heroicon-s-check-circle' : null)
                    ->color(fn (?string $state) => ['paid' => 'success', 'open' => 'danger', 'waiting' => 'gray'][$state] ?? 'gray')
                    ->tooltip(fn (FinPaymentOrder $record) => ['paid' => 'Cobranças pagas', 'open' => 'Cobrança em aberto', 'waiting' => 'Aguardando cobrança'][self::billingState($record)] ?? null),
            ])
            ->filters([
                Filter::make('created_at')
                    ->label('Data de criação')
                    ->schema([DatePicker::make('date')->label('Data de criação')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['date'], fn (Builder $q, $date) => $q->whereDate('fin_payment_orders.created_at', $date))),
                Filter::make('booking')
                    ->schema([TextInput::make('booking')->label('Booking')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['booking'], fn (Builder $q, $booking) => $q->whereHas('embarque', fn (Builder $e) => $e->where('booking', 'like', "%{$booking}%")))),
                Filter::make('mbl')
                    ->schema([TextInput::make('mbl')->label('MBL')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['mbl'], fn (Builder $q, $mbl) => $q->where('fin_payment_orders.mbl', 'like', "%{$mbl}%"))),
                SelectFilter::make('ref_armador')
                    ->label('Armador')
                    ->searchable()
                    ->options(fn () => Armador::query()->orderBy('nome')->pluck('nome', 'armador_id')),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(FinPaymentOrder::STATUSES),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('comprovante')
                        ->label('Comprovante de Pagamento')
                        ->icon('heroicon-o-paper-clip')
                        ->modalHeading('Comprovante de Pagamento')
                        ->modalDescription(fn (Collection $records) => self::selectionSummary($records))
                        ->modalSubmitActionLabel('Anexar Comprovante')
                        ->schema([
                            DatePicker::make('payment_date')
                                ->label('Data do Pagamento')
                                ->default(now()->toDateString())
                                ->required(),
                            FileUpload::make('receipt')
                                ->label('Comprovante')
                                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                                ->storeFiles(false)
                                ->required(),
                        ])
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data, BulkAction $action) {
                            try {
                                app(AttachPaymentReceipt::class)($records->modelKeys(), $data['payment_date'], $data['receipt'], auth()->id());
                            } catch (DomainException $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();
                                $action->halt();
                            }

                            Notification::make()->title('Comprovante anexado e baixa realizada.')->success()->send();
                        }),
                ]),
            ]);
    }

    /** Lista as ordens marcadas e soma os valores, como o "Total selecionado" da lista do legado. @param  Collection<int, FinPaymentOrder>  $orders */
    public static function selectionSummary(Collection $orders): HtmlString
    {
        return new HtmlString(
            $orders->map(fn (FinPaymentOrder $order) => e("Booking: {$order->embarque?->booking} | MBL: {$order->mbl}"))->implode('<br>')
            .'<br><strong>Total selecionado: R$ '.number_format((float) $orders->sum('items_total'), 2, ',', '.').'</strong>'
        );
    }

    /**
     * Indicador da cobrança da reserva (FinPaymentOrders::getStatusColor, legado): na exportação, verde se todas as
     * cobranças estão pagas e vermelho se não; na importação, verde ou cinza. Sem reserva, nada.
     *
     * @return ?string paid | open | waiting
     */
    private static function billingState(FinPaymentOrder $order): ?string
    {
        $embarque = $order->embarque;

        if (! $embarque || ! in_array($embarque->impor_expor, ['e', 'i'], true)) {
            return null;
        }

        $allPaid = (int) $order->charges_total > 0 && (int) $order->charges_open === 0;

        return match (true) {
            $allPaid => 'paid',
            $embarque->impor_expor === 'e' => 'open',
            default => 'waiting',
        };
    }
}
