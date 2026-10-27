{{-- Itens da Cobrança: mesma tabela do showSuccess legado (itens, desconto, IOF e total no rodapé). --}}
@php($t = $getState())
<div style="overflow-x: auto">
    <table class="charge-items">
        <thead>
            <tr>
                <th>Despesa</th>
                <th>Taxa (moeda)</th>
                <th>Valor Unitário</th>
                <th>Índice</th>
                <th>Valor do Item</th>
                <th>Isenta IOF</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($t['items'] as $item)
                <tr>
                    <td>{{ $item['expense'] }}</td>
                    <td class="num">{{ $item['rate'] }}</td>
                    <td class="num">{{ $item['unit'] }}</td>
                    <td class="num">{{ $item['index'] }}</td>
                    <td class="num">{{ $item['value'] }}</td>
                    <td class="center">{{ $item['exempt_iof'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="center">Nenhum item.</td></tr>
            @endforelse
            @if ($t['discount'])
                <tr>
                    <td colspan="4">Desconto</td>
                    <td class="num">( {{ $t['discount'] }})</td>
                    <td></td>
                </tr>
            @endif
        </tbody>
        <tfoot>
            <tr class="iof">
                <th colspan="4">Despesas Financeiras/IOF</th>
                <th class="num">
                    {{ $t['iof'] }}
                    <x-filament::icon-button
                        icon="heroicon-o-pencil-square"
                        size="sm"
                        label="Alterar IOF"
                        wire:click="mountAction('editarIof')"
                    />
                </th>
                <th class="center">
                    <label class="iof-manual" title="Ligar/desligar o controle manual do valor do IOF">
                        <x-filament::input.checkbox
                            :checked="$t['iof_manual']"
                            wire:click="mountAction('alternarIofManual')"
                        />
                        IOF manual
                    </label>
                </th>
            </tr>
            <tr class="total">
                <th colspan="4">Total</th>
                <th class="num">{{ $t['total'] }}</th>
                <th></th>
            </tr>
        </tfoot>
    </table>
</div>

<style>
    .charge-items { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
    .charge-items th, .charge-items td { padding: 0.35rem 0.6rem; text-align: left; }
    .charge-items .num { text-align: right; white-space: nowrap; }
    .charge-items .center { text-align: center; }
    .charge-items .iof-manual { display: inline-flex; align-items: center; gap: 0.35rem; font-weight: 400; cursor: pointer; white-space: nowrap; }
    .charge-items thead th { background: #474b4e; color: #fff; text-align: center; font-weight: 600; }
    .charge-items tbody tr:nth-child(even) { background: rgba(0, 0, 0, 0.04); }
    .charge-items tfoot .iof th { font-weight: 600; border-top: 1px solid rgba(0, 0, 0, 0.15); }
    .charge-items tfoot .total th { background: #474b4e; color: #fff; font-weight: 700; }
    .dark .charge-items tbody tr:nth-child(even) { background: rgba(255, 255, 255, 0.05); }
    .dark .charge-items tfoot .iof th { border-top-color: rgba(255, 255, 255, 0.15); }
</style>
