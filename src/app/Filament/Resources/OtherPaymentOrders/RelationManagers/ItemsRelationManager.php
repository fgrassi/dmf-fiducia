<?php

namespace App\Filament\Resources\OtherPaymentOrders\RelationManagers;

use App\Actions\PaymentOrders\EditPaymentIof;
use App\Actions\PaymentOrders\Other\EditOtherOrderItem;
use App\Models\OrdemPagamentoItem;
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
 * Resumo da ordem (executeShow do legado): uma linha por despesa da reserva, com os valores do último item da despesa
 * (o legado lê a variável do laço, inclusive o "Valor do Item"). Controle ON = item com status 1.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Resumo';

    private const ENABLED = 1;

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
                        ->state(fn () => $money($order()->itemsTotal())),
                    TextEntry::make('iof')
                        ->label('Despesas Financeiras/IOF')
                        ->inlineLabel()
                        ->state(fn () => $money((float) $order()->valor_iof))
                        ->suffixAction($this->editIof()),
                    TextEntry::make('total')
                        ->label('Total')
                        ->inlineLabel()
                        ->weight('bold')
                        ->state(fn () => $money($order()->itemsTotal() + (float) $order()->valor_iof)),
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
        $value = fn (string $column) => DB::table('fin_expenses_values as v')->select($column)->whereColumn('v.id', 'ordem_pagamento_item.ref_expense');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('ordem_pagamento_item.*')
                ->addSelect([
                    'currency_symbol' => DB::table('cmn_currencies')->select('symbol')->whereColumn('cmn_currencies.id', 'ordem_pagamento_item.ref_currency'),
                    'due_to_name' => DB::table('fin_expenses_values as v')->join('fin_expenses_values_due_to as d', 'd.id', '=', 'v.ref_due_to')->select('d.name')->whereColumn('v.id', 'ordem_pagamento_item.ref_expense'),
                    'expense_name' => DB::table('fin_expenses_values as v')->join('fin_expenses as e', 'e.id', '=', 'v.ref_expense')->select('e.name')->whereColumn('v.id', 'ordem_pagamento_item.ref_expense'),
                    'unity_name' => DB::table('fin_expenses_values as v')->join('fin_expenses as e', 'e.id', '=', 'v.ref_expense')->join('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')->select('u.acronym')->whereColumn('v.id', 'ordem_pagamento_item.ref_expense'),
                    'ref_equipment' => $value('v.ref_equipment'),
                    'v_bl' => $value('v.ref_bl'),
                    'ref_container' => $value('v.ref_container'),
                ])
                ->whereIn('ordem_pagamento_item.ordem_pagamento_item_id', fn ($last) => $last->selectRaw('MAX(l.ordem_pagamento_item_id)')->from('ordem_pagamento_item as l')
                    ->whereColumn('l.ordem_pagamento_id', 'ordem_pagamento_item.ordem_pagamento_id')
                    ->groupBy('l.ref_expense'))
                ->orderBy('ordem_pagamento_item.ordem_pagamento_item_id'))
            ->paginated(false)
            ->columns([
                TextColumn::make('expense')
                    ->label('Despesa')
                    ->state(fn (OrdemPagamentoItem $record) => app(ChargeDetails::class)->expenseLabel((object) $record->getAttributes())),
                TextColumn::make('current_rate_value')
                    ->label('Taxa (moeda)')
                    ->numeric(4, ',', '.'),
                TextColumn::make('indice')
                    ->label('Indice'),
                TextColumn::make('currency_symbol')
                    ->label('Moeda'),
                TextColumn::make('unitary_value')
                    ->label('Valor Unitário')
                    ->numeric(2, ',', '.'),
                TextColumn::make('item_value')
                    ->label('Valor do Item')
                    ->formatStateUsing(fn ($state) => 'R$ '.number_format((float) $state, 2, ',', '.')),
                TextColumn::make('due_to_name')
                    ->label('Due To'),
                TextColumn::make('status')
                    ->label('Controle')
                    ->badge()
                    ->formatStateUsing(fn ($state) => (int) $state === self::ENABLED ? 'ON' : 'OFF')
                    ->color(fn ($state) => (int) $state === self::ENABLED ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('taxa')
                    ->label('Taxa')
                    ->icon('heroicon-o-currency-dollar')
                    ->modalWidth('sm')
                    ->modalHeading('Informe o novo valor da taxa')
                    ->fillForm(fn (OrdemPagamentoItem $record) => ['rate' => $record->current_rate_value])
                    ->schema([TextInput::make('rate')->label('Taxa')->numeric()->required()])
                    ->action(function (array $data, OrdemPagamentoItem $record) {
                        $item = app(EditOtherOrderItem::class)->rate($record, (float) $data['rate']);
                        Notification::make()->title('Valor do item: R$ '.number_format((float) $item->item_value, 2, ',', '.'))->success()->send();
                    }),
                Action::make('indice')
                    ->label('Índice')
                    ->icon('heroicon-o-hashtag')
                    ->modalWidth('sm')
                    ->modalHeading('Informe o novo índice')
                    ->fillForm(fn (OrdemPagamentoItem $record) => ['index' => $record->indice])
                    ->schema([TextInput::make('index')->label('Índice')->numeric()->required()])
                    ->action(function (array $data, OrdemPagamentoItem $record) {
                        $item = app(EditOtherOrderItem::class)->index($record, (float) $data['index']);
                        Notification::make()->title('Valor do item: R$ '.number_format((float) $item->item_value, 2, ',', '.'))->success()->send();
                    }),
                Action::make('valorUnitario')
                    ->label('Valor unitário')
                    ->icon('heroicon-o-pencil-square')
                    ->modalWidth('sm')
                    ->modalHeading('Informe o valor unitário atualizado')
                    ->fillForm(fn (OrdemPagamentoItem $record) => ['unit' => $record->unitary_value])
                    ->schema([TextInput::make('unit')->label('Valor unitário')->numeric()->required()])
                    ->action(function (array $data, OrdemPagamentoItem $record) {
                        $item = app(EditOtherOrderItem::class)->unitValue($record, (float) $data['unit']);
                        Notification::make()->title('Valor do item: R$ '.number_format((float) $item->item_value, 2, ',', '.'))->success()->send();
                    }),
                Action::make('alternar')
                    ->label(fn (OrdemPagamentoItem $record) => (int) $record->status === self::ENABLED ? 'Desligar' : 'Ligar')
                    ->icon(fn (OrdemPagamentoItem $record) => (int) $record->status === self::ENABLED ? 'heroicon-o-pause' : 'heroicon-o-play')
                    ->action(function (OrdemPagamentoItem $record) {
                        $enabled = app(EditOtherOrderItem::class)->toggle($record);
                        Notification::make()->title($enabled ? 'Despesa ligada.' : 'Despesa desligada.')->success()->send();
                    }),
            ]);
    }
}
