@php
    $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
    $date = fn ($value) => filled($value) && ($time = strtotime($value)) ? date('d/m/Y', $time) : '';
    $th = 'padding: 6px 8px; background: #5d5950; color: #fff; font-weight: 600; text-align: left;';
    $td = 'padding: 4px 8px; border-bottom: 1px solid rgba(128, 128, 128, .3);';
@endphp

@if ($report !== null)
    @php($totals = $report['totais'])
    <div style="overflow-x: auto; font-size: .875rem;">
        <h3 style="margin: 0 0 4px; font-weight: 600;">Extrato de Conta - {{ $report['cliente_nome'] }}</h3>
        <p style="margin: 0 0 12px;">
            CNPJ: {{ $report['cliente_cnpj'] ?: '-' }} |
            Emissão: {{ $date($report['data_ini']) }} a {{ $date($report['data_fim']) }} |
            Gerado em {{ now()->format('d/m/Y H:i') }} | Valores em BRL
        </p>

        <table style="width: 100%; margin-bottom: 24px; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="{{ $th }} text-align: center;">Invoices no período</th>
                    <th style="{{ $th }} text-align: center;">Payments no período</th>
                    <th style="{{ $th }} text-align: right;">Total faturado</th>
                    <th style="{{ $th }} text-align: right;">Total recebido</th>
                    <th style="{{ $th }} text-align: right;">Total em aberto (statement)</th>
                    <th style="{{ $th }} text-align: right;">Total pago a maior</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="{{ $td }} text-align: center;">{{ $totals['invoices'] }}</td>
                    <td style="{{ $td }} text-align: center;">{{ $totals['payments'] }}</td>
                    <td style="{{ $td }} text-align: right;">{{ $money($totals['faturado']) }}</td>
                    <td style="{{ $td }} text-align: right;">{{ $money($totals['recebido']) }}</td>
                    <td style="{{ $td }} text-align: right;">{{ $money($totals['em_aberto']) }}</td>
                    <td style="{{ $td }} text-align: right;">{{ $money($totals['pago_a_maior']) }}</td>
                </tr>
            </tbody>
        </table>

        <h4 style="margin: 0 0 8px; font-weight: 600;">Statement - itens em aberto até {{ $date($report['data_fim']) }}</h4>

        @if ($report['statement'] === [])
            <p>Nenhum item em aberto para este cliente.</p>
        @else
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="{{ $th }}">Invoice Ref</th>
                        <th style="{{ $th }}">NF-e</th>
                        <th style="{{ $th }} text-align: center;">Emissão</th>
                        <th style="{{ $th }} text-align: center;">Vencimento</th>
                        <th style="{{ $th }}">Booking</th>
                        <th style="{{ $th }}">BL</th>
                        <th style="{{ $th }} text-align: right;">Total</th>
                        <th style="{{ $th }} text-align: right;">Recebido</th>
                        <th style="{{ $th }} text-align: right;">Saldo Aberto</th>
                        <th style="{{ $th }} text-align: right;">Pago a Maior</th>
                        <th style="{{ $th }}">Status</th>
                        <th style="{{ $th }} text-align: right;">Dias Vencido</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['statement'] as $row)
                        <tr>
                            <td style="{{ $td }}">{{ $row['id'] }}</td>
                            <td style="{{ $td }}">{{ $row['num_nota_fiscal'] }}</td>
                            <td style="{{ $td }} text-align: center;">{{ $date($row['created_at']) }}</td>
                            <td style="{{ $td }} text-align: center;">{{ $date($row['due_date']) }}</td>
                            <td style="{{ $td }}">{{ $row['booking_ref'] }}</td>
                            <td style="{{ $td }}">{{ $row['bl_ref'] }}</td>
                            <td style="{{ $td }} text-align: right;">{{ $money($row['total_value']) }}</td>
                            <td style="{{ $td }} text-align: right;">{{ $money($row['received_value']) }}</td>
                            <td style="{{ $td }} text-align: right;">{{ $money($row['balance_open']) }}</td>
                            <td style="{{ $td }} text-align: right;">{{ $money($row['overpayment']) }}</td>
                            <td style="{{ $td }}">{{ $row['match_status'] }}</td>
                            <td style="{{ $td }} text-align: right;">{{ $row['days_overdue'] }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <th colspan="6" style="{{ $th }} text-align: right;">TOTAL</th>
                        <th style="{{ $th }} text-align: right;">{{ $money(array_sum(array_column($report['statement'], 'total_value'))) }}</th>
                        <th style="{{ $th }} text-align: right;">{{ $money(array_sum(array_column($report['statement'], 'received_value'))) }}</th>
                        <th style="{{ $th }} text-align: right;">{{ $money($totals['em_aberto']) }}</th>
                        <th style="{{ $th }} text-align: right;">{{ $money($totals['pago_a_maior']) }}</th>
                        <th colspan="2" style="{{ $th }}"></th>
                    </tr>
                </tbody>
            </table>
        @endif

        <p style="margin-top: 12px; font-size: .75rem;">
            Invoices e Payments completos (incl. itens quitados; cancelados não são retidos) estão no Excel. Payments inclui recibo
            bancário, baixa por boleto e baixa sem recibo. Cliente = cliente comercial da reserva. Não operamos notas de crédito ao cliente.
        </p>
    </div>
@endif
