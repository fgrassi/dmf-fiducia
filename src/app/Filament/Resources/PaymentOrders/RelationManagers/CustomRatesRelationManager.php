<?php

namespace App\Filament\Resources\PaymentOrders\RelationManagers;

use App\Actions\PaymentOrders\DeleteOrderCustomRate;
use App\Actions\PaymentOrders\SaveOrderCustomRate;
use App\Models\CmnCurrency;
use App\Models\FinCustomPaymentOrderCurrencyRate;
use App\Services\Cobranca\ChargeDetails;
use DomainException;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Cotações Negociadas da ordem (listaCustom do legado): valem para a moeda toda ou para uma despesa, no próximo recálculo. */
class CustomRatesRelationManager extends RelationManager
{
    protected static string $relationship = 'customRates';

    protected static ?string $title = 'Cotações Negociadas';

    private const ARMADOR_DUE_TO = 3;

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('ref_currency')
                    ->label('Moeda')
                    ->required()
                    ->options(fn () => CmnCurrency::query()->orderBy('name')->get()->mapWithKeys(fn (CmnCurrency $c) => [$c->id => "{$c->name} ({$c->symbol})"])),
                Select::make('ref_expense')
                    ->label('Despesa')
                    ->placeholder('Toda a moeda')
                    ->options(fn () => $this->expenseOptions()),
                TextInput::make('custom_rate')
                    ->label('Valor Negociado')
                    ->required()
                    ->numeric()
                    ->minValue(0.0001),
                Textarea::make('note')
                    ->label('Observações'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('fin_custom_payment_order_currency_rate.*')
                ->addSelect([
                    'currency_name' => DB::table('cmn_currencies')->select('name')->whereColumn('cmn_currencies.id', 'fin_custom_payment_order_currency_rate.ref_currency'),
                    'currency_symbol' => DB::table('cmn_currencies')->select('symbol')->whereColumn('cmn_currencies.id', 'fin_custom_payment_order_currency_rate.ref_currency'),
                    'author' => DB::table('usuario')->select('nome')->whereColumn('usuario.usuario_id', 'fin_custom_payment_order_currency_rate.created_by'),
                ])
                ->orderByDesc('fin_custom_payment_order_currency_rate.id'))
            ->paginated(false)
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar Cotação Negociada')
                    ->modalWidth('md')
                    ->using(function (array $data, CreateAction $action) {
                        try {
                            return app(SaveOrderCustomRate::class)($this->getOwnerRecord(), (int) $data['ref_currency'], filled($data['ref_expense'] ?? null) ? (int) $data['ref_expense'] : null, (float) $data['custom_rate'], $data['note'] ?? null, auth()->id());
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }
                    }),
            ])
            ->columns([
                TextColumn::make('currency')
                    ->label('Moeda')
                    ->state(fn (FinCustomPaymentOrderCurrencyRate $record) => "{$record->currency_name} ({$record->currency_symbol})"),
                TextColumn::make('expense')
                    ->label('Despesa')
                    ->state(fn (FinCustomPaymentOrderCurrencyRate $record) => $record->ref_expense ? ($this->expenseOptions()[$record->ref_expense] ?? '') : ''),
                TextColumn::make('custom_rate')
                    ->label('Valor')
                    ->numeric(4, ',', '.'),
                TextColumn::make('author')
                    ->label('Registrado por'),
                TextColumn::make('created_at')
                    ->label('Data')
                    ->date('d/m/Y'),
                TextColumn::make('note')
                    ->label('Observação'),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->using(function (FinCustomPaymentOrderCurrencyRate $record) {
                        app(DeleteOrderCustomRate::class)($record);

                        return true;
                    }),
            ]);
    }

    /** Despesas da reserva do armador (Due To 3), como no formulário do legado. @return array<int, string> */
    private function expenseOptions(): array
    {
        return DB::table('fin_expenses_values as v')
            ->join('fin_expenses as e', 'e.id', '=', 'v.ref_expense')
            ->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')
            ->where('v.ref_embarque', $this->getOwnerRecord()->ref_embarque)
            ->where('v.ref_due_to', self::ARMADOR_DUE_TO)
            ->orderBy('v.id')
            ->get(['v.id', 'v.ref_equipment', 'v.ref_bl as v_bl', 'v.ref_container', 'e.name as expense_name', 'u.acronym as unity_name'])
            ->mapWithKeys(fn ($row) => [$row->id => app(ChargeDetails::class)->expenseLabel($row)])
            ->all();
    }
}
