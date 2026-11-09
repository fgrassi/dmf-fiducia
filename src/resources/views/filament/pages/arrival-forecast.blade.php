@php
    $th = 'padding: 6px 8px; background: #5d5950; color: #fff; font-weight: 600; text-align: left; white-space: nowrap;';
    $td = 'padding: 4px 8px; border-bottom: 1px solid rgba(128, 128, 128, .3);';
    $money = fn ($symbol, $value) => trim("{$symbol} ".number_format($value, 2, ',', '.'));
    $date = fn ($value) => $value ? date('d/m/Y', strtotime($value)) : '';
@endphp

@if ($report === null)
    <p style="font-size: .875rem;">Informe o período para gerar o fluxo de caixa.</p>
@elseif ($report['sea'] === [] && $report['other'] === [])
    <p style="font-size: .875rem;">Nenhum embarque encontrado para o filtro informado.</p>
@endif

@foreach ([['sea', 'Armador'], ['other', 'Cia Aérea/Cia Seguro']] as [$key, $carrier])
    @foreach ($report[$key] ?? [] as $row)
        <div style="overflow-x: auto; font-size: .875rem; margin-bottom: 1.5rem;">
            <table style="width: 100%; border-collapse: collapse; margin-bottom: .5rem;">
                <tr>
                    @foreach (['Booking', 'Cliente', $carrier, 'ETD', 'ETA'] as $heading)
                        <th style="{{ $th }}">{{ $heading }}</th>
                    @endforeach
                </tr>
                <tr>
                    <td style="{{ $td }}">{{ $row['booking'] }}({{ $row['embarque_id'] }})</td>
                    <td style="{{ $td }}">{{ $row['cliente'] }}</td>
                    <td style="{{ $td }}">{{ $row['transportadora'] }}</td>
                    <td style="{{ $td }}">{{ $date($row['etd']) }}</td>
                    <td style="{{ $td }}">{{ $date($row['eta']) }}</td>
                </tr>
            </table>

            @foreach ($row['groups'] as $group)
                <table style="width: 100%; border-collapse: collapse; margin-bottom: .5rem;">
                    <tr>
                        <th colspan="7" style="{{ $th }}">{{ $group['title'] }}</th>
                    </tr>
                    <tr>
                        @foreach (['Despesa', 'Tipo', 'Fator', 'Compra Unit.', 'Compra Total', 'Cotação', 'Valor Convertido'] as $heading)
                            <th style="{{ $th }}">{{ $heading }}</th>
                        @endforeach
                    </tr>
                    @foreach ($group['expenses'] as $line)
                        <tr>
                            <td style="{{ $td }}">{{ $line['name'] }}</td>
                            <td style="{{ $td }}">{{ $line['billing'] }}</td>
                            <td style="{{ $td }}">{{ str_replace('.', ',', (string) round($line['factor'], 4)) }}</td>
                            <td style="{{ $td }} text-align: right;">{{ $money($line['currency'], $line['unit_cost']) }}</td>
                            <td style="{{ $td }} text-align: right;">{{ $money($line['currency'], $line['total_cost']) }}</td>
                            <td style="{{ $td }} text-align: right;">{{ number_format($line['rate'], 4, ',', '.') }}</td>
                            <td style="{{ $td }} text-align: right;">{{ $money('R$', $line['converted']) }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td colspan="6" style="{{ $td }} font-weight: 600;">Total</td>
                        <td style="{{ $td }} text-align: right; font-weight: 600;">{{ $money('R$', $group['total']) }}</td>
                    </tr>
                </table>
            @endforeach
        </div>
    @endforeach
@endforeach
