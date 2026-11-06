@php
    $money = fn ($value) => number_format((float) $value, 2, ',', '.');
    $th = 'padding: 6px 8px; background: #5d5950; color: #fff; font-weight: 600; text-align: left;';
    $td = 'padding: 4px 8px; border-bottom: 1px solid rgba(128, 128, 128, .3);';
    $tables = [
        ['anterior', 'NFS-e com capa em arquivo anterior', true, false],
        ['posterior', 'NFS-e com capa em arquivo posterior', true, true],
        ['nenhum', 'NFS-e sem capa arquivada', false, false],
        ['mesmo_mes', 'NFS-e em outro arquivo do mesmo mês', true, false],
    ];
@endphp

@if ($report !== null)
    @php($summary = $report['resumo'])
    <div style="overflow-x: auto; font-size: .875rem;">
        <table style="width: 100%; margin-bottom: 8px; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="{{ $th }}">Período</th>
                    <th style="{{ $th }}">Arquivo de referência</th>
                    <th style="{{ $th }} text-align: center;">No arquivo</th>
                    <th style="{{ $th }} text-align: center;">Anterior</th>
                    <th style="{{ $th }} text-align: center;">Posterior</th>
                    <th style="{{ $th }} text-align: center;">Sem arquivo</th>
                    <th style="{{ $th }} text-align: center;">Outro no mesmo mês</th>
                    <th style="{{ $th }} text-align: right;">Custo em aberto</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="{{ $td }}">{{ $report['periodo'] }}</td>
                    <td style="{{ $td }}">{{ $report['arquivo_nome'] }}</td>
                    <td style="{{ $td }} text-align: center;">{{ $summary['no_arquivo'] }}</td>
                    <td style="{{ $td }} text-align: center;">{{ $summary['anterior'] }}</td>
                    <td style="{{ $td }} text-align: center;">{{ $summary['posterior'] }}</td>
                    <td style="{{ $td }} text-align: center;">{{ $summary['nenhum'] }}</td>
                    <td style="{{ $td }} text-align: center;">{{ $summary['mesmo_mes'] }}</td>
                    <td style="{{ $td }} text-align: right;">{{ $money($summary['custo_em_aberto']) }}</td>
                </tr>
            </tbody>
        </table>
        <p style="margin: 0 0 12px;">
            O custo em aberto soma as despesas das capas posteriores e desconta só o que já está em ordem de pagamento paga.
            O total não repete a mesma capa.
        </p>

        @foreach ($tables as [$key, $title, $withFile, $withCost])
            @continue($key === 'mesmo_mes' && $report[$key] === [])

            <h4 style="margin: 18px 0 8px; font-weight: 600;">{{ $title }}</h4>

            @if ($report[$key] === [])
                <p>Nenhuma nota neste grupo.</p>
            @else
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th style="{{ $th }}">Data da NF</th>
                            <th style="{{ $th }}">Número da NF</th>
                            <th style="{{ $th }} text-align: right;">Valor da NF</th>
                            <th style="{{ $th }}">Reserva</th>
                            @if ($withFile)
                                <th style="{{ $th }}">Arquivo da capa</th>
                            @endif
                            @if ($withCost)
                                <th style="{{ $th }} text-align: right;">Custo em aberto</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report[$key] as $row)
                            <tr>
                                <td style="{{ $td }}">{{ $row['data_nf'] }}</td>
                                <td style="{{ $td }}">{{ $row['numero_nf'] }}</td>
                                <td style="{{ $td }} text-align: right;">{{ $money($row['valor_nf']) }}</td>
                                <td style="{{ $td }}">{{ $row['booking'] }}</td>
                                @if ($withFile)
                                    <td style="{{ $td }}">{{ $row['arquivo_nome'] }}</td>
                                @endif
                                @if ($withCost)
                                    <td style="{{ $td }} text-align: right;">{{ $money($row['custo_em_aberto']) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($withCost)
                        <tfoot>
                            <tr>
                                <th colspan="5" style="{{ $th }}">Total em aberto (sem repetir capa)</th>
                                <th style="{{ $th }} text-align: right;">{{ $money($summary['custo_em_aberto']) }}</th>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            @endif
        @endforeach
    </div>
@endif
