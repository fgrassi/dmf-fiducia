{{-- Envio dos recibos de pagamento (mesmo HTML do Cobranca::enviarEmailRecibos legado). --}}
<table width="960px" border="0">
    <tr bgcolor="#7e0001"><td></td></tr>
    <tr>
        <td><font face="Arial" color="black"><h3>Recibo{{ $plural ? 's' : '' }} de Pagamento </h3></font></td>
    </tr>
    <tr>
        <td>
            <font face="Arial"><strong>Cliente</strong><br/>
                @if ($plural)
                    <br/> Em anexo você encontrará os Recibos Originais de Pagamento gerados através de nosso sistema.<br/>
                @else
                    <br/> Em anexo você encontrará o Recibo Original de Pagamento gerado através de nosso sistema.<br/>
                @endif
                @if ($nfseLater)
                    <br/>A NFS-e correspondente será enviada em e-mail separado, assim que autorizada.<br/>
                @endif
                <br/> <br/><br/>Atenciosamente,<br/> Equipe da DMF Logistics <br/> <br/>
            </font>
        </td>
    </tr>
</table>
