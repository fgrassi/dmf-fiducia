<?php

namespace App\Filament\Resources\OtherPaymentOrders\Pages;

use App\Actions\PaymentOrders\EditPaymentStatus;
use App\Actions\PaymentOrders\Other\DeleteOtherPaymentOrder;
use App\Actions\PaymentOrders\Other\OtherOrderParties;
use App\Actions\PaymentOrders\Other\RecalculateOtherPaymentOrder;
use App\Filament\Resources\OtherPaymentOrders\OtherPaymentOrderResource;
use App\Models\FinPaymentOrder;
use App\Models\OrdemPagamento;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

class ViewOtherPaymentOrder extends ViewRecord
{
    protected static string $resource = OtherPaymentOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recalcular')
                ->label('Recalcular despesas')
                ->icon(Heroicon::OutlinedCalculator)
                ->modalWidth('4xl')
                ->modalHeading('Recalcular despesas')
                ->modalDescription('Informe as taxas das moedas e os dados de quem recebe. A instrução de pagamento de cada parte é criada ou atualizada.')
                ->modalSubmitActionLabel('Recalcular')
                ->fillForm(fn (OrdemPagamento $record) => [
                    'parties' => collect(app(OtherOrderParties::class)->forOrder($record))->map(fn (array $p) => ['id' => $p['id'], 'class' => $p['class'], 'name' => $p['name'], 'cnpj' => $p['cnpj'], 'bank' => $p['bank']])->all(),
                    'reset' => false,
                ])
                ->schema(fn (OrdemPagamento $record) => $this->recalculationSchema($record))
                ->action(function (array $data, OrdemPagamento $record, Action $action) {
                    $rates = collect($data['rates'] ?? [])->map(fn ($rate) => (float) $rate)->all();

                    try {
                        $result = app(RecalculateOtherPaymentOrder::class)($record, $rates, (bool) ($data['reset'] ?? false), $data['parties'] ?? [], auth()->id());
                    } catch (DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();
                    }

                    Notification::make()->title($result['message'])->status($result['status'] === 'OK' ? 'success' : 'warning')->send();
                }),
            Action::make('excluir')
                ->label('Excluir')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Excluir a ordem de pagamento?')
                ->modalDescription('Apaga a ordem, os itens e as instruções de pagamento e devolve a reserva à fila de pagamento de outros.')
                ->action(function (OrdemPagamento $record) {
                    app(DeleteOtherPaymentOrder::class)($record);
                    Notification::make()->title('Registro excluído com sucesso.')->success()->send();

                    return redirect(OtherPaymentOrderResource::getUrl());
                }),
            Action::make('alterarStatus')
                ->label('Alterar status')
                ->icon(Heroicon::OutlinedArrowPath)
                ->modalWidth('sm')
                ->fillForm(fn (OrdemPagamento $record) => ['status' => $record->status])
                ->schema([
                    Select::make('status')
                        ->label('Status do pagamento')
                        ->options(FinPaymentOrder::STATUSES)
                        ->required(),
                ])
                ->action(function (array $data, OrdemPagamento $record, Action $action) {
                    try {
                        app(EditPaymentStatus::class)($record, (int) $data['status']);
                    } catch (DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();
                    }

                    Notification::make()->title('Status atualizado.')->success()->send();
                }),
        ];
    }

    /** Um bloco por parte (Due To com item ligado) e um campo por moeda estrangeira da ordem, como o formulário do legado. */
    private function recalculationSchema(OrdemPagamento $order): array
    {
        $parties = app(OtherOrderParties::class);
        $blocks = [];

        foreach ($parties->forOrder($order) as $dueTo => $party) {
            $blocks[] = Section::make("{$party['label']}")
                ->columns(3)
                ->schema([
                    Select::make("parties.{$dueTo}.id")
                        ->label("Razão Social ({$party['label']})")
                        ->searchable()
                        ->live()
                        ->getSearchResultsUsing(fn (string $search) => app(OtherOrderParties::class)->search($party['class'], $search))
                        ->getOptionLabelUsing(fn ($value) => app(OtherOrderParties::class)->lookup($party['class'], (int) $value)['name'] ?? null)
                        ->afterStateUpdated(function ($state, Set $set) use ($dueTo, $party) {
                            $data = $state ? app(OtherOrderParties::class)->lookup($party['class'], (int) $state) : null;
                            $set("parties.{$dueTo}.name", $data['name'] ?? null);
                            $set("parties.{$dueTo}.cnpj", $data['cnpj'] ?? null);
                            $set("parties.{$dueTo}.bank", $data['bank'] ?? null);
                        }),
                    TextInput::make("parties.{$dueTo}.cnpj")
                        ->label("CNPJ ({$party['label']})"),
                    Textarea::make("parties.{$dueTo}.bank")
                        ->label("Dados Bancários ({$party['label']})")
                        ->required()
                        ->rows(3),
                    TextInput::make("parties.{$dueTo}.name")
                        ->label('Nome do recebedor')
                        ->columnSpanFull(),
                ]);
        }

        $currencies = DB::table('ordem_pagamento_item as i')
            ->join('cmn_currencies as c', 'c.id', '=', 'i.ref_currency')
            ->where('i.ordem_pagamento_id', $order->getKey())
            ->where('i.ref_currency', '<>', 1)
            ->distinct()
            ->orderBy('c.name')
            ->get(['c.id', 'c.name', 'c.symbol']);

        foreach ($currencies as $currency) {
            $blocks[] = TextInput::make("rates.{$currency->id}")
                ->label("{$currency->name} ({$currency->symbol})")
                ->numeric()
                ->required();
        }

        return [...$blocks, Toggle::make('reset')->label('Resetar valores')->helperText('Refaz valor unitário, moeda e índice a partir das despesas da reserva.')];
    }
}
