<?php

namespace App\Filament\Resources\OtherPaymentOrders\Tables;

use App\Models\FinPaymentOrder;
use App\Models\OrdemPagamento;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OtherPaymentOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('ordem_pagamento.*')
                ->addSelect([
                    'items_total' => DB::table('ordem_pagamento_item as i')->selectRaw('COALESCE(SUM(i.item_value), 0)')->whereColumn('i.ordem_pagamento_id', 'ordem_pagamento.ordem_pagamento_id'),
                    'client_name' => DB::table('embarque as e')->join('pessoa as p', 'p.pessoa_id', '=', 'e.cliente_id')->select('p.nome')->whereColumn('e.embarque_id', 'ordem_pagamento.ref_embarque'),
                    'last_charge_status' => DB::table('fin_charges as c')->select('c.status')->whereColumn('c.ref_booking', 'ordem_pagamento.ref_embarque')->orderByDesc('c.id')->limit(1),
                ])
                ->with('embarque'))
            ->defaultSort('ordem_pagamento_id', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('embarque.booking')
                    ->label('Booking'),
                TextColumn::make('embarque.house_bl')
                    ->label('HBL'),
                TextColumn::make('client_name')
                    ->label('Cliente'),
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
                    ->state(fn (OrdemPagamento $record) => self::billingState($record))
                    ->icon(fn (?string $state) => $state ? 'heroicon-s-check-circle' : null)
                    ->color(fn (?string $state) => ['paid' => 'success', 'open' => 'danger', 'waiting' => 'gray'][$state] ?? 'gray')
                    ->tooltip(fn (OrdemPagamento $record) => ['paid' => 'Cobranças pagas', 'open' => 'Cobrança em aberto', 'waiting' => 'Aguardando cobrança'][self::billingState($record)] ?? null),
            ])
            ->filters([
                Filter::make('booking')
                    ->schema([TextInput::make('booking')->label('Booking')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['booking'], fn (Builder $q, $booking) => $q->whereHas('embarque', fn (Builder $e) => $e->where('booking', 'like', "%{$booking}%")))),
                Filter::make('hbl')
                    ->schema([TextInput::make('hbl')->label('HBL')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['hbl'], fn (Builder $q, $hbl) => $q->whereHas('embarque', fn (Builder $e) => $e->where('house_bl', 'like', "%{$hbl}%")))),
                Filter::make('mbl')
                    ->schema([TextInput::make('mbl')->label('MBL')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['mbl'], fn (Builder $q, $mbl) => $q->whereHas('embarque', fn (Builder $e) => $e
                        ->where('master_bl', 'like', "%{$mbl}%")
                        ->orWhereIn('embarque_id', DB::table('bl')->select('embarque_id')->where('mbl_number', 'like', "%{$mbl}%"))))),
                SelectFilter::make('cliente')
                    ->label('Cliente')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => DB::table('pessoa')->where('nome', 'like', "%{$search}%")->orderBy('nome')->limit(30)->pluck('nome', 'pessoa_id')->all())
                    ->getOptionLabelUsing(fn ($value) => DB::table('pessoa')->where('pessoa_id', $value)->value('nome'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn (Builder $q, $client) => $q->whereHas('embarque', fn (Builder $e) => $e->where('cliente_id', $client)))),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(FinPaymentOrder::STATUSES),
                Filter::make('created')
                    ->label('Data de criação')
                    ->schema([DatePicker::make('from')->label('Criada de'), DatePicker::make('until')->label('Criada até')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'], fn (Builder $q, $date) => $q->whereDate('ordem_pagamento.created_at', '>=', $date))
                        ->when($data['until'], fn (Builder $q, $date) => $q->whereDate('ordem_pagamento.created_at', '<=', $date))),
                Filter::make('paid')
                    ->label('Data de pagamento')
                    ->schema([DatePicker::make('from')->label('Paga de'), DatePicker::make('until')->label('Paga até')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'], fn (Builder $q, $date) => $q->whereDate('ordem_pagamento.payment_date', '>=', $date))
                        ->when($data['until'], fn (Builder $q, $date) => $q->whereDate('ordem_pagamento.payment_date', '<=', $date))),
                Filter::make('recebedor')
                    ->schema([TextInput::make('recebedor')->label('Recebedor')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['recebedor'], fn (Builder $q, $name) => $q->whereIn('ordem_pagamento.ordem_pagamento_id', DB::table('instrucao_pagamento_outros')->select('ordem_pagamento_id')
                        ->where(fn ($w) => $w->where('recebedor', 'like', "%{$name}%")->orWhere('cnpj_recebedor', 'like', "%{$name}%"))))),
                SelectFilter::make('capa')
                    ->label('Arquivo de capa')
                    ->options(fn () => DB::table('capa_processo_arquivo')->orderByDesc('capa_processo_arquivo_id')->pluck('nome', 'capa_processo_arquivo_id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn (Builder $q, $file) => $q->whereExists(fn ($c) => $c->selectRaw('1')->from('capa_processo as cp')
                        ->whereColumn('cp.embarque_id', 'ordem_pagamento.ref_embarque')->where('cp.capa_processo_arquivo_id', $file)))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * Indicador da cobrança da reserva (OrdemPagamento::getStatusColor, legado): olha só a última cobrança da reserva
     * (o laço do legado recomeça a cada cobrança). Exportação: verde se paga, vermelho se não; importação: verde ou cinza.
     *
     * @return ?string paid | open | waiting
     */
    private static function billingState(OrdemPagamento $order): ?string
    {
        $operation = $order->embarque?->impor_expor;

        if (! in_array($operation, ['e', 'i'], true)) {
            return null;
        }

        return match (true) {
            $order->last_charge_status !== null && (int) $order->last_charge_status !== 0 => 'paid',
            $operation === 'e' => 'open',
            default => 'waiting',
        };
    }
}
