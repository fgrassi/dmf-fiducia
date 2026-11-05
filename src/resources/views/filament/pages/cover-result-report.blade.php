{{-- capasContabilidade/relatorio (legado): lista de capas com subtotais, resumo por despesa, resumo por escritório e ranking de clientes. --}}
@php
    use App\Actions\AccountingCovers\CoverResultReport;
    use App\Filament\Resources\Embarques\EmbarqueResource;
    use App\Models\Embarque;

    $money = fn ($value) => number_format((float) $value, 2, ',', '.');
    $th = 'px-2 py-1 text-left font-semibold whitespace-nowrap';
    $td = 'px-2 py-1 align-top whitespace-pre-line';
    $nowrap = 'px-2 py-1 align-top whitespace-nowrap';
    $num = 'px-2 py-1 align-top text-right whitespace-nowrap';
    $table = 'w-full text-xs divide-y divide-gray-200 dark:divide-white/10';
    $head = 'bg-gray-950 text-white dark:bg-gray-800';
    $box = 'overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
@endphp

@if ($report !== null)
    @if (($options['show_list'] ?? false))
        @php
            $columns = ['Capa', 'Nº', 'Reserva', 'HBL', 'Cliente', 'Tipo', 'Impo/Expo', 'Mercadoria', 'País Origem', 'Origem', 'País Destino', 'Destino', 'Data Pagamento', 'Armador', 'Cia Aérea', 'Agente', 'Vendedor', 'Centro Custo', 'Responsável Fechamento', 'Cotação'];
            $sumColumns = ['USD', 'Tx. Conv.', 'R$', 'EU', 'Tx. Conv.', 'Custos Financeiros', 'DEM/DET/COU/SEG'];
            $condensable = ['Cliente', 'Tipo', 'Impo/Expo', 'Mercadoria', 'País Origem', 'Origem', 'País Destino', 'Data Pagamento', 'Armador', 'Cia Aérea'];
            $previous = 0;
            $count = 0;
        @endphp
        <div class="mt-4" x-data="{ condensed: false }" x-bind:class="condensed && '[&_.condensable]:hidden'">
            <x-filament::button color="gray" size="sm" x-on:click="condensed = ! condensed">
                <span x-text="condensed ? 'Expandir' : 'Condensar'"></span>
            </x-filament::button>
        <div class="{{ $box }} mt-2 max-h-[80vh] overflow-y-auto">
            <table class="{{ $table }}">
                <thead class="{{ $head }} sticky top-0 z-10">
                    <tr>
                        <th class="{{ $th }}">#</th>
                        @foreach ($columns as $column)
                            <th @class([in_array($column, ['Centro Custo', 'Responsável Fechamento']) ? 'px-2 py-1 text-left font-semibold' : $th, 'condensable' => in_array($column, $condensable)])>{{ $column }}</th>
                        @endforeach
                        @foreach ($sumColumns as $column)
                            <th class="{{ $th }} text-right">{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($report['lines'] as $line)
                        @if (isset($line['subtotal']))
                            <tr class="bg-gray-200 font-semibold dark:bg-white/10">
                                <td class="{{ $td }}" colspan="{{ count($columns) + 1 }}" style="padding-left: {{ 0.5 + $line['level'] }}rem">{{ $line['subtotal'] }}</td>
                                <td class="{{ $num }}">{{ $money($line['sum']['usd']) }}</td>
                                <td class="{{ $num }}">{{ $money($line['sum']['tx_usd']) }}</td>
                                <td class="{{ $num }}">{{ $money($line['sum']['rs']) }}</td>
                                <td class="{{ $num }}">{{ $money($line['sum']['euro']) }}</td>
                                <td class="{{ $num }}">{{ $money($line['sum']['tx_euro']) }}</td>
                                <td class="{{ $num }}">{{ $money($line['sum']['financial_cost']) }}</td>
                                <td></td>
                            </tr>
                        @else
                            @php
                                $row = $line['row'];
                                $count++;
                                $gap = $row->cover_number && $row->cover_number - $previous > 1;
                                $previous = $row->cover_number ?: $previous;
                            @endphp
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="{{ $nowrap }}">{{ $count }}</td>
                                <td class="{{ $nowrap }}">{{ $row->numero_capa }}</td>
                                <td class="{{ $nowrap }}">
                                    @if ($row->capa_processo_id)
                                        <span @class(['bg-yellow-300 px-1 text-gray-950' => $gap])>{{ $row->cover_number }}</span>
                                    @endif
                                </td>
                                <td class="{{ $td }}">
                                    @if ($row->embarque_id)
                                        <a class="text-primary-600 hover:underline" href="{{ EmbarqueResource::getUrl('view', ['record' => $row->embarque_id]) }}" target="_blank">{{ $row->booking }}</a>
                                    @else
                                        {{ $row->booking }}
                                    @endif
                                </td>
                                <td class="px-2 py-1 align-top whitespace-pre">{{ preg_replace('/\s*\/\s*/', " /\n", (string) $row->house_bl) }}</td>
                                <td class="{{ $td }} condensable">{{ $row->client }}</td>
                                <td class="{{ $td }} condensable">{{ $row->capa_processo_id ? mb_strtoupper(Embarque::TYPES[$row->tipo_embarque] ?? '') : 'Capa Manual' }}</td>
                                <td class="{{ $td }} condensable">{{ $row->capa_processo_id ? Embarque::OPERATIONS[$row->operation] : '' }}</td>
                                <td class="{{ $td }} condensable">{{ $row->mercadoria }}</td>
                                <td class="{{ $td }} condensable">{{ $row->pais_origem }}</td>
                                <td class="{{ $td }} condensable">{{ $row->porto_origem }}</td>
                                <td class="{{ $td }} condensable">{{ $row->pais_destino }}</td>
                                <td class="{{ $td }}">{{ $row->porto_destino }}</td>
                                <td class="{{ $nowrap }} condensable">{{ $row->data_pagamento ? \Illuminate\Support\Carbon::parse($row->data_pagamento)->format('d/m/Y') : '' }}</td>
                                <td class="{{ $td }} condensable">{{ $row->armador }}</td>
                                <td class="{{ $td }} condensable">{{ $row->cia_aerea }}</td>
                                <td class="{{ $td }}">{{ $row->agent }}</td>
                                <td class="{{ $td }}">{{ $row->vendedor }}</td>
                                <td class="{{ $td }}">{{ $row->office }}</td>
                                <td class="{{ $td }}">{{ $row->closer }}</td>
                                <td class="{{ $td }}">{{ $row->quotation }}</td>
                                <td class="{{ $num }}">{{ $money($row->usd) }}</td>
                                <td class="{{ $num }}">{{ $money($row->tx_usd) }}</td>
                                <td class="{{ $num }}">{{ $money($row->rs) }}</td>
                                <td class="{{ $num }}">{{ $money($row->euro) }}</td>
                                <td class="{{ $num }}">{{ $money($row->tx_euro) }}</td>
                                <td class="{{ $num }}">{{ $money($row->financial_cost) }}</td>
                                <td class="{{ $td }} text-center">{{ $row->category }}</td>
                            </tr>
                        @endif
                    @empty
                        <tr><td class="{{ $td }}" colspan="{{ count($columns) + count($sumColumns) + 1 }}">Nenhum resultado.</td></tr>
                    @endforelse
                </tbody>
                @if ($count)
                    <tfoot class="{{ $head }} sticky bottom-0 z-10">
                        <tr>
                            <th class="{{ $th }}" colspan="{{ count($columns) + 1 }}">TOTAL</th>
                            <th class="{{ $num }}">{{ $money($report['total']['usd']) }}</th>
                            <th class="{{ $num }}">{{ $money($report['total']['tx_usd']) }}</th>
                            <th class="{{ $num }}">{{ $money($report['total']['rs']) }}</th>
                            <th class="{{ $num }}">{{ $money($report['total']['euro']) }}</th>
                            <th class="{{ $num }}">{{ $money($report['total']['tx_euro']) }}</th>
                            <th class="{{ $num }}">{{ $money($report['total']['financial_cost']) }}</th>
                            <th></th>
                        </tr>
                        <tr>
                            <th class="{{ $th }}" colspan="{{ count($columns) + 1 }}">TOTAL (R$ + conversões)</th>
                            <th class="{{ $num }}" colspan="2">{{ $money($report['total']['tx_usd'] + $report['total']['rs'] + $report['total']['tx_euro']) }}</th>
                            <th colspan="5"></th>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        </div>
    @endif

    <div class="{{ $box }} mt-4 md:w-3/5">
        <table class="{{ $table }}">
            <thead class="{{ $head }}">
                <tr><th class="{{ $th }}" colspan="4">Resumo de resultado por grupo</th></tr>
                <tr>
                    <th class="{{ $th }}">Grupo</th>
                    <th class="{{ $th }} text-right">Importação</th>
                    <th class="{{ $th }} text-right">Exportação</th>
                    <th class="{{ $th }} text-right">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach (CoverResultReport::CATEGORIES as $initials => $name)
                    <tr>
                        <td class="{{ $td }}">{{ $name }}</td>
                        <td class="{{ $num }}">R$ {{ $money($report['groups'][$initials]['i']) }}</td>
                        <td class="{{ $num }}">R$ {{ $money($report['groups'][$initials]['e']) }}</td>
                        <td class="{{ $num }}">R$ {{ $money($report['groups'][$initials]['i'] + $report['groups'][$initials]['e']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="{{ $head }}">
                @php
                    $imports = array_sum(array_column($report['groups'], 'i'));
                    $exports = array_sum(array_column($report['groups'], 'e'));
                @endphp
                <tr>
                    <th class="{{ $th }}">Total</th>
                    <th class="{{ $num }}">R$ {{ $money($imports) }}</th>
                    <th class="{{ $num }}">R$ {{ $money($exports) }}</th>
                    <th class="{{ $num }}">R$ {{ $money($imports + $exports) }}</th>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="{{ $box }} mt-4">
        <table class="{{ $table }}">
            <thead class="{{ $head }}">
                <tr><th class="{{ $th }}" colspan="12">Resumo por escritório</th></tr>
                <tr>
                    <th class="{{ $th }}">Impo/Expo</th>
                    <th class="{{ $th }}">Escritório</th>
                    <th class="{{ $th }} text-right">USD</th>
                    <th class="{{ $th }} text-right">Vlr convertido R$</th>
                    <th class="{{ $th }} text-right">R$</th>
                    <th class="{{ $th }} text-right">EUR</th>
                    <th class="{{ $th }} text-right">Vlr convertido R$</th>
                    @foreach (array_keys(CoverResultReport::CATEGORIES) as $initials)
                        <th class="{{ $th }} text-right">{{ $initials }}</th>
                    @endforeach
                    <th class="{{ $th }} text-right">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach (['e' => 'Exportação', 'i' => 'Importação'] as $operation => $operationName)
                    @continue(empty($report['offices'][$operation]))
                    @php
                        $totals = array_fill_keys(['usd', 'tx_usd', 'rs', 'euro', 'tx_euro', ...array_keys(CoverResultReport::CATEGORIES)], 0.0);
                    @endphp
                    @foreach ($report['offices'][$operation] as $office => $values)
                        @php
                            foreach ($totals as $key => $total) {
                                $totals[$key] += $values[$key];
                            }
                        @endphp
                        <tr>
                            <td class="{{ $td }}">{{ $operationName }}</td>
                            <td class="{{ $td }}">{{ $office }}</td>
                            @foreach (['usd', 'tx_usd', 'rs', 'euro', 'tx_euro', ...array_keys(CoverResultReport::CATEGORIES)] as $key)
                                <td class="{{ $num }}">{{ $money($values[$key]) }}</td>
                            @endforeach
                            <td class="{{ $num }}">{{ $money($values['tx_usd'] + $values['rs'] + $values['tx_euro'] + $values['DEM'] + $values['DET'] + $values['COU'] + $values['SEG']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="{{ $head }} font-semibold">
                        <td class="{{ $th }}" colspan="2">Total {{ $operationName }}</td>
                        @foreach ($totals as $total)
                            <td class="{{ $num }}">{{ $money($total) }}</td>
                        @endforeach
                        <td class="{{ $num }}">{{ $money($totals['tx_usd'] + $totals['rs'] + $totals['tx_euro'] + $totals['DEM'] + $totals['DET'] + $totals['COU'] + $totals['SEG']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if (($options['show_ranking'] ?? false))
        @php
            $ranking = $report['ranking'];
            $resultTotal = array_sum($ranking);
        @endphp
        <div class="mt-4 grid gap-3 md:grid-cols-2">
            @foreach (['Ranking +' => $ranking, 'Ranking -' => array_reverse($ranking, true)] as $title => $list)
                <div class="{{ $box }}">
                    <table class="{{ $table }}"
                        @if ($loop->first)
                            wire:key="ranking-{{ md5(json_encode($list)) }}"
                            x-data="{
                                values: @js(array_values($list)),
                                checked: @js(array_fill(0, count($list), true)),
                                get total() {
                                    return this.values.reduce((sum, value, i) => sum + (this.checked[i] ? value : 0), 0)
                                },
                                toggleAll() {
                                    const on = ! this.checked.some(Boolean)
                                    this.checked = this.checked.map(() => on)
                                },
                            }"
                        @endif
                    >
                        <thead class="{{ $head }}">
                            <tr>
                                <th class="{{ $th }}" colspan="{{ $loop->first ? 2 : 4 }}">{{ $title }}</th>
                                @if ($loop->first)
                                    <th class="{{ $num }}" colspan="2">
                                        Total selecionado: R$ <span x-text="total.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span>
                                    </th>
                                @endif
                                @if ($loop->first)
                                    <th class="px-2 py-1"><input type="checkbox" :checked="checked.some(Boolean)" x-on:click.prevent="toggleAll()" /></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($list as $client => $value)
                                <tr>
                                    <td class="{{ $td }}">{{ $loop->iteration }}</td>
                                    <td class="{{ $td }}">{{ $client }}</td>
                                    <td class="{{ $num }}">R$ {{ $money($value) }}</td>
                                    <td class="{{ $num }}">{{ $money($resultTotal ? $value / $resultTotal * 100 : 0) }}%</td>
                                    @if ($title === 'Ranking +')
                                        <td class="px-2 py-1"><input type="checkbox" x-model="checked[{{ $loop->index }}]" /></td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    @endif
@endif
