@php
    $money = fn ($value) => number_format((float) $value, 2, ',', '.');
    $date = fn ($value) => $value !== '' && $value !== null && ($time = strtotime($value)) ? date('d/m/Y', $time) : '';
    $th = 'padding: 6px 8px; background: #5d5950; color: #fff; font-weight: 600; text-align: left;';
    $td = 'padding: 4px 8px; border-bottom: 1px solid rgba(128, 128, 128, .3);';
@endphp

@if ($report === null)
    {{-- nada consultado ainda --}}
@elseif ($report['grupos'] === [])
    <p>Nenhum registro encontrado para os filtros informados.</p>
@else
    <div style="overflow-x: auto; font-size: .875rem;">
        <table style="width: 100%; margin-bottom: 24px; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="{{ $th }}">Despesa</th>
                    <th style="{{ $th }}">Tipo</th>
                    <th style="{{ $th }} text-align: center;">Reservas</th>
                    <th style="{{ $th }} text-align: right;">Cobrado</th>
                    <th style="{{ $th }} text-align: right;">Pago</th>
                    <th style="{{ $th }} text-align: right;">Resultado</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['grupos'] as $group)
                    <tr>
                        <td style="{{ $td }}">{{ $group['despesa_nome'] }}</td>
                        <td style="{{ $td }}">{{ $group['despesa_tipo'] }}</td>
                        <td style="{{ $td }} text-align: center;">{{ $group['reservas'] }}</td>
                        <td style="{{ $td }} text-align: right;">{{ $money($group['cobrado']) }}</td>
                        <td style="{{ $td }} text-align: right;">{{ $money($group['pago']) }}</td>
                        <td style="{{ $td }} text-align: right;">{{ $money($group['resultado']) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <th style="{{ $th }}">Total</th>
                    <th style="{{ $th }}"></th>
                    <th style="{{ $th }} text-align: center;">{{ $report['totais']['reservas'] }}</th>
                    <th style="{{ $th }} text-align: right;">{{ $money($report['totais']['cobrado']) }}</th>
                    <th style="{{ $th }} text-align: right;">{{ $money($report['totais']['pago']) }}</th>
                    <th style="{{ $th }} text-align: right;">{{ $money($report['totais']['resultado']) }}</th>
                </tr>
            </tbody>
        </table>

        @foreach ($report['grupos'] as $group)
            <h4 style="margin: 20px 0 8px; font-weight: 600;">{{ $group['titulo'] }}</h4>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="{{ $th }}">Booking</th>
                        <th style="{{ $th }}">Cliente</th>
                        <th style="{{ $th }} text-align: center;">ETD</th>
                        <th style="{{ $th }} text-align: center;">ETA</th>
                        <th style="{{ $th }} text-align: center;">Data da cobrança</th>
                        <th style="{{ $th }} text-align: center;">Data do pagamento</th>
                        <th style="{{ $th }} text-align: right;">Cobrado</th>
                        <th style="{{ $th }} text-align: right;">Pago</th>
                        <th style="{{ $th }} text-align: right;">Resultado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($group['rows'] as $row)
                        <tr>
                            <td style="{{ $td }}">{{ $row['booking'] }}</td>
                            <td style="{{ $td }}">{{ $row['cliente_nome'] }}</td>
                            <td style="{{ $td }} text-align: center;">{{ $date($row['etd']) }}</td>
                            <td style="{{ $td }} text-align: center;">{{ $date($row['eta']) }}</td>
                            <td style="{{ $td }} text-align: center;">{{ $date($row['data_cobranca']) }}</td>
                            <td style="{{ $td }} text-align: center;">{{ $date($row['data_pagamento']) }}</td>
                            <td style="{{ $td }} text-align: right;">{{ $money($row['cobrado']) }}</td>
                            <td style="{{ $td }} text-align: right;">{{ $money($row['pago']) }}</td>
                            <td style="{{ $td }} text-align: right;">{{ $money($row['resultado']) }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <th colspan="6" style="{{ $th }} text-align: right;">Subtotal ({{ $group['reservas'] }} reservas)</th>
                        <th style="{{ $th }} text-align: right;">{{ $money($group['cobrado']) }}</th>
                        <th style="{{ $th }} text-align: right;">{{ $money($group['pago']) }}</th>
                        <th style="{{ $th }} text-align: right;">{{ $money($group['resultado']) }}</th>
                    </tr>
                </tbody>
            </table>
        @endforeach
    </div>
@endif
