{{-- Aviso ao financeiro de exclusão de despesas da IC (mesmo conteúdo do Cobranca::excluirItensCobranca legado). --}}
<p>
    Usuário <strong>{{ $user }}</strong> excluiu despesa(s) da instrução de cobrança <strong>#{{ $charge->id }}</strong>
    @if ($booking !== '')
        (reserva <strong>{{ $booking }}</strong>)
    @endif
    em {{ now()->format('d/m/Y H:i') }}.
</p>
<p><strong>Justificativa:</strong> {{ $justification }}</p>
<table style="border-collapse:collapse;margin:12px 0;" cellpadding="0" cellspacing="0">
    <thead>
        <tr>
            <th style="padding:6px 8px;border:1px solid #ddd;background:#f5f5f5;text-align:left;">Despesa</th>
            <th style="padding:6px 8px;border:1px solid #ddd;background:#f5f5f5;text-align:right;">Valor (R$)</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($removed as $item)
            <tr>
                <td style="padding:4px 8px;border:1px solid #ddd;">{{ $item['label'] }}</td>
                <td style="padding:4px 8px;border:1px solid #ddd;text-align:right;">R$ {{ number_format($item['value'], 2, ',', '.') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<p>
    <strong>Total anterior:</strong> R$ {{ $previousTotal }}<br />
    <strong>Total atual:</strong> R$ {{ $total }}<br />
    <strong>IOF atual:</strong> R$ {{ $iof }}
</p>
<p><strong>Histórico registrado na IC:</strong><br />{!! nl2br(e($history)) !!}</p>
@if ($issuedNfse)
    <p style="color:#a94442;">
        <strong>Atenção:</strong> esta IC possui NFS-e autorizada. Recalcule a cobrança e atualize a nota com o valor atual
        (substituição ou cancelamento, conforme permitido — independente do valor da nota).
    </p>
@endif
