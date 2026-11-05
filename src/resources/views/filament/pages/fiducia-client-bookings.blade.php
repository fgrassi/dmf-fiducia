@php
    $int = fn ($value) => number_format((float) $value, 0, ',', '.');
    $avg = fn ($value) => number_format((float) $value, 2, ',', '.');
    $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
    $th = 'padding: 6px 8px; background: #5d5950; color: #fff; font-weight: 600; text-align: left;';
    $td = 'padding: 4px 8px; border-bottom: 1px solid rgba(128, 128, 128, .3);';
    $right = 'text-align: right;';

    $profit = function (array $row) use ($money) {
        if (! $row['tem_lucro']) {
            return '-';
        }

        $hint = 'Receitas estimadas: '.$money($row['receitas_estimadas']).'; despesas estimadas: '.$money($row['despesas_estimadas']);

        return e($money($row['lucratividade'])).($row['lucratividade_provisoria'] ? ' <sup title="'.e($hint).'">*</sup>' : '');
    };
@endphp

@if ($report === null)
    {{-- nada consultado ainda --}}
@else
    @php
        $byMonth = $report['agrupar_por_mes'];
        $effective = $report['campo_data'] === 'chegada_efetiva' ? 'data efetiva de chegada' : 'data efetiva de embarque';
        $planned = $report['campo_data'] === 'chegada_efetiva' ? 'ETA' : 'ETD';
        $tables = $byMonth ? $report['grupos'] : [['label' => null, 'rows' => $report['rows'], 'totais' => $report['totais']]];
    @endphp

    <div style="overflow-x: auto; font-size: .875rem;">
        @if (! $byMonth)
            <p style="margin-bottom: 8px;">Período: {{ $report['qtd_meses'] }} mês(es) — médias = total ÷ {{ $report['qtd_meses'] }}</p>
        @endif

        @forelse ($tables as $table)
            @if ($table['label'])
                <h4 style="margin: 16px 0 8px; font-weight: 600;">{{ $table['label'] }}</h4>
            @endif

            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="{{ $th }}"></th>
                        <th colspan="2" style="{{ $th }} text-align: center;">Emb. confirmados</th>
                        <th colspan="2" style="{{ $th }} text-align: center;">Emb. não confirmados</th>
                        <th colspan="2" style="{{ $th }} text-align: center;">Totais</th>
                        <th style="{{ $th }}"></th>
                    </tr>
                    <tr>
                        <th style="{{ $th }}">Cliente</th>
                        @foreach (['Embarques', 'Equipamentos', 'Embarques', 'Equipamentos', 'Embarques', 'Equipamentos'] as $label)
                            <th style="{{ $th }} {{ $right }}">{{ $label }}</th>
                        @endforeach
                        <th style="{{ $th }} {{ $right }}">Lucratividade</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($table['rows'] as $row)
                        <tr>
                            <td style="{{ $td }}">{{ $row['cliente_nome'] }}</td>
                            @foreach (['emb_confirmados', 'eq_confirmados', 'emb_nao_confirmados', 'eq_nao_confirmados'] as $key)
                                <td style="{{ $td }} {{ $right }}">
                                    {{ $int($row[$key]) }}
                                    @if (! $byMonth)
                                        <span style="opacity: .75;">(méd. {{ $avg($row['media_'.$key]) }})</span>
                                    @endif
                                </td>
                            @endforeach
                            <td style="{{ $td }} {{ $right }}">{{ $int($row['emb_confirmados'] + $row['emb_nao_confirmados']) }}</td>
                            <td style="{{ $td }} {{ $right }}">{{ $int($row['eq_confirmados'] + $row['eq_nao_confirmados']) }}</td>
                            <td style="{{ $td }} {{ $right }}">{!! $profit($row) !!}</td>
                        </tr>

                        @if ($report['listar_embarques'] && $row['detalhes'] !== [])
                            <tr>
                                <td colspan="8" style="padding: 6px 10px; background: rgba(128, 128, 128, .1);">
                                    <table style="width: 100%; font-size: .75rem;">
                                        <thead>
                                            <tr>
                                                <th style="text-align: left;">Booking</th>
                                                <th style="text-align: left;">Status</th>
                                                <th style="{{ $right }}">Equipamentos</th>
                                                <th style="text-align: left;">Capa</th>
                                                <th style="{{ $right }}">Lucratividade</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($row['detalhes'] as $detail)
                                                <tr>
                                                    <td>
                                                        <a href="{{ \App\Filament\Resources\Embarques\EmbarqueResource::getUrl('view', ['record' => $detail['embarque_id']]) }}" target="_blank" rel="noopener noreferrer" style="font-weight: 600; color: #0b5cab;">{{ $detail['booking'] }}</a>
                                                    </td>
                                                    <td>{{ $detail['confirmado'] ? 'Confirmado' : 'Não confirmado' }}</td>
                                                    <td style="{{ $right }}">{{ $int($detail['equipamentos']) }}</td>
                                                    <td>
                                                        @forelse ($detail['capas'] as $cover)
                                                            <span style="display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; border-radius: 12px; background: rgba(128, 128, 128, .2);">{{ $cover['numero'] }} ({{ $cover['arquivo'] }})</span>
                                                        @empty
                                                            -
                                                        @endforelse
                                                    </td>
                                                    <td style="{{ $right }}">{!! $profit($detail) !!}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        @endif
                    @endforeach

                    @if ($table['rows'] === [])
                        <tr><td colspan="8" style="{{ $td }}">Nenhum cliente Fiducia encontrado para o filtro informado.</td></tr>
                    @else
                        @php $total = $table['totais']; @endphp
                        <tr>
                            <th style="{{ $th }}">{{ $byMonth ? 'Subtotal' : 'Total' }}</th>
                            @foreach (['emb_confirmados', 'eq_confirmados', 'emb_nao_confirmados', 'eq_nao_confirmados'] as $key)
                                <th style="{{ $th }} {{ $right }}">
                                    {{ $int($total[$key]) }}
                                    @if (! $byMonth)
                                        <span style="font-weight: normal; opacity: .85;">(méd. {{ $avg($total['media_'.$key]) }})</span>
                                    @endif
                                </th>
                            @endforeach
                            <th style="{{ $th }} {{ $right }}">{{ $int($total['emb_confirmados'] + $total['emb_nao_confirmados']) }}</th>
                            <th style="{{ $th }} {{ $right }}">{{ $int($total['eq_confirmados'] + $total['eq_nao_confirmados']) }}</th>
                            <th style="{{ $th }} {{ $right }}">{!! $profit($total) !!}</th>
                        </tr>
                    @endif
                </tbody>
            </table>
        @empty
            <p>Nenhum embarque encontrado para o filtro informado.</p>
        @endforelse

        @if ($byMonth && $report['grupos'] !== [])
            @php $total = $report['totais']; @endphp
            <h4 style="margin: 20px 0 8px; font-weight: 600;">Total geral</h4>
            <table style="width: 100%; border-collapse: collapse;">
                <tbody>
                    <tr>
                        <th style="{{ $th }}">Total</th>
                        @foreach (['emb_confirmados', 'eq_confirmados', 'emb_nao_confirmados', 'eq_nao_confirmados'] as $key)
                            <th style="{{ $th }} {{ $right }}">
                                {{ $int($total[$key]) }}
                                <span style="font-weight: normal; opacity: .85;">(méd. {{ $avg($total['media_'.$key]) }})</span>
                            </th>
                        @endforeach
                        <th style="{{ $th }} {{ $right }}">{{ $int($total['emb_confirmados'] + $total['emb_nao_confirmados']) }}</th>
                        <th style="{{ $th }} {{ $right }}">{{ $int($total['eq_confirmados'] + $total['eq_nao_confirmados']) }}</th>
                        <th style="{{ $th }} {{ $right }}">{!! $profit($total) !!}</th>
                    </tr>
                </tbody>
            </table>
            <p style="margin-top: 8px;">Médias do total geral = total ÷ {{ $report['qtd_meses'] }} mês(es)</p>
        @endif

        <div style="margin-top: 12px; font-size: .75rem; line-height: 1.5;">
            <strong>Observações:</strong>
            <ul style="margin: 6px 0 0 18px; list-style: disc;">
                <li>Confirmados: filtro pela {{ $effective }}</li>
                <li>Não confirmados: sem data efetiva; filtro pela {{ $planned }}</li>
                <li>Embarques não confirmados usam a lucratividade provisória do resumo de custos e receitas da reserva</li>
                @if ($report['totais']['lucratividade_provisoria'])
                    <li>
                        * Lucratividade provisória: soma o resultado das capas existentes com
                        {{ $money($report['totais']['receitas_estimadas']) }} em receitas e
                        {{ $money($report['totais']['despesas_estimadas']) }} em despesas ainda não lançadas em capa.
                        Conversões em dólar usam a Taxa Diária de
                        {{ $report['totais']['data_cotacao_dolar'] ? date('d/m/Y', strtotime($report['totais']['data_cotacao_dolar'])) : '-' }}
                        ({{ number_format((float) $report['totais']['cotacao_dolar'], 4, ',', '.') }}).
                    </li>
                @endif
            </ul>
        </div>
    </div>
@endif
