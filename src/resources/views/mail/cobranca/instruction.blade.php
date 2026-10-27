{{-- Instrução de Cobrança: mesmo HTML do Cobranca::geraPdf legado (e-mail único com as seções aplicáveis). --}}
<table width="960px" border="0">
    <tr bgcolor="#7e0001"><td>&nbsp;</td></tr>
    @if ($sections['mode'] !== 'exception')
        <tr><td><font face="Arial"><strong>Prezado Cliente,</strong></font></td></tr>
    @endif
    <tr><td><font face="Arial" color="black"><h3>Instrução de Cobrança</h3></font></td></tr>
    @include('mail.cobranca.partials.instruction-sections')
    @if ($sections['mode'] !== 'exception')
        <tr><td>&nbsp;</td></tr>
        <tr><td><font face="Arial">Atenciosamente,<br/>Departamento Financeiro</font></td></tr>
        <tr><td>&nbsp;</td></tr>
    @endif
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
    <tr bgcolor="#7e0001"><td><font face="Arial" color="white"><center>DMF Logistics do Brasil - www.dmflog.com.br - +55 51 3717-8200 </center></font></td></tr>
</table>
