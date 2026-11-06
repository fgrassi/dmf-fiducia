@php
    $money = fn ($value) => number_format((float) $value, 2, ',', '.');
    $date = fn ($value) => filled($value) && ($time = strtotime($value)) ? date('d/m/Y', $time) : '';
    $th = 'padding: 6px 8px; background: #5d5950; color: #fff; font-weight: 600; text-align: left;';
    $td = 'padding: 4px 8px; border-bottom: 1px solid rgba(128, 128, 128, .3);';
@endphp

@if ($report === null)
    {{-- nada consultado ainda --}}
@elseif ($report['rows'] === [])
    <p>Nenhum registro encontrado para o período informado.</p>
@else
    <div style="overflow-x: auto; font-size: .875rem;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="{{ $th }}">Reserva</th>
                    <th style="{{ $th }}">Data</th>
                    <th style="{{ $th }}">Item</th>
                    <th style="{{ $th }} text-align: right;">Compra</th>
                    <th style="{{ $th }} text-align: right;">Venda</th>
                    <th style="{{ $th }} text-align: right;">Despesa</th>
                    <th style="{{ $th }} text-align: right;">Receita</th>
                    <th style="{{ $th }} text-align: right;">Resultado</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['rows'] as $row)
                    <tr>
                        <th style="{{ $td }} text-align: left;">{{ $row['booking'] }}</th>
                        <td style="{{ $td }}">{{ $date($row['data_despesa']) }}</td>
                        <td style="{{ $td }}">{{ $row['nome_despesa'] }}</td>
                        <td style="{{ $td }} text-align: right;">{{ $row['moeda_compra'] }} {{ $money($row['despesa']) }}</td>
                        <td style="{{ $td }} text-align: right;">{{ $row['moeda_venda'] }} {{ $money($row['receita']) }}</td>
                        <td style="{{ $td }} text-align: right;">R$ {{ $money($row['despesa_rs']) }}</td>
                        <td style="{{ $td }} text-align: right;">R$ {{ $money($row['receita_rs']) }}</td>
                        <td style="{{ $td }} text-align: right;">R$ {{ $money($row['resultado']) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <th style="{{ $th }}" colspan="5">Total</th>
                    <th style="{{ $th }} text-align: right;">R$ {{ $money($report['totais']['despesa']) }}</th>
                    <th style="{{ $th }} text-align: right;">R$ {{ $money($report['totais']['receita']) }}</th>
                    <th style="{{ $th }} text-align: right;">R$ {{ $money($report['totais']['resultado']) }}</th>
                </tr>
            </tbody>
        </table>
    </div>
@endif
