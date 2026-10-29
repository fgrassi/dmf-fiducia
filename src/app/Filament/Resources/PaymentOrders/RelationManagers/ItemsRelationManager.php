<?php

namespace App\Filament\Resources\PaymentOrders\RelationManagers;

use App\Actions\PaymentOrders\EditOrderItem;
use App\Actions\PaymentOrders\EditPaymentIof;
use App\Actions\PaymentOrders\ToggleOrderExpense;
use App\Models\FinPaymentOrderItem;
use App\Services\Cobranca\ChargeDetails;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Resumo da ordem (executeShow do legado): uma linha por despesa da reserva, com o total dos itens ligados. Taxa, moeda e
 * valor unitário são os do último item da despesa, e é esse item que a edição altera (o legado lê a variável do laço).
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Resumo';

    private const DISABLED = 0;

    public function isReadOnly(): bool
    {
        return false;
    }

    /** A tabela do resumo e, depois das linhas, Subtotal, Despesas Financeiras/IOF (com o botão de editar) e Total. */
    public function content(Schema $schema): Schema
    {
        $order = fn () => $this->getOwnerRecord();
        $money = fn (float $value) => 'R$ '.number_format($value, 2, ',', '.');

        return $schema
            ->components([
                EmbeddedTable::make(),
                Group::make([
                    TextEntry::make('subtotal')
                        ->label('Subtotal')
                        ->inlineLabel()
                        ->state(fn () => $money($order()->enabledTotal())),
                    TextEntry::make('iof')
                        ->label('Despesas Financeiras/IOF')
                        ->inlineLabel()
                        ->state(fn () => $money((float) $order()->valor_iof))
                        ->suffixAction($this->editIof()),
                    TextEntry::make('total')
                        ->label('Total')
                        ->inlineLabel()
                        ->weight('bold')
                        ->state(fn () => $money($order()->enabledTotal() + (float) $order()->valor_iof)),
                ])->extraAttributes(['class' => 'fi-order-totals', 'style' => 'padding-left: 2rem']),
            ]);
    }

    /** Despesas Financeiras/IOF da ordem, editado pelo botão simples ao lado do campo. */
    private function editIof(): Action
    {
        return Action::make('alterarIof')
            ->icon('heroicon-o-pencil-square')
            ->tooltip('Alterar Despesas Financeiras/IOF')
            ->modalHeading('Alterar Despesas Financeiras/IOF')
            ->modalWidth('sm')
            ->fillForm(fn () => ['iof' => $this->getOwnerRecord()->valor_iof])
            ->schema([TextInput::make('iof')->label('Despesas Financeiras/IOF (R$)')->numeric()->minValue(0)->required()])
            ->action(function (array $data, Action $action) {
                try {
                    app(EditPaymentIof::class)->order($this->getOwnerRecord(), (float) $data['iof']);
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('IOF atualizado.')->success()->send();
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('fin_payment_order_items.*')
                ->addSelect([
                    'currency_symbol' => DB::table('cmn_currencies')->select('symbol')->whereColumn('cmn_currencies.id', 'fin_payment_order_items.ref_currency'),
                    'control' => DB::table('fin_payment_order_items_disabled as d')->select('d.status')
                        ->whereColumn('d.ref_fin_payment_order', 'fin_payment_order_items.ref_payment_order')
                        ->whereColumn('d.ref_expense', 'fin_payment_order_items.ref_expense'),
                    'expense_total' => DB::table('fin_payment_order_items as t')->selectRaw('COALESCE(SUM(t.item_value), 0)')
                        ->whereColumn('t.ref_payment_order', 'fin_payment_order_items.ref_payment_order')
                        ->whereColumn('t.ref_expense', 'fin_payment_order_items.ref_expense')
                        ->whereNotExists(fn ($d) => $d->selectRaw('1')->from('fin_payment_order_items_disabled as x')
                            ->whereColumn('x.ref_fin_payment_order', 't.ref_payment_order')
                            ->whereColumn('x.ref_expense', 't.ref_expense')
                            ->where('x.status', self::DISABLED)),
                    'expense_name' => DB::table('fin_expenses_values as v')->join('fin_expenses as e', 'e.id', '=', 'v.ref_expense')->select('e.name')->whereColumn('v.id', 'fin_payment_order_items.ref_expense'),
                    'unity_name' => DB::table('fin_expenses_values as v')->join('fin_expenses as e', 'e.id', '=', 'v.ref_expense')->join('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')->select('u.acronym')->whereColumn('v.id', 'fin_payment_order_items.ref_expense'),
                    'ref_equipment' => DB::table('fin_expenses_values as v')->select('v.ref_equipment')->whereColumn('v.id', 'fin_payment_order_items.ref_expense'),
                    'v_bl' => DB::table('fin_expenses_values as v')->select('v.ref_bl')->whereColumn('v.id', 'fin_payment_order_items.ref_expense'),
                    'ref_container' => DB::table('fin_expenses_values as v')->select('v.ref_container')->whereColumn('v.id', 'fin_payment_order_items.ref_expense'),
                ])
                ->whereIn('fin_payment_order_items.id', fn ($last) => $last->selectRaw('MAX(l.id)')->from('fin_payment_order_items as l')
                    ->whereColumn('l.ref_payment_order', 'fin_payment_order_items.ref_payment_order')
                    ->groupBy('l.ref_expense'))
                ->orderBy('fin_payment_order_items.id'))
            ->paginated(false)
            ->columns([
                TextColumn::make('expense')
                    ->label('Despesa')
                    ->state(fn (FinPaymentOrderItem $record) => app(ChargeDetails::class)->expenseLabel((object) $record->getAttributes())),
                TextColumn::make('current_rate_value')
                    ->label('Taxa')
                    ->numeric(4, ',', '.'),
                TextColumn::make('currency_symbol')
                    ->label('Moeda'),
                TextColumn::make('unitary_value')
                    ->label('Valor Unitário')
                    ->numeric(2, ',', '.'),
                TextColumn::make('expense_total')
                    ->label('Valor do Item')
                    ->formatStateUsing(fn ($state) => 'R$ '.number_format((float) $state, 2, ',', '.')),
                TextColumn::make('control')
                    ->label('Controle')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state !== null && (int) $state === self::DISABLED ? 'OFF' : 'ON')
                    ->color(fn ($state) => $state !== null && (int) $state === self::DISABLED ? 'gray' : 'success'),
            ])
            ->recordActions([
                Action::make('taxa')
                    ->label('Taxa')
                    ->icon('heroicon-o-currency-dollar')
                    ->modalWidth('sm')
                    ->modalHeading('Informe o novo valor da taxa')
                    ->fillForm(fn (FinPaymentOrderItem $record) => ['rate' => $record->current_rate_value])
                    ->schema([TextInput::make('rate')->label('Taxa')->numeric()->required()])
                    ->action(function (array $data, FinPaymentOrderItem $record) {
                        $item = app(EditOrderItem::class)->rate($record, (float) $data['rate']);
                        Notification::make()->title('Valor do item: R$ '.number_format((float) $item->item_value, 2, ',', '.'))->success()->send();
                    }),
                Action::make('valorUnitario')
                    ->label('Valor unitário')
                    ->icon('heroicon-o-pencil-square')
                    ->modalWidth('sm')
                    ->modalHeading('Informe o valor unitário atualizado')
                    ->fillForm(fn (FinPaymentOrderItem $record) => ['unit' => $record->unitary_value])
                    ->schema([TextInput::make('unit')->label('Valor unitário')->numeric()->required()])
                    ->action(function (array $data, FinPaymentOrderItem $record) {
                        $item = app(EditOrderItem::class)->unitValue($record, (float) $data['unit']);
                        Notification::make()->title('Valor do item: R$ '.number_format((float) $item->item_value, 2, ',', '.'))->success()->send();
                    }),
                Action::make('alternar')
                    ->label(fn (FinPaymentOrderItem $record) => $record->control !== null && (int) $record->control === self::DISABLED ? 'Ligar' : 'Desligar')
                    ->icon(fn (FinPaymentOrderItem $record) => $record->control !== null && (int) $record->control === self::DISABLED ? 'heroicon-o-play' : 'heroicon-o-pause')
                    ->action(function (FinPaymentOrderItem $record) {
                        $enabled = app(ToggleOrderExpense::class)($this->getOwnerRecord(), (int) $record->ref_expense, auth()->id());
                        Notification::make()->title($enabled ? 'Despesa ligada.' : 'Despesa desligada.')->success()->send();
                    }),
            ]);
    }
}
