{{-- E-mail de rastreamento ao cliente: mesmo quadro do TransbordaEmail::setCorpoTransborda legado. --}}
<table cellspacing="1" bgcolor="#FFFFFF" style="font-family: Verdana">
    <tr><td colspan="2"><img src="{{ $logo }}" width="80" height="33" alt="DMF"><br></td></tr>
    <tr><th colspan="2" align="left"><font face="Verdana" size="2" color="red">{{ $kind }}</font></th></tr>
    @foreach ($rows as [$label, $value])
        <tr>
            <th align="left"><font face="Verdana" size="2" color="black">{{ $label }}</font></th>
            <td><font face="Verdana" size="2">{!! nl2br(e($value)) !!}</font></td>
        </tr>
    @endforeach
    @if ($goods->isNotEmpty())
        <tr>
            <th align="left"><font face="Verdana" size="2" color="black">Merchandise</font></th>
            <th><font face="Verdana" size="2" color="black">Equipment</font></th>
        </tr>
        @foreach ($goods as $item)
            <tr>
                <td><font face="Verdana" size="2">{{ $item->mercadoria }}</font></td>
                <td align="center"><font face="Verdana" size="2">{{ $item->quantidade }}x{{ $item->equipamento }}</font></td>
            </tr>
        @endforeach
    @endif
</table>
<hr>
<table>
    <tr><th colspan="2" align="left"><font face="Verdana" size="2" color="black">Eventos/Events</font><br><hr></th></tr>
    @foreach ($history as $event)
        <tr valign="top">
            <td align="left"><font face="Verdana" size="3"><strong>{{ $event['date'] }}</strong></font></td>
            <td><font face="Verdana" size="3">{!! $event['text'] !!}</font></td>
        </tr>
        <tr><td colspan="2"><hr></td></tr>
    @endforeach
</table>
<table>
    @if ($import)
        <tr><td colspan="2"><h4>*Caso a informação de free time demurrage desta mensagem não esteja de acordo com a informada em nossa cotação, será considerada, como correta, a quantidade de dias (free time demurrage) informada na cotação.</h4></td></tr>
    @endif
    <tr><td colspan="2"><font face="Verdana" size="1"><hr><i>DA AVARIA GROSSA: Tem-se por Avaria Grossa todos os danos ou despesas extraordinárias decorrentes de um ato
        intencional (decorrente de uma decisão do capitão do navio), efetuado para a segurança do navio e suas cargas,
        em uma situação de perigo real e iminente, com o intuito de evitar um mal maior a expedição marítima.
        Sendo declarada a Avaria Grossa pelas autoridades competentes, todas as despesas geradas com o salvamento do navio
        e cargas serão rateadas proporcionalmente entre os proprietários das cargas embarcadas e do navio, conforme
        regulamentado pelo Código Comercial Brasileiro e pelas Regras de York-Antuérpia.<br>
        Ficam cientes os embarcadores que não contratarem seguro, caso reconhecida a Avaria Grossa,
        terão que arcar com os valores definidos no rateio das despesas, além da possibilidade também da perda ou
        retenção de suas mercadorias. A recomendação é sempre embarcar com seguro de transporte internacional,
        afinal o seguro é um grande investimento para proteção dos interesses dos embarcadores a um custo
        insignificante diante dos valores das cargas.</i></font></td></tr>
</table>
