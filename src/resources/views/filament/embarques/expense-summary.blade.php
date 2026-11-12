{{-- Rodapé da aba Despesas (embarqueExpenses do legado): resumo de custos e receitas, obs. do embarque e frete acordado HBL. --}}
@php
    $booking = $getLivewire()->getOwnerRecord();
    $summary = app(\App\Actions\Booking\ExpenseSummary::class)($booking);
    $money = fn ($value) => number_format($value, 2, ',', '.');
    $color = fn ($value) => $value < 0 ? '#dc2626' : '#2563eb';
    $quotes = collect($summary['rows'])->filter(fn ($row) => $row['quote'] && $row['currency'] !== 'R$')->mapWithKeys(fn ($row) => [$row['currency'] => $row['quote']]);
    $freight = $booking->impor_expor === 'i' ? \Illuminate\Support\Facades\DB::table('valor_frete_embarque as f')
        ->leftJoin('usuario as u', 'u.usuario_id', '=', 'f.created_by')->where('f.embarque_id', $booking->getKey())->first(['f.valor', 'f.created_at', 'u.nome as author']) : null;
@endphp

<style>
    .expense-summary { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
    .expense-summary th, .expense-summary td { padding: 0.35rem 0.6rem; text-align: left; }
    .expense-summary thead th, .expense-summary tfoot td { border-bottom: 1px dotted currentColor; }
    .expense-summary .num { text-align: right; white-space: nowrap; }
</style>

<div style="display: grid; gap: 1.5rem">
    <x-filament::section heading="Resumo de Custos e Receitas">
        @if ($summary['rows'])
            <div style="overflow-x: auto">
                <table class="expense-summary">
                    <thead>
                        <tr>
                            <th>Pago à</th>
                            <th>Moeda</th>
                            <th class="num">Custo</th>
                            <th class="num">Venda</th>
                            <th class="num">Saldo</th>
                            <th class="num">Saldo em R$</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($summary['rows'] as $row)
                            <tr>
                                <td>{{ $row['to'] }}</td>
                                <td>{{ $row['currency'] }}</td>
                                <td class="num" style="color: #dc2626">{{ $money($row['cost']) }}</td>
                                <td class="num" style="color: #16a34a">{{ $money($row['sale']) }}</td>
                                <td class="num" style="font-weight: 700; color: {{ $color($row['balance']) }}">{{ $money($row['balance']) }}</td>
                                <td class="num" style="font-weight: 700; color: {{ $color($row['balance']) }}"
                                    @if ($row['quote']) title="{{ $row['quote']['name'] }}{{ $row['quote']['date'] ? ' em '.\Illuminate\Support\Carbon::parse($row['quote']['date'])->format('d/m/Y') : '' }}: {{ number_format($row['quote']['value'], 4, ',', '.') }}" @endif>
                                    @if ($row['balance_brl'] !== null)
                                        R$ {{ $money($row['balance_brl']) }}
                                    @else
                                        <span style="color: #dc2626">Taxa não encontrada</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" class="num" style="font-weight: 700">{{ $summary['complete'] ? 'Total do saldo em R$:' : 'Total parcial do saldo em R$:' }}</td>
                            <td class="num" style="font-weight: 700; color: {{ $color($summary['total']) }}">R$ {{ $money($summary['total']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if ($quotes->isNotEmpty())
                <div style="margin-top: 0.75rem; font-size: 0.875rem">
                    <strong>Cotações utilizadas:</strong>
                    <ul style="list-style: disc; padding-left: 1.5rem">
                        @foreach ($quotes as $currency => $quote)
                            <li>Conversões em {{ $currency }} usam {{ $quote['name'] }}{{ $quote['date'] ? ' de '.\Illuminate\Support\Carbon::parse($quote['date'])->format('d/m/Y') : '' }} (R$ {{ number_format($quote['value'], 4, ',', '.') }}).</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @else
            <p style="font-size: 0.875rem; opacity: 0.7">Nenhuma despesa com Due To informado.</p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Obs. Embarque">
        @if (filled($booking->embarque_obs))
            <div style="font-size: 0.875rem">{!! nl2br(e($booking->embarque_obs)) !!}</div>
        @else
            <em style="font-size: 0.875rem; color: #be185d">N/D</em>
        @endif
    </x-filament::section>

    @if ($booking->impor_expor === 'i')
        <x-filament::section heading="Frete Acordado HBL">
            @if ($freight)
                <div style="overflow-x: auto">
                    <table class="expense-summary">
                        <thead>
                            <tr>
                                <th>Criado em</th>
                                <th>Por</th>
                                <th>Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>{{ \Illuminate\Support\Carbon::parse($freight->created_at)->format('d/m/Y') }}</td>
                                <td>{{ $freight->author }}</td>
                                <td>{!! nl2br(e(trim($freight->valor))) !!}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @else
                <p style="font-size: 0.875rem; opacity: 0.7">Frete acordado ainda não calculado.</p>
            @endif
        </x-filament::section>
    @endif
</div>
