{{-- Aviso de demurrage/detention da reserva: mesmo conteúdo do e-mail do executeSaveDemurrage / executeSaveDetention legado. --}}
<table width="960px" border="0">
    <tr bgcolor="#7e0001"><td>&nbsp;</td></tr>
    <tr><td><font face="Arial" color="black"><h3>{{ $title }}</h3></font></td></tr>
    <tr>
        <td>
            {{ $intro }}
            <p>Cliente: {{ $client }}</p>
            <p>Armador: {{ $carrier }}</p>
            <table cellspacing="0" cellpadding="10" border="0">
                <tr style="background-color:#eceef1">
                    <td>
                        @if ($paysCarrierValues)
                            <p><font face="Verdana" size="3" color="#c33337"><strong><u>Este cliente somente aceita pagar os valores cobrados pelo Armador!</u></strong></font></p>
                            <p><font face="Verdana" size="3" color="#c33337"><strong>Enviar copia da fatura do armador, junto com a cobranca</strong></font></p>
                            <p><font face="Verdana" size="3" color="#c33337"><strong>{!! nl2br(e($clientNote)) !!}</strong></font></p>
                        @endif
                    </td>
                </tr>
            </table>
            <br/>
            <table border="1">
                <tr>
                    <th>Container</th>
                    <th>Tipo Equip.</th>
                    <th>{{ $label }}</th>
                    <th>Data Retirada</th>
                    <th>Data Devolucão</th>
                    <th>Free Time</th>
                </tr>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        </td>
    </tr>
    <tr bgcolor="#7e0001"><td><font face="Arial" color="white"><center>DMF Logistics do Brasil - www.dmflog.com.br - +55 51 3717-8200</center></font></td></tr>
</table>
