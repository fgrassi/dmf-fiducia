{{-- Instrução de Cobrança de seguro: mesmo HTML do Cobranca::geraPdfSeguro legado. --}}
<table width="960px" border="0">
    <tr bgcolor="#7e0001"><td>&nbsp;</td></tr>
    <tr>
        @if ($exception)
            <td>A reserva {{ $booking }} é de um cliente que possui taxa negociada e precisa ser recalculada manualmente.</td>
        @else
            <td>
                <font face="Arial"><strong>Prezado Cliente</strong><br/>
                    <br/> Gentileza notar em anexo o arquivo com os valores a serem pagos referentes ao SEGURO <strong>{{ $booking }}</strong>, Invoice <strong>{{ $invoice }}</strong>
                    <ul>
                        <li>Caso o pagamento não seja efetuado no dia, gentileza confirmar a taxa na data do pagamento.</li>
                        <li>Gentileza enviar comprovante de depósito para que possamos identificar o pagamento.</li>
                        <li style="color:red">A TAXA DE CÂMBIO PODERÁ SOFRER ALTERAÇÕES DURANTE O DIA</li>
                    </ul>
                    <br/><br/><br/>Atenciosamente,<br/> Equipe da DMF Logistics <br/><br/>
                </font>
            </td>
        @endif
    </tr>
    <tr><td><strong>Contatos do Setor Financeiro</strong></td></tr>
    <tr>
        <td>
            <table width="100%">
                <tr><td>Sr.a Márcia Ziebell</td></tr>
                <tr><td>Financial and Administrative Manager</td></tr>
                <tr><td>marcia@dmflog.com.br</td></tr>
                <tr><td>+55 51 3717-8204</td></tr>
            </table>
        </td>
    </tr>
</table>
