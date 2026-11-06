@php
    $money = fn ($value) => number_format((float) $value, 2, ',', '.');
    $date = fn ($value) => filled($value) && ($time = strtotime($value)) ? date('d/m/Y', $time) : '';
    $th = 'padding: 6px 8px; background: #5d5950; color: #fff; font-weight: 600; text-align: left;';
    $td = 'padding: 4px 8px; border-bottom: 1px solid rgba(128, 128, 128, .3);';
@endphp

@if ($report === null)
    {{-- nada consultado ainda --}}
@elseif ($report['rows'] === [])
    <p>Nenhum recibo com IOF no período informado.</p>
@else
    <div style="overflow-x: auto; font-size: .875rem;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="{{ $th }}">Reserva</th>
                    <th style="{{ $th }}">HBL</th>
                    <th style="{{ $th }} text-align: right;">IOF (R$)</th>
                    <th style="{{ $th }}">Data Recebimento</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['rows'] as $row)
                    <tr>
                        <td style="{{ $td }}">{{ $row['booking'] }}</td>
                        <td style="{{ $td }}">{{ $row['bl'] }}</td>
                        <td style="{{ $td }} text-align: right;">{{ $money($row['valor_iof']) }}</td>
                        <td style="{{ $td }}">{{ $date($row['payment_date']) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <th style="{{ $th }}" colspan="2">Total</th>
                    <th style="{{ $th }} text-align: right;">{{ $money($report['total']) }}</th>
                    <th style="{{ $th }}"></th>
                </tr>
            </tbody>
        </table>
    </div>
@endif
